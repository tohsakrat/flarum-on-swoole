<?php
/**
 * Flarum Swoole Coroutine Worker (协程版)
 *
 * 基于 Swoole 的 Flarum 常驻内存入口
 * 开启了一键协程化，并使用 Context Proxy 解决了单例污染问题。
 */
declare(strict_types=1);

require_once __DIR__ . '/Config.php';


// ============================================================
// 启动检查与一键协程化
// ============================================================
if (!extension_loaded('swoole')) {
    fwrite(STDERR, "[ERROR] Swoole 扩展未安装。请执行: pecl install swoole\n");
    exit(1);
}

// 核心：开启一键协程化 Hook（拦截 PDO, Redis, cURL, 文件 IO 等）
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$composerLoader = require WORKER_BASE_DIR . '/vendor/autoload.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/AutoBatchingDataLoader.php';
require_once __DIR__ . '/Formatter.php';
require_once __DIR__ . '/HttpUtils.php';

$isDebugBoot = defined('LOG_LEVEL') && LOG_LEVEL === 'debug';

$workerPluginPatches = [];

function workerRegisterPluginPatch($composerLoader, string $key, string $probe, string $file, string $class): void
{
    global $workerPluginPatches;

    $workerPluginPatches[$key] = [
        'probe' => $probe,
        'file' => $file,
        'class' => $class,
        'loaded' => false,
    ];

    if (!$composerLoader->findFile($probe)) {
        echo "[Boot] 插件补丁跳过：{$key}（插件命名空间不存在：{$probe}）。\n";
        return;
    }

    if (!is_file($file)) {
        echo "[Boot] 插件补丁失败：{$key}（补丁文件不存在：{$file}）。\n";
        return;
    }

    require_once $file;

    $loaded = class_exists($class, false);
    $workerPluginPatches[$key]['loaded'] = $loaded;

    echo $loaded
        ? "[Boot] 插件补丁加载成功：{$key}。\n"
        : "[Boot] 插件补丁失败：{$key}（补丁类不存在：{$class}）。\n";
}

// 注释掉对应一行即可禁用某个插件补丁。
workerRegisterPluginPatch($composerLoader, 'fof-reactions', 'FoF\\Reactions\\Reaction', __DIR__ . '/PluginPatches/FoFReactionsWorkerPatch.php', 'FoFReactionsWorkerPatch');
workerRegisterPluginPatch($composerLoader, 'ianm-follow-users', 'IanM\\FollowUsers\\FollowState', __DIR__ . '/PluginPatches/IanMFollowUsersWorkerPatch.php', 'IanMFollowUsersWorkerPatch');
workerRegisterPluginPatch($composerLoader, 'fof-badges', 'FoF\\Badges\\UserBadge', __DIR__ . '/PluginPatches/FoFBadgesWorkerPatch.php', 'FoFBadgesWorkerPatch');
workerRegisterPluginPatch($composerLoader, 'fof-ignore-users', 'FoF\\IgnoreUsers\\Listener\\SaveIgnoredToDatabase', __DIR__ . '/PluginPatches/FoFIgnoreUsersWorkerPatch.php', 'FoFIgnoreUsersWorkerPatch');
workerRegisterPluginPatch($composerLoader, 'flarum-mentions', 'Flarum\\Mentions\\Formatter\\FormatPostMentions', __DIR__ . '/PluginPatches/FlarumMentionsWorkerPatch.php', 'FlarumMentionsWorkerPatch');

function workerPluginPatchLoaded(string $key): bool
{
    global $workerPluginPatches;

    return !empty($workerPluginPatches[$key]['loaded']);
}

if (!function_exists('swoole_fast_json_decode')) {
    function swoole_fast_json_decode($json, ?bool $associative = null, int $depth = 512, int $flags = 0): mixed
    {
        $json = (string) $json;

        if ($flags === 0 && function_exists('simdjson_decode')) {
            try {
                if ($associative === null) {
                    return simdjson_decode($json, false, $depth);
                }

                return simdjson_decode($json, $associative, $depth);
            } catch (\Throwable $e) {
                // Preserve json_decode()'s default null-on-invalid behavior.
            }
        }

        if ($associative === null) {
            return json_decode($json, null, $depth, $flags);
        }

        return json_decode($json, $associative, $depth, $flags);
    }
}

// Flarum 1.x custom relation safety net:
// if a collection-style custom relation is marked loaded with a null value,
// clear that broken cache entry so the normal lazy-load path can run.
$abstractModelFile = $composerLoader->findFile('Flarum\Database\AbstractModel');
if ($abstractModelFile && file_exists($abstractModelFile) && !class_exists('Flarum\Database\AbstractModel', false)) {
    $src = file_get_contents($abstractModelFile);
    $needle = implode("\n", [
        '    public function getAttribute($key)',
        '    {',
        '        if (! is_null($value = parent::getAttribute($key))) {',
        '            return $value;',
        '        }',
        '',
        '        // If a custom relation with this key has been set up, then we will load',
        '        // and return results from the query and hydrate the relationship\'s',
        '        // value on the "relationships" array.',
        '        if (! $this->relationLoaded($key) && ($relation = $this->getCustomRelation($key))) {',
        '            if (! $relation instanceof Relation) {',
        '                throw new LogicException(',
        '                    \'Relationship method must return an object of type \'.Relation::class',
        '                );',
        '            }',
        '',
        '            return $this->relations[$key] = $relation->getResults();',
        '        }',
        '    }',
    ]);

    $replacement = implode("\n", [
        '    public function getAttribute($key)',
        '    {',
        '        if (! is_null($value = parent::getAttribute($key))) {',
        '            return $value;',
        '        }',
        '',
        '        // If a custom relation with this key has been set up, then we will load',
        '        // and return results from the query and hydrate the relationship\'s',
        '        // value on the "relationships" array.',
        '        $relation = $this->getCustomRelation($key);',
        '',
        '        if ($relation) {',
        '            if (! $relation instanceof Relation) {',
        '                throw new LogicException(',
        '                    \'Relationship method must return an object of type \'.Relation::class',
        '                );',
        '            }',
        '',
        '            if ($this->relationLoaded($key) && ($this->relations[$key] ?? null) === null) {',
        '                $expectsCollection = $relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany',
        '                    || $relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany',
        '                    || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphMany',
        '                    || $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphToMany;',
        '',
        '                if ($expectsCollection) {',
        '                    unset($this->relations[$key]);',
        '                }',
        '            }',
        '',
        '            if (! $this->relationLoaded($key)) {',
        '                return $this->relations[$key] = $relation->getResults();',
        '            }',
        '        }',
        '    }',
    ]);

    $patched = str_replace($needle, $replacement, $src);
    if ($patched !== $src) {
        $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
        try {
            eval($code);
            if ($isDebugBoot) {
                echo "[Boot] Flarum custom relation null-cache lazy-load 补丁注入成功。\n";
            }
        } catch (\Throwable $e) {
            echo "[Boot] ⚠️ Flarum custom relation null-cache lazy-load 补丁注入失败: " . $e->getMessage() . "\n";
        }
    } elseif ($isDebugBoot) {
        echo "[Boot] ⚠️ Flarum custom relation null-cache lazy-load 补丁未匹配。\n";
    }
}

class CoroutineSerializerConfig {
    public static array $whitelist = [];
    public static array $blacklist = [];
    private static array $resourceTimingBuckets = [];
    private static array $postSerializerBuckets = [];
    private static array $tagResourceCache = [];
    private static array $middlewareTimingBuckets = [];
    private static array $beforeSerializationBuckets = [];

    private static function getResourceSerializer($resource): ?object {
        if (!is_object($resource)) {
            return null;
        }

        try {
            $get = \Closure::bind(function($r) {
                return isset($r->serializer) && is_object($r->serializer) ? $r->serializer : null;
            }, null, $resource);

            return $get($resource);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getResourceSerializerForPatch($resource): ?object {
        return self::getResourceSerializer($resource);
    }

    private static function requestPathFromSerializer($serializer): string {
        if (!$serializer || !method_exists($serializer, 'getRequest')) {
            return '';
        }

        $request = $serializer->getRequest();
        if (!$request || !method_exists($request, 'getServerParams')) {
            return '';
        }

        return parse_url(
            (string) ($request->getServerParams()['REQUEST_URI'] ?? ''),
            PHP_URL_PATH
        ) ?: '';
    }

    public static function requestPathFromSerializerForPatch($serializer): string {
        return self::requestPathFromSerializer($serializer);
    }

    private static function isDiscussionOrPostApiPath(string $path): bool {
        return (bool) preg_match('#^/api/(discussions|posts)(?:/[^/]+)?$#', $path);
    }

    private static function isStandaloneDiscussionOrPostApiRequest($serializer): bool {
        if (\Swoole\Coroutine::getCid() > 0) {
            try {
                $ctx = \Swoole\Coroutine::getContext();
                $rootRequestId = $ctx["root_request_id"] ?? null;
                $routeRequestId = $ctx["route_profile_request_id"] ?? null;
                $requestId = $ctx["request_id"] ?? null;
                if ($rootRequestId !== null && $routeRequestId !== null && $routeRequestId !== $rootRequestId) {
                    return false;
                }
                if ($rootRequestId !== null && $requestId !== null && $requestId !== $rootRequestId) {
                    return false;
                }
            } catch (\Throwable $e) {}
        }

        return self::isDiscussionOrPostApiPath(self::requestPathFromSerializer($serializer));
    }

    private static function isDiscussionOrPostDocumentContext($serializer): bool {
        if (\Swoole\Coroutine::getCid() > 0) {
            try {
                $ctx = \Swoole\Coroutine::getContext();
                $mainType = (string) ($ctx["document_main_type"] ?? '');
                if (str_contains($mainType, 'Flarum\\Api\\Serializer\\DiscussionSerializer')
                    || str_contains($mainType, 'Flarum\\Api\\Serializer\\PostSerializer')
                    || str_contains($mainType, 'Flarum\\Api\\Serializer\\BasicPostSerializer')) {
                    return true;
                }
            } catch (\Throwable $e) {}
        }

        return self::isDiscussionOrPostApiPath(self::requestPathFromSerializer($serializer));
    }

    public static function isDiscussionOrPostDocumentContextForPatch($serializer): bool {
        return self::isDiscussionOrPostDocumentContext($serializer);
    }

    public static function shouldSkipStandaloneIncludedTag($resource): bool {
        $serializer = self::getResourceSerializer($resource);

        if (!$serializer || !str_contains(get_class($serializer), 'Flarum\\Tags\\Api\\Serializer\\TagSerializer')) {
            return false;
        }

        return self::isStandaloneDiscussionOrPostApiRequest($serializer);
    }

    public static function shouldSkipStandaloneIncludedResource($resource): bool {
        return self::shouldSkipStandaloneIncludedTag($resource)
            || (\workerPluginPatchLoaded("fof-badges") && \FoFBadgesWorkerPatch::shouldSkipStandaloneIncludedResource($resource));
    }

    public static function prepareUserExtensionRelationsForResources(array $resources): void {
        if (\workerPluginPatchLoaded("fof-badges")) {
            \FoFBadgesWorkerPatch::prepareUserRelationsForResources($resources);
        }
        if (\workerPluginPatchLoaded("ianm-follow-users")) {
            \IanMFollowUsersWorkerPatch::prepareForResources($resources);
        }
        if (\workerPluginPatchLoaded("fof-ignore-users")) {
            \FoFIgnoreUsersWorkerPatch::prepareForResources($resources);
        }
    }

    private static function collectUserResources(array $resources, bool $userSerializerOnly = false): array {
        $users = [];
        foreach ($resources as $resource) {
            $serializer = self::getResourceSerializer($resource);
            if (!$serializer) {
                continue;
            }

            $serializerClass = get_class($serializer);
            $isUserSerializer = str_contains($serializerClass, 'Flarum\\Api\\Serializer\\UserSerializer');
            $isBasicUserSerializer = str_contains($serializerClass, 'Flarum\\Api\\Serializer\\BasicUserSerializer');
            if (!$isUserSerializer && (!$isBasicUserSerializer || $userSerializerOnly)) {
                continue;
            }

            $user = method_exists($resource, 'getData') ? $resource->getData() : null;
            if (is_object($user) && str_contains(get_class($user), 'Flarum\\User\\User') && isset($user->id)) {
                $users[(int) $user->id] = $user;
            }
        }

        return $users;
    }

    public static function collectUserResourcesForPatch(array $resources, bool $userSerializerOnly = false): array {
        return self::collectUserResources($resources, $userSerializerOnly);
    }

    private static function tagResourceCacheKey($resource, string $cacheKey): ?string {
        if (!str_contains($cacheKey, 'Flarum\\Tags\\Api\\Serializer\\TagSerializer')) {
            return null;
        }
        if (!method_exists($resource, 'getData')) {
            return null;
        }

        $tag = $resource->getData();
        if (!is_object($tag) || !isset($tag->id)) {
            return null;
        }

        $serializer = self::getResourceSerializer($resource);
        if ($serializer && method_exists($serializer, 'getRequest') && $serializer->getRequest()) {
            $request = $serializer->getRequest();
            if (method_exists($request, 'getQueryParams')) {
                $params = $request->getQueryParams();
                if (isset($params['fields']['tags'])) {
                    return null;
                }
            }
        }

        $updatedAt = '';
        if (isset($tag->updated_at) && is_object($tag->updated_at) && method_exists($tag->updated_at, 'getTimestamp')) {
            $updatedAt = (string) $tag->updated_at->getTimestamp();
        }

        return implode('|', [
            'tag',
            (string) $tag->id,
            $updatedAt,
            is_object($serializer) ? get_class($serializer) : 'unknown',
            $serializer && method_exists($serializer, 'getActor') ? ($serializer->getActor()->id ?? 'guest') : 'guest',
        ]);
    }

    private static function stripDynamicTagResource(array $resource): array {
        // [修复] 这里之前移除了权限字段导致无法发帖。不移除这些重要字段
        // unset($resource['attributes']['canStartDiscussion']);
        // unset($resource['attributes']['canAddToDiscussion']);
        // unset($resource['attributes']['isRestricted']);
        // unset($resource['attributes']['subscription']);
        return $resource;
    }

    private static function hydrateDynamicTagResource(array $resource, $jsonApiResource): array {
        // [修复] 对应上面的修改，由于不移除了，这里也直接返回，不再重新计算权限
        return $resource;
    }

    public static function ensureUserGroupsReady($user): void {
        if (!is_object($user) || !str_contains(get_class($user), 'Flarum\\User\\User')) {
            return;
        }

        try {
            $needsLoad = true;
            if (method_exists($user, 'relationLoaded') && $user->relationLoaded('groups')) {
                $needsLoad = method_exists($user, 'getRelation') && $user->getRelation('groups') === null;
            }

            if ($needsLoad && method_exists($user, 'loadMissing')) {
                $user->loadMissing('groups');
            }

            if (method_exists($user, 'relationLoaded') && $user->relationLoaded('groups')) {
                if (method_exists($user, 'getRelation') && $user->getRelation('groups') !== null) {
                    return;
                }
            }

            if ($needsLoad && method_exists($user, 'load')) {
                $user->load('groups');
            }

            if (method_exists($user, 'relationLoaded') && method_exists($user, 'getRelation')
                && $user->relationLoaded('groups') && $user->getRelation('groups') === null
                && method_exists($user, 'setRelation')) {
                $user->setRelation('groups', new \Illuminate\Database\Eloquent\Collection());
            }
        } catch (\Throwable $e) {
            // Keep the original request path alive; permission checks will still fail normally if the actor is invalid.
        }
    }

    public static function ensureResourceActorGroupsReady($resource): void {
        $serializer = self::getResourceSerializer($resource);
        if ($serializer && method_exists($serializer, 'getActor')) {
            self::ensureUserGroupsReady($serializer->getActor());
        }
        if (\workerPluginPatchLoaded("fof-ignore-users")) {
            \FoFIgnoreUsersWorkerPatch::ensureResourceActorIgnoredUsersReady($resource);
        }
    }

    public static function resourceToArray($resource, string $cacheKey): array {
        $tagCacheKey = self::tagResourceCacheKey($resource, $cacheKey);
        if ($tagCacheKey !== null && isset(self::$tagResourceCache[$tagCacheKey])) {
            return self::hydrateDynamicTagResource(self::$tagResourceCache[$tagCacheKey], $resource);
        }

        if (\workerPluginPatchLoaded("fof-badges")) {
            $badgeResource = \FoFBadgesWorkerPatch::getCachedResource($resource, $cacheKey);
            if ($badgeResource !== null) {
                return $badgeResource;
            }
        }

        $serializer = self::getResourceSerializer($resource);
        $data = method_exists($resource, 'getData') ? $resource->getData() : null;
        $profilePostSerializer = self::shouldProfilePostSerializer($serializer, $data);
        $profileStart = $profilePostSerializer ? microtime(true) : 0.0;

        try {
            $result = $resource->toArray();
        } finally {
            if ($profilePostSerializer) {
                self::recordPostSerializerPhase("resource.toArray:" . $cacheKey, (microtime(true) - $profileStart) * 1000, $serializer, $data);
            }
        }

        if ($tagCacheKey !== null) {
            self::$tagResourceCache[$tagCacheKey] = self::stripDynamicTagResource($result);
            $limit = defined('TAG_RESOURCE_CACHE_LIMIT') ? TAG_RESOURCE_CACHE_LIMIT : 2048;
            if (count(self::$tagResourceCache) > $limit) {
                array_shift(self::$tagResourceCache);
            }
        }

        if (\workerPluginPatchLoaded("fof-badges")) {
            \FoFBadgesWorkerPatch::rememberResource($resource, $cacheKey, $result);
        }

        return $result;
    }

    public static function recordResourceTime(string $resourceType, float $durationMs): void {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $requestId = $ctx["request_id"] ?? (string)\Swoole\Coroutine::getCid();
        if (!isset(self::$resourceTimingBuckets[$requestId])) {
            self::$resourceTimingBuckets[$requestId] = [];
        }
        if (!isset(self::$resourceTimingBuckets[$requestId][$resourceType])) {
            self::$resourceTimingBuckets[$requestId][$resourceType] = ["time" => 0.0, "count" => 0];
        }
        self::$resourceTimingBuckets[$requestId][$resourceType]["time"] += $durationMs;
        self::$resourceTimingBuckets[$requestId][$resourceType]["count"]++;
    }

    public static function shouldProfilePostSerializer($serializer, $model = null): bool {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || !is_object($serializer)) {
            return false;
        }

        $serializerClass = get_class($serializer);
        if (!str_contains($serializerClass, 'Flarum\\Api\\Serializer\\PostSerializer')
            && !str_contains($serializerClass, 'Flarum\\Api\\Serializer\\BasicPostSerializer')) {
            return false;
        }

        return $model === null || (is_object($model) && str_contains(get_class($model), 'Flarum\\Post\\'));
    }

    public static function shouldProfileUserSerializer($serializer, $model = null): bool {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || !is_object($serializer)) {
            return false;
        }

        $serializerClass = get_class($serializer);
        if (!str_contains($serializerClass, 'Flarum\\Api\\Serializer\\UserSerializer')
            && !str_contains($serializerClass, 'Flarum\\Api\\Serializer\\BasicUserSerializer')) {
            return false;
        }

        return $model === null || (is_object($model) && str_contains(get_class($model), 'Flarum\\User\\'));
    }

    public static function describeCallback($callback): string {
        try {
            if (is_string($callback)) {
                return $callback;
            }

            if (is_array($callback)) {
                $target = $callback[0] ?? null;
                $method = $callback[1] ?? null;
                $targetName = is_object($target) ? get_class($target) : (string) $target;

                return $targetName . '::' . (string) $method;
            }

            if ($callback instanceof \Closure) {
                $ref = new \ReflectionFunction($callback);
                $where = self::shortFileLocation((string) $ref->getFileName(), (int) $ref->getStartLine());
                $statics = $ref->getStaticVariables();
                $wrapped = $statics['callback'] ?? null;

                if ($wrapped !== null && $wrapped !== $callback) {
                    return 'Closure(' . $where . ' wraps ' . self::describeCallback($wrapped) . ')';
                }

                return 'Closure(' . $where . ')';
            }

            if (is_object($callback)) {
                return get_class($callback);
            }
        } catch (\Throwable $e) {
            return 'unknown-callback';
        }

        return gettype($callback);
    }

    public static function shortFileLocation(string $file, int $line): string {
        $file = str_replace('\\', '/', $file);
        foreach (['/dev/', '/extensions/', '/vendor/', '/flarum/'] as $marker) {
            $pos = strpos($file, $marker);
            if ($pos !== false) {
                return substr($file, $pos + 1) . ':' . $line;
            }
        }

        $parts = explode('/', trim($file, '/'));
        $tail = array_slice($parts, -3);

        return implode('/', $tail) . ':' . $line;
    }

    public static function recordPostSerializerPhase(string $phase, float $durationMs, $serializer = null, $model = null): void {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $requestId = $ctx["request_id"] ?? (string)\Swoole\Coroutine::getCid();
        $postId = null;
        $postNumber = null;
        if (is_object($model)) {
            $postId = $model->id ?? null;
            $postNumber = $model->number ?? null;
        }

        $key = $phase;
        if (is_object($serializer)) {
            $key .= '|' . get_class($serializer);
        }

        if (!isset(self::$postSerializerBuckets[$requestId])) {
            self::$postSerializerBuckets[$requestId] = [];
        }

        if (!isset(self::$postSerializerBuckets[$requestId][$key])) {
            self::$postSerializerBuckets[$requestId][$key] = [
                "time" => 0.0,
                "count" => 0,
                "max" => 0.0,
                "sample" => "",
            ];
        }

        $bucket =& self::$postSerializerBuckets[$requestId][$key];
        $bucket["time"] += $durationMs;
        $bucket["count"]++;
        if ($durationMs > $bucket["max"]) {
            $bucket["max"] = $durationMs;
            $bucket["sample"] = "post=" . ($postId ?? "?") . " number=" . ($postNumber ?? "?") . " request=" . $requestId;
        }
    }

    public static function pullPostSerializerProfile(string $requestId): array {
        $profile = self::$postSerializerBuckets[$requestId] ?? [];
        unset(self::$postSerializerBuckets[$requestId]);
        if (\workerPluginPatchLoaded("fof-reactions")) {
            \FoFReactionsWorkerPatch::clearRequestCache($requestId);
        }

        return $profile;
    }

    public static function recordUserSerializerPhase(string $phase, float $durationMs, $serializer = null, $model = null): void {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $requestId = $ctx["request_id"] ?? (string)\Swoole\Coroutine::getCid();
        $userId = null;
        $username = null;
        if (is_object($model)) {
            $userId = $model->id ?? null;
            $username = $model->username ?? null;
        }

        $key = $phase;
        if (is_object($serializer)) {
            $key .= '|' . get_class($serializer);
        }

        if (!isset(self::$postSerializerBuckets[$requestId])) {
            self::$postSerializerBuckets[$requestId] = [];
        }

        if (!isset(self::$postSerializerBuckets[$requestId][$key])) {
            self::$postSerializerBuckets[$requestId][$key] = [
                "time" => 0.0,
                "count" => 0,
                "max" => 0.0,
                "sample" => "",
            ];
        }

        $bucket =& self::$postSerializerBuckets[$requestId][$key];
        $bucket["time"] += $durationMs;
        $bucket["count"]++;
        if ($durationMs > $bucket["max"]) {
            $bucket["max"] = $durationMs;
            $bucket["sample"] = "user=" . ($userId ?? "?") . " username=" . ($username ?? "?") . " request=" . $requestId;
        }
    }

    public static function pullResourceTimes(string $requestId): array {
        $stats = self::$resourceTimingBuckets[$requestId] ?? [];
        unset(self::$resourceTimingBuckets[$requestId]);

        return $stats;
    }

    public static function recordMiddlewareTime(string $name, float $durationMs, ?string $targetRequestId = null): void {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $requestId = $targetRequestId ?: ($ctx["request_id"] ?? (string)\Swoole\Coroutine::getCid());
        if (!isset(self::$middlewareTimingBuckets[$requestId])) {
            self::$middlewareTimingBuckets[$requestId] = [];
        }
        if (!isset(self::$middlewareTimingBuckets[$requestId][$name])) {
            self::$middlewareTimingBuckets[$requestId][$name] = ["time" => 0.0, "count" => 0, "max" => 0.0];
        }

        self::$middlewareTimingBuckets[$requestId][$name]["time"] += $durationMs;
        self::$middlewareTimingBuckets[$requestId][$name]["count"]++;
        self::$middlewareTimingBuckets[$requestId][$name]["max"] = max(
            self::$middlewareTimingBuckets[$requestId][$name]["max"],
            $durationMs
        );
    }

    public static function pullMiddlewareProfile(string $requestId): array {
        $profile = self::$middlewareTimingBuckets[$requestId] ?? [];
        unset(self::$middlewareTimingBuckets[$requestId]);

        return $profile;
    }

    public static function recordBeforeSerializationPhase(string $name, float $durationMs, ?string $targetRequestId = null): void {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $requestId = $targetRequestId ?: ($ctx["route_profile_request_id"] ?? $ctx["root_request_id"] ?? $ctx["request_id"] ?? (string)\Swoole\Coroutine::getCid());
        if (!isset(self::$beforeSerializationBuckets[$requestId])) {
            self::$beforeSerializationBuckets[$requestId] = [];
        }
        if (!isset(self::$beforeSerializationBuckets[$requestId][$name])) {
            self::$beforeSerializationBuckets[$requestId][$name] = ["time" => 0.0, "count" => 0, "max" => 0.0];
        }

        self::$beforeSerializationBuckets[$requestId][$name]["time"] += $durationMs;
        self::$beforeSerializationBuckets[$requestId][$name]["count"]++;
        self::$beforeSerializationBuckets[$requestId][$name]["max"] = max(
            self::$beforeSerializationBuckets[$requestId][$name]["max"],
            $durationMs
        );
    }

    public static function pullBeforeSerializationProfile(string $requestId): array {
        $profile = self::$beforeSerializationBuckets[$requestId] ?? [];
        unset(self::$beforeSerializationBuckets[$requestId]);

        return $profile;
    }

    public static function recordRoutePhase(string $name, float $durationMs): void {
        $targetRequestId = null;
        try {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx) {
                $targetRequestId = $ctx["route_profile_request_id"] ?? $ctx["root_request_id"] ?? null;
            }
        } catch (\Throwable $e) {}

        self::recordMiddlewareTime($name, $durationMs, $targetRequestId);
    }

    public static function recordSerializationTime(float $durationMs): void {
        if (!defined("LOG_LEVEL") || LOG_LEVEL !== "debug" || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $ctx["serialization_ms"] = ($ctx["serialization_ms"] ?? 0.0) + $durationMs;
    }

    public static function getResourceType($resource): string {
        if (!is_object($resource)) return 'scalar';
        try {
            $get = \Closure::bind(function($r) {
                return isset($r->serializer) && is_object($r->serializer) ? get_class($r->serializer) : null;
            }, null, $resource);
            $type = $get($resource);
            if ($type) return $type;
        } catch (\Throwable $e) {}
        
        if (method_exists($resource, 'getData')) {
            $data = $resource->getData();
            if (is_object($data)) return get_class($data);
        }
        return get_class($resource);
    }

    public static function getMainType($data): string {
        if (is_object($data)) {
            if (method_exists($data, 'getResources')) {
                $resources = $data->getResources();
                if (!empty($resources) && is_object($resources[0])) {
                    return self::getResourceType($resources[0]);
                }
            } else {
                return self::getResourceType($data);
            }
        }
        return 'UnknownMain';
    }
}

// 协程并发序列化引擎：并发化 Collection::toArray()（真正的计算瓶颈）
// toArray() 内每个 resource->toArray() 调用 serializer->getAttributes/getRelationships，
// 这才是耗 CPU / 触发 DB 的地方；getResources() 只是返回已有对象，不需要改。
if (COROUTINE_SERIALIZER_ENABLED) {
    if ($isDebugBoot) {
        $abstractSerializerFile = $composerLoader->findFile('Flarum\Api\Serializer\AbstractSerializer');
        if (!$abstractSerializerFile || !file_exists($abstractSerializerFile)) {
            echo "[WARNING] 无法定位 Flarum\\Api\\Serializer\\AbstractSerializer，跳过 PostSerializer 细分打点注入。\n";
        } elseif (class_exists('Flarum\Api\Serializer\AbstractSerializer', false)) {
            echo "[Boot] Flarum\\Api\\Serializer\\AbstractSerializer 已被提前加载，跳过 PostSerializer 细分打点注入。\n";
        } else {
        $src = file_get_contents($abstractSerializerFile);
        $patchMethod = function (string $src, string $methodName, string $newMethod): string {
            if (!preg_match('/public\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
                return $src;
            }

            $sigEnd = $m[0][1] + strlen($m[0][0]);
            $depth = 1;
            $pos = $sigEnd;
            $len = strlen($src);
            while ($pos < $len && $depth > 0) {
                if ($src[$pos] === '{') $depth++;
                elseif ($src[$pos] === '}') $depth--;
                $pos++;
            }
            $lineStart = strrpos(substr($src, 0, $m[0][1]), "\n");

            return substr($src, 0, $lineStart + 1) . $newMethod . "\n" . substr($src, $pos);
        };

        $src = $patchMethod($src, 'getAttributes', '
    public function getAttributes($model, array $fields = null)
    {
        $profilePostSerializer = \CoroutineSerializerConfig::shouldProfilePostSerializer($this, $model);
        $profileUserSerializer = \CoroutineSerializerConfig::shouldProfileUserSerializer($this, $model);
        $profileAnySerializer = $profilePostSerializer || $profileUserSerializer;
        $profileStart = $profileAnySerializer ? microtime(true) : 0.0;

        try {
            if (! is_object($model) && ! is_array($model)) {
                return [];
            }

            $defaultStart = $profileAnySerializer ? microtime(true) : 0.0;
            $attributes = $this->getDefaultAttributes($model);
            if ($profilePostSerializer) {
                \CoroutineSerializerConfig::recordPostSerializerPhase("attributes.default", (microtime(true) - $defaultStart) * 1000, $this, $model);
            }
            if ($profileUserSerializer) {
                \CoroutineSerializerConfig::recordUserSerializerPhase("userAttributes.default", (microtime(true) - $defaultStart) * 1000, $this, $model);
            }

            foreach (array_reverse(array_merge([static::class], class_parents($this))) as $class) {
                if (isset(static::$attributeMutators[$class])) {
                    foreach (static::$attributeMutators[$class] as $mutatorIndex => $callback) {
                        $mutatorStart = $profileAnySerializer ? microtime(true) : 0.0;
                        $attributes = array_merge(
                            $attributes,
                            $callback($this, $model, $attributes)
                        );
                        if ($profilePostSerializer) {
                            \CoroutineSerializerConfig::recordPostSerializerPhase(
                                "attributes.mutator:" . $class . "#" . $mutatorIndex . ":" . \CoroutineSerializerConfig::describeCallback($callback),
                                (microtime(true) - $mutatorStart) * 1000,
                                $this,
                                $model
                            );
                        }
                        if ($profileUserSerializer) {
                            \CoroutineSerializerConfig::recordUserSerializerPhase(
                                "userAttributes.mutator:" . $class . "#" . $mutatorIndex . ":" . \CoroutineSerializerConfig::describeCallback($callback),
                                (microtime(true) - $mutatorStart) * 1000,
                                $this,
                                $model
                            );
                        }
                    }
                }
            }

            return $attributes;
        } finally {
            if ($profilePostSerializer) {
                \CoroutineSerializerConfig::recordPostSerializerPhase("attributes.total", (microtime(true) - $profileStart) * 1000, $this, $model);
            }
            if ($profileUserSerializer) {
                \CoroutineSerializerConfig::recordUserSerializerPhase("userAttributes.total", (microtime(true) - $profileStart) * 1000, $this, $model);
            }
        }
    }');

        $src = $patchMethod($src, 'getRelationship', '
    public function getRelationship($model, $name)
    {
        $profilePostSerializer = \CoroutineSerializerConfig::shouldProfilePostSerializer($this, $model);
        $profileUserSerializer = \CoroutineSerializerConfig::shouldProfileUserSerializer($this, $model);
        $profileAnySerializer = $profilePostSerializer || $profileUserSerializer;
        $profileStart = $profileAnySerializer ? microtime(true) : 0.0;

        try {
            if ($relationship = $this->getCustomRelationship($model, $name)) {
                return $relationship;
            }

            return parent::getRelationship($model, $name);
        } finally {
            if ($profilePostSerializer) {
                \CoroutineSerializerConfig::recordPostSerializerPhase("relationship:" . $name, (microtime(true) - $profileStart) * 1000, $this, $model);
            }
            if ($profileUserSerializer) {
                \CoroutineSerializerConfig::recordUserSerializerPhase("userRelationship:" . $name, (microtime(true) - $profileStart) * 1000, $this, $model);
            }
        }
    }');

        $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
        try {
            eval($code);
            echo "[Boot] PostSerializer 细分打点注入成功（AbstractSerializer attributes/relationships）。\n";
        } catch (\Throwable $e) {
            echo "[Boot] ⚠️ PostSerializer 细分打点注入失败: " . $e->getMessage() . "\n";
        }
        }
    }

    $collectionFile = $composerLoader->findFile('Tobscure\JsonApi\Collection');
    if (!$collectionFile || !file_exists($collectionFile)) {
        echo "[WARNING] 无法定位 Tobscure\\JsonApi\\Collection，跳过协程序列化注入。\n";
    } elseif (class_exists('Tobscure\JsonApi\Collection', false)) {
        if ($isDebugBoot) {
            echo "[Boot] Tobscure\\JsonApi\\Collection 已被提前加载，跳过注入。\n";
        }
    } else {
        $src = file_get_contents($collectionFile);

        // 定位并替换 toArray()（协程并行化每个 resource->toArray()）
        if (preg_match('/public\s+function\s+toArray\s*\(\s*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $sigEnd    = $m[0][1] + strlen($m[0][0]);
            $depth     = 1;
            $pos       = $sigEnd;
            $len       = strlen($src);
            while ($pos < $len && $depth > 0) {
                if ($src[$pos] === '{') $depth++;
                elseif ($src[$pos] === '}') $depth--;
                $pos++;
            }
            $lineStart = strrpos(substr($src, 0, $m[0][1]), "\n");

            $newMethod = '
    public function toArray()
    {
        // 协程环境 + 多条目时并行计算每个 resource->toArray()
        if (\Swoole\Coroutine::getCid() > 0 && count($this->resources) > 1) {
            $wg         = new \Swoole\Coroutine\WaitGroup();
            $results    = [];
            $errors     = [];
            $parentCtx  = \Swoole\Coroutine::getContext();
            $viewShared = $parentCtx["view_shared"] ?? [];
            $postHtmlPrefetch = $parentCtx["post_html_prefetch"] ?? [];
            $mainType   = $parentCtx["document_main_type"] ?? "UnknownMain";
            $requestId  = $parentCtx["request_id"] ?? null;
            $routeProfileRequestId = $parentCtx["route_profile_request_id"] ?? $parentCtx["root_request_id"] ?? $requestId;
            if (\workerPluginPatchLoaded("fof-reactions")) {
                \FoFReactionsWorkerPatch::prepareForResources($this->resources);
            }
            if (\workerPluginPatchLoaded("flarum-mentions")) {
                \FlarumMentionsWorkerPatch::prepareForResources($this->resources);
            }
            \CoroutineSerializerConfig::prepareUserExtensionRelationsForResources($this->resources);
            \PostHtmlCache::prefetchForResources($this->resources);
            
                foreach ($this->resources as $idx => $resource) {
                    \CoroutineSerializerConfig::ensureResourceActorGroupsReady($resource);
                    $resType = \CoroutineSerializerConfig::getResourceType($resource);
                    $cacheKey = $mainType . "::" . $resType;
                    $isBlacklisted = isset(\CoroutineSerializerConfig::$blacklist[$cacheKey]);
                    $isWhitelisted = isset(\CoroutineSerializerConfig::$whitelist[$cacheKey]);
                
                if ($isBlacklisted && !$isWhitelisted) {
                    $parentCtx["serializer_key"] = $cacheKey;
                    $parentCtx["query_executed"] = false;
                    try { 
                        $profileStart = microtime(true);
                        $results[$idx] = \CoroutineSerializerConfig::resourceToArray($resource, $cacheKey); 
                        \CoroutineSerializerConfig::recordResourceTime($cacheKey, (microtime(true) - $profileStart) * 1000);
                    } catch (\Throwable $e) {
                        $errors[$idx] = $e;
                    }
                    if (!empty($parentCtx["query_executed"])) {
                        \CoroutineSerializerConfig::$whitelist[$cacheKey] = true;
                    }
                } else {
                    $wg->add();
                    \Swoole\Coroutine\go(function () use ($wg, $resource, $idx, &$results, &$errors, $viewShared, $postHtmlPrefetch, $cacheKey, $isWhitelisted, $requestId, $routeProfileRequestId) {
                        $ctx = \Swoole\Coroutine::getContext();
                        $ctx["view_shared"] = $viewShared;
                        $ctx["post_html_prefetch"] = $postHtmlPrefetch;
                        if ($requestId !== null) {
                            $ctx["request_id"] = $requestId;
                        }
                        if ($routeProfileRequestId !== null) {
                            $ctx["route_profile_request_id"] = $routeProfileRequestId;
                        }
                        $ctx["serializer_key"] = $cacheKey;
                        $ctx["query_executed"] = false;
                        try { 
                            $profileStart = microtime(true);
                            $results[$idx] = \CoroutineSerializerConfig::resourceToArray($resource, $cacheKey); 
                            \CoroutineSerializerConfig::recordResourceTime($cacheKey, (microtime(true) - $profileStart) * 1000);
                        } catch (\Throwable $e) {
                            $errors[$idx] = $e;
                        } finally { 
                            if (!$isWhitelisted && empty($ctx["query_executed"])) {
                                \CoroutineSerializerConfig::$blacklist[$cacheKey] = true;
                            }
                            $wg->done(); 
                        }
                    });
                }
            }
            $wg->wait();
            // 子协程出现异常时，取第一个错误重新抛出（让上层框架统一处理，避免 Swoole 协程外 Fatal Error）
            if (!empty($errors)) {
                throw reset($errors);
            }
            ksort($results);
            return array_values($results);
        }
        // 非协程或单条目时串行降级
        if (\workerPluginPatchLoaded("fof-reactions")) {
            \FoFReactionsWorkerPatch::prepareForResources($this->resources);
        }
        if (\workerPluginPatchLoaded("flarum-mentions")) {
            \FlarumMentionsWorkerPatch::prepareForResources($this->resources);
        }
        \CoroutineSerializerConfig::prepareUserExtensionRelationsForResources($this->resources);
        \PostHtmlCache::prefetchForResources($this->resources);
        return array_map(function ($resource) {
            \CoroutineSerializerConfig::ensureResourceActorGroupsReady($resource);
            $resourceType = \CoroutineSerializerConfig::getResourceType($resource);
            $profileStart = microtime(true);
            try {
                return \CoroutineSerializerConfig::resourceToArray($resource, $resourceType);
            } finally {
                \CoroutineSerializerConfig::recordResourceTime($resourceType, (microtime(true) - $profileStart) * 1000);
            }
        }, $this->resources);
    }';

            $patched = substr($src, 0, $lineStart + 1) . $newMethod . "\n" . substr($src, $pos);
        } else {
            $patched = $src; // toArray() 不存在则不修改
        }

        $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
        try {
            eval($code);
            if ($isDebugBoot) {
                echo "[Boot] 协程序列化引擎注入成功（toArray 并发版）。\n";
            }
        } catch (\Throwable $e) {
            echo "[Boot] ⚠️ 协程序列化注入失败: " . $e->getMessage() . "\n";
        }
    }

    // 第二波注入：针对单篇帖子详情等（如 /api/discussions/392），
    // 帖子数组是被塞进了 JSON:API 的 `included` 数组里，由 Document::toArray() 线性串行处理的！
    // 我们必须连同 Document 一起并发化，才能解决这最后 300ms 的 CPU 瓶颈！
    $documentFile = $composerLoader->findFile('Tobscure\JsonApi\Document');
    if ($documentFile && file_exists($documentFile) && !class_exists('Tobscure\JsonApi\Document', false)) {
        $src = file_get_contents($documentFile);

        // 我们要拦截 Document::toArray() 里遍历 $included 数组的部分
        if (preg_match('/public\s+function\s+toArray\s*\(\s*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $sigEnd    = $m[0][1] + strlen($m[0][0]);
            $depth     = 1;
            $pos       = $sigEnd;
            $len       = strlen($src);
            while ($pos < $len && $depth > 0) {
                if ($src[$pos] === '{') $depth++;
                elseif ($src[$pos] === '}') $depth--;
                $pos++;
            }
            $lineStart = strrpos(substr($src, 0, $m[0][1]), "\n");

            // 暴力重写整个 toArray()，在处理 included 时加入协程并发
            // 增加 Pre-warm 逻辑，打地鼠式解决共享模型的 lazy-load 竞态问题
            $newMethod = '
    public function toArray()
    {
        $document = [];

        if (! empty($this->links)) {
            $document["links"] = $this->links;
        }

        if (! empty($this->data)) {
            $mainType = \CoroutineSerializerConfig::getMainType($this->data);
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx) {
                $prevMainType = $ctx["document_main_type"] ?? "UnknownMain";
                $ctx["document_main_type"] = $mainType;
            }

            $serializationProfileStart = microtime(true);
            try {
                if (\workerPluginPatchLoaded("fof-ignore-users")) {
                    \FoFIgnoreUsersWorkerPatch::ensureResourceActorIgnoredUsersReady($this->data);
                }
                if (\workerPluginPatchLoaded("flarum-mentions")) {
                    \FlarumMentionsWorkerPatch::prepareForResources([$this->data]);
                }
                $document["data"] = $this->data->toArray();

                $resources = $this->getIncluded($this->data);

                if (count($resources)) {
                    if (\workerPluginPatchLoaded("fof-reactions")) {
                        \FoFReactionsWorkerPatch::prepareForResources($resources);
                    }
                    if (\workerPluginPatchLoaded("flarum-mentions")) {
                        \FlarumMentionsWorkerPatch::prepareForResources($resources);
                    }
                    \CoroutineSerializerConfig::prepareUserExtensionRelationsForResources($resources);
                    \PostHtmlCache::prefetchForResources($resources);
                    // 🌟 核心突破口：对 included 资源进行协程并发序列化 🌟
                    if ((defined("COROUTINE_DOCUMENT_INCLUDED_ENABLED") ? COROUTINE_DOCUMENT_INCLUDED_ENABLED : true)
                        && \Swoole\Coroutine::getCid() > 0
                        && count($resources) > 1) {
                        
                        // 【打地鼠】在进入并发前，串行预热共享对象的关联关系，防止在并发时 lazy-load 导致数据竞争
                        foreach ($resources as $resource) {
                            \CoroutineSerializerConfig::ensureResourceActorGroupsReady($resource);
                            $model = $resource->getData();
                            if (is_object($model) && method_exists($model, "getAttribute")) {
                                // 帖子所属的讨论
                                $discussion = $model->getAttribute("discussion");
                                if (is_object($discussion) && method_exists($discussion, "getAttribute")) {
                                    // 预热 discussion->tags
                                    $discussion->getAttribute("tags");
                                }
                            }
                        }

                        $wg = new \Swoole\Coroutine\WaitGroup();
                        $results = [];
                        $coErrors = [];
                        $parentCtx = \Swoole\Coroutine::getContext();
                        $viewShared = $parentCtx["view_shared"] ?? [];
                        $postHtmlPrefetch = $parentCtx["post_html_prefetch"] ?? [];
                        $requestId = $parentCtx["request_id"] ?? null;
                        $routeProfileRequestId = $parentCtx["route_profile_request_id"] ?? $parentCtx["root_request_id"] ?? $requestId;
                        
                        foreach ($resources as $idx => $resource) {
                            if (\CoroutineSerializerConfig::shouldSkipStandaloneIncludedResource($resource)) {
                                continue;
                            }

                            \CoroutineSerializerConfig::ensureResourceActorGroupsReady($resource);
                            $resType = \CoroutineSerializerConfig::getResourceType($resource);
                            $cacheKey = $mainType . "::" . $resType;
                            $isBlacklisted = isset(\CoroutineSerializerConfig::$blacklist[$cacheKey]);
                            $isWhitelisted = isset(\CoroutineSerializerConfig::$whitelist[$cacheKey]);
                            
                            if ($isBlacklisted && !$isWhitelisted) {
                                $parentCtx["serializer_key"] = $cacheKey;
                                $parentCtx["query_executed"] = false;
                                try {
                                    $profileStart = microtime(true);
                                    $results[$idx] = \CoroutineSerializerConfig::resourceToArray($resource, $cacheKey);
                                    \CoroutineSerializerConfig::recordResourceTime($cacheKey, (microtime(true) - $profileStart) * 1000);
                                } catch (\Throwable $e) {
                                    $coErrors[$idx] = $e;
                                }
                                if (!empty($parentCtx["query_executed"])) {
                                    \CoroutineSerializerConfig::$whitelist[$cacheKey] = true;
                                }
                            } else {
                                $wg->add();
                                \Swoole\Coroutine\go(function () use ($wg, $resource, $idx, &$results, &$coErrors, $viewShared, $postHtmlPrefetch, $cacheKey, $isWhitelisted, $requestId, $routeProfileRequestId) {
                                    $subCtx = \Swoole\Coroutine::getContext();
                                    $subCtx["view_shared"] = $viewShared;
                                    $subCtx["post_html_prefetch"] = $postHtmlPrefetch;
                                    if ($requestId !== null) {
                                        $subCtx["request_id"] = $requestId;
                                    }
                                    if ($routeProfileRequestId !== null) {
                                        $subCtx["route_profile_request_id"] = $routeProfileRequestId;
                                    }
                                    $subCtx["serializer_key"] = $cacheKey;
                                    $subCtx["query_executed"] = false;
                                    try {
                                        $profileStart = microtime(true);
                                        $results[$idx] = \CoroutineSerializerConfig::resourceToArray($resource, $cacheKey);
                                        \CoroutineSerializerConfig::recordResourceTime($cacheKey, (microtime(true) - $profileStart) * 1000);
                                    } catch (\Throwable $e) {
                                        $coErrors[$idx] = $e;
                                    } finally {
                                        if (!$isWhitelisted && empty($subCtx["query_executed"])) {
                                            \CoroutineSerializerConfig::$blacklist[$cacheKey] = true;
                                        }
                                        $wg->done();
                                    }
                                });
                            }
                        }
                        $wg->wait();
                        // 子协程异常：重新从父协程抛出，让框架统一处理，避免 Swoole 协程外 Fatal Error
                        if (!empty($coErrors)) {
                            throw reset($coErrors);
                        }
                        if (defined("LOG_LEVEL") && LOG_LEVEL === "debug") {
                            foreach ($results as $resultIdx => $resultItem) {
                                if (!is_array($resultItem) || empty($resultItem)) {
                                    $resource = $resources[$resultIdx] ?? null;
                                    $resourceType = is_object($resource) ? \CoroutineSerializerConfig::getResourceType($resource) : gettype($resource);
                                    echo "[Warn] Document included serializes to invalid item idx={$resultIdx} type={$resourceType}\n";
                                }
                            }
                        }
                        ksort($results);
                        $document["included"] = array_values(array_filter($results, function ($item) {
                            return is_array($item) && !empty($item);
                        }));
                    } else {
                        // 非协程或只有一个 included 时的降级
                        $document["included"] = array_values(array_filter(array_map(function (\Tobscure\JsonApi\Resource $resource) {
                            if (\CoroutineSerializerConfig::shouldSkipStandaloneIncludedResource($resource)) {
                                return null;
                            }

                            \CoroutineSerializerConfig::ensureResourceActorGroupsReady($resource);
                            $resourceType = \CoroutineSerializerConfig::getResourceType($resource);
                            $profileStart = microtime(true);
                            try {
                                return \CoroutineSerializerConfig::resourceToArray($resource, $resourceType);
                            } finally {
                                    \CoroutineSerializerConfig::recordResourceTime($resourceType, (microtime(true) - $profileStart) * 1000);
                            }
                        }, $resources)));
                    }
                }
            } finally {
                \CoroutineSerializerConfig::recordSerializationTime((microtime(true) - $serializationProfileStart) * 1000);
                if (isset($ctx)) {
                    $ctx["document_main_type"] = $prevMainType;
                }
            }
        }

        if (! empty($this->meta)) {
            $document["meta"] = $this->meta;
        }

        if (! empty($this->errors)) {
            $document["errors"] = $this->errors;
        }

        if (! empty($this->jsonapi)) {
            $document["jsonapi"] = $this->jsonapi;
        }

        if (\workerPluginPatchLoaded("fof-reactions")) {
            return \FoFReactionsWorkerPatch::ensureForDocument($document);
        }

        return $document;
    }';

            $patched = substr($src, 0, $lineStart + 1) . $newMethod . "\n" . substr($src, $pos);
            $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
            try {
                eval($code);
                if ($isDebugBoot) {
                    echo "[Boot] 协程序列化引擎注入成功（Document->included 并发版 + 预热打地鼠）。\n";
                }
            } catch (\Throwable $e) {
                echo "[Boot] ⚠️ 协程序列化注入失败 (Document): " . $e->getMessage() . "\n";
            }
        }
    }
}

if (\workerPluginPatchLoaded("fof-reactions")) {
    \FoFReactionsWorkerPatch::installPostAttributesPatch($composerLoader, $isDebugBoot);
}

if (!function_exists('replaceWorkerClassMethod')) {
    function replaceWorkerClassMethod(string $src, string $methodName, string $newMethod): array {
        $pattern = '/^[ \t]*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{;]*\{/m';
        if (!preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            return [$src, false];
        }

        $sigStart = $m[0][1];
        $sigEnd = $sigStart + strlen($m[0][0]);
        $depth = 1;
        $pos = $sigEnd;
        $len = strlen($src);

        while ($pos < $len && $depth > 0) {
            if ($src[$pos] === '{') {
                $depth++;
            } elseif ($src[$pos] === '}') {
                $depth--;
            }
            $pos++;
        }

        if ($depth !== 0) {
            return [$src, false];
        }

        return [substr($src, 0, $sigStart) . rtrim($newMethod) . "\n" . substr($src, $pos), true];
    }
}

if (\workerPluginPatchLoaded("ianm-follow-users")) {
    \IanMFollowUsersWorkerPatch::installPatches($composerLoader, $isDebugBoot);
}

if ($isDebugBoot) {
// 第四波注入：拦截 AbstractSerializeController::handle 细分 before_serialization 的耗时
$absSerializeCtrlFile = $composerLoader->findFile('Flarum\Api\Controller\AbstractSerializeController');
if ($absSerializeCtrlFile && file_exists($absSerializeCtrlFile) && !class_exists('Flarum\Api\Controller\AbstractSerializeController', false)) {
    $src = file_get_contents($absSerializeCtrlFile);
    
    // 我们在 public function handle(ServerRequestInterface $request): ResponseInterface 开头和中间打点
    $newMethod = '
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $controllerProfileStart = microtime(true);
        $controllerName = static::class;
        $ctx = \Swoole\Coroutine::getContext();
        $routeProfileRequestId = null;
        if ($ctx) {
            $routeProfileRequestId = $request->getAttribute("routeProfileRequestId");
            if ($routeProfileRequestId) {
                $ctx["route_profile_request_id"] = $routeProfileRequestId;
            }
            $routeProfileRequestId = $ctx["route_profile_request_id"] ?? $ctx["root_request_id"] ?? $ctx["request_id"] ?? null;
            $ctx["bs_ctrl_start"] = microtime(true);
        }

        $document = new \Tobscure\JsonApi\Document;

        $beforeDataStart = microtime(true);
        foreach (array_reverse(array_merge([static::class], class_parents($this))) as $class) {
            if (isset(static::$beforeDataCallbacks[$class])) {
                foreach (static::$beforeDataCallbacks[$class] as $callback) {
                    $callbackStart = microtime(true);
                    $callback($this);
                    \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.beforeData:" . $controllerName . ":" . \CoroutineSerializerConfig::describeCallback($callback), (microtime(true) - $callbackStart) * 1000, $routeProfileRequestId);
                }
            }
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.beforeData.total:" . $controllerName, (microtime(true) - $beforeDataStart) * 1000, $routeProfileRequestId);
        
        if ($ctx) $ctx["bs_data_start"] = microtime(true);
        $dataStart = microtime(true);
        $data = $this->data($request, $document);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.data:" . $controllerName, (microtime(true) - $dataStart) * 1000, $routeProfileRequestId);
        if ($ctx) $ctx["bs_data_end"] = microtime(true);

        $beforeSerializationStart = microtime(true);
        foreach (array_reverse(array_merge([static::class], class_parents($this))) as $class) {
            if (isset(static::$beforeSerializationCallbacks[$class])) {
                foreach (static::$beforeSerializationCallbacks[$class] as $callback) {
                    $callbackStart = microtime(true);
                    $callback($this, $data, $request, $document);
                    \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.beforeSerialization:" . $controllerName . ":" . \CoroutineSerializerConfig::describeCallback($callback), (microtime(true) - $callbackStart) * 1000, $routeProfileRequestId);
                }
            }
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.beforeSerialization.total:" . $controllerName, (microtime(true) - $beforeSerializationStart) * 1000, $routeProfileRequestId);
        
        if ($ctx) $ctx["bs_build_start"] = microtime(true);
        $buildStart = microtime(true);

        if (empty($this->serializer)) {
            throw new \InvalidArgumentException("Serializer required for controller: ".static::class);
        }

        $serializerStart = microtime(true);
        $serializer = static::$container->make($this->serializer);
        $serializer->setRequest($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.serializer_make:" . $controllerName . ":" . $this->serializer, (microtime(true) - $serializerStart) * 1000, $routeProfileRequestId);

        $elementStart = microtime(true);
        $element = $this->createElement($data, $serializer);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.createElement:" . $controllerName, (microtime(true) - $elementStart) * 1000, $routeProfileRequestId);

        $includeStart = microtime(true);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.extractInclude:" . $controllerName, (microtime(true) - $includeStart) * 1000, $routeProfileRequestId);

        $fieldsStart = microtime(true);
        $fields = $this->extractFields($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.extractFields:" . $controllerName, (microtime(true) - $fieldsStart) * 1000, $routeProfileRequestId);

        $element = $element->with($include)->fields($fields);

        $setDataStart = microtime(true);
        $document->setData($element);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.documentSetData:" . $controllerName, (microtime(true) - $setDataStart) * 1000, $routeProfileRequestId);
        
        if ($ctx) $ctx["bs_build_end"] = microtime(true);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.build.total:" . $controllerName, (microtime(true) - $buildStart) * 1000, $routeProfileRequestId);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.handle.total:" . $controllerName, (microtime(true) - $controllerProfileStart) * 1000, $routeProfileRequestId);

        return new \Flarum\Api\JsonApiResponse($document);
    }';

    $src = str_replace(<<<'PHP'
    protected function loadRelations(Collection $models, array $relations, ServerRequestInterface $request = null): void
    {
        $addedRelations = $this->getRelationsToLoad($models);
        $addedRelationCallables = $this->getRelationCallablesToLoad($models);

        foreach ($addedRelationCallables as $name => $relation) {
            $addedRelations[] = $name;
        }

        if (! empty($addedRelations)) {
            usort($addedRelations, function ($a, $b) {
                return substr_count($a, '.') - substr_count($b, '.');
            });

            foreach ($addedRelations as $relation) {
                if (strpos($relation, '.') !== false) {
                    $parentRelation = Str::beforeLast($relation, '.');

                    if (! in_array($parentRelation, $relations, true)) {
                        continue;
                    }
                }

                $relations[] = $relation;
            }
        }

        if (! empty($relations)) {
            $relations = array_unique($relations);
        }

        $callableRelations = [];
        $nonCallableRelations = [];

        foreach ($relations as $relation) {
            if (isset($addedRelationCallables[$relation])) {
                $load = $addedRelationCallables[$relation];

                $callableRelations[$relation] = function ($query) use ($load, $request, $relations) {
                    $load($query, $request, $relations);
                };
            } else {
                $nonCallableRelations[] = $relation;
            }
        }

        if (! empty($callableRelations)) {
            $models->loadMissing($callableRelations);
        }

        if (! empty($nonCallableRelations)) {
            $models->loadMissing($nonCallableRelations);
        }
    }
PHP, <<<'PHP'
    protected function loadRelations(Collection $models, array $relations, ServerRequestInterface $request = null): void
    {
        $controllerName = static::class;
        $modelCount = $models->count();
        $profileStart = microtime(true);

        $addedStart = microtime(true);
        $addedRelations = $this->getRelationsToLoad($models);
        $addedRelationCallables = $this->getRelationCallablesToLoad($models);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.collect:" . $controllerName . ":models" . $modelCount, (microtime(true) - $addedStart) * 1000);

        foreach ($addedRelationCallables as $name => $relation) {
            $addedRelations[] = $name;
        }

        $mergeStart = microtime(true);
        if (! empty($addedRelations)) {
            usort($addedRelations, function ($a, $b) {
                return substr_count($a, '.') - substr_count($b, '.');
            });

            foreach ($addedRelations as $relation) {
                if (strpos($relation, '.') !== false) {
                    $parentRelation = Str::beforeLast($relation, '.');

                    if (! in_array($parentRelation, $relations, true)) {
                        continue;
                    }
                }

                $relations[] = $relation;
            }
        }

        if (! empty($relations)) {
            $relations = array_unique($relations);
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.merge:" . $controllerName . ":r" . count($relations), (microtime(true) - $mergeStart) * 1000);

        $callableRelations = [];
        $nonCallableRelations = [];

        foreach ($relations as $relation) {
            if (isset($addedRelationCallables[$relation])) {
                $load = $addedRelationCallables[$relation];

                $callableRelations[$relation] = function ($query) use ($load, $request, $relations) {
                    $load($query, $request, $relations);
                };
            } else {
                $nonCallableRelations[] = $relation;
            }
        }

        if (! empty($callableRelations)) {
            $loadStart = microtime(true);
            $models->loadMissing($callableRelations);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.callable:" . $controllerName . ":r" . implode(",", array_keys($callableRelations)), (microtime(true) - $loadStart) * 1000);
        }

        if (! empty($nonCallableRelations)) {
            $normalStart = microtime(true);
            $relationGroups = [];

            foreach ($nonCallableRelations as $relation) {
                if (strpos($relation, 'userBadges') !== false) {
                    $group = 'badges';
                } elseif (strpos($relation, 'mentions') !== false || strpos($relation, 'mentionedBy') !== false) {
                    $group = 'mentions';
                } elseif (strpos($relation, 'recipient') === 0) {
                    $group = 'recipients';
                } elseif (strpos($relation, 'tags') === 0 || strpos($relation, 'stickyTags') === 0) {
                    $group = 'tags';
                } elseif (strpos($relation, 'polls') === 0) {
                    $group = 'polls';
                } elseif ($relation === 'state' || $relation === 'bookmarkState') {
                    $group = 'state';
                } elseif ($relation === 'firstPost' || $relation === 'mostRelevantPost' || $relation === 'discussion') {
                    $group = 'posts';
                } elseif ($relation === 'user' || $relation === 'lastPostedUser' || strpos($relation, '.user') !== false || strpos($relation, 'User') !== false || strpos($relation, 'user.groups') === 0) {
                    $group = 'users';
                } else {
                    $group = 'other';
                }

                $relationGroups[$group][] = $relation;
            }

            foreach ($relationGroups as $group => $groupRelations) {
                $loadStart = microtime(true);
                $models->loadMissing($groupRelations);
                \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.normalGroup:" . $controllerName . ":" . $group . ":r" . implode(",", $groupRelations), (microtime(true) - $loadStart) * 1000);
            }

            \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.normal:" . $controllerName . ":r" . implode(",", $nonCallableRelations), (microtime(true) - $normalStart) * 1000);
        }

        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.total:" . $controllerName . ":models" . $modelCount, (microtime(true) - $profileStart) * 1000);
    }
PHP, $src);

    if (strpos($src, 'controller.loadRelations.collect:') === false) {
        [$src, $loadRelationsMatched] = replaceWorkerClassMethod($src, 'loadRelations', <<<'PHP'
    protected function loadRelations(Collection $models, array $relations, ServerRequestInterface $request = null): void
    {
        $controllerName = static::class;
        $modelCount = $models->count();
        $profileStart = microtime(true);

        $addedStart = microtime(true);
        $addedRelations = $this->getRelationsToLoad($models);
        $addedRelationCallables = $this->getRelationCallablesToLoad($models);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.collect:" . $controllerName . ":models" . $modelCount, (microtime(true) - $addedStart) * 1000);

        foreach ($addedRelationCallables as $name => $relation) {
            $addedRelations[] = $name;
        }

        $mergeStart = microtime(true);
        if (! empty($addedRelations)) {
            usort($addedRelations, function ($a, $b) {
                return substr_count($a, '.') - substr_count($b, '.');
            });

            foreach ($addedRelations as $relation) {
                if (strpos($relation, '.') !== false) {
                    $parentRelation = Str::beforeLast($relation, '.');

                    if (! in_array($parentRelation, $relations, true)) {
                        continue;
                    }
                }

                $relations[] = $relation;
            }
        }

        if (! empty($relations)) {
            $relations = array_unique($relations);
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.merge:" . $controllerName . ":r" . count($relations), (microtime(true) - $mergeStart) * 1000);

        $callableRelations = [];
        $nonCallableRelations = [];

        foreach ($relations as $relation) {
            if (isset($addedRelationCallables[$relation])) {
                $load = $addedRelationCallables[$relation];

                $callableRelations[$relation] = function ($query) use ($load, $request, $relations) {
                    $load($query, $request, $relations);
                };
            } else {
                $nonCallableRelations[] = $relation;
            }
        }

        if (! empty($callableRelations)) {
            $loadStart = microtime(true);
            $models->loadMissing($callableRelations);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.callable:" . $controllerName . ":r" . implode(",", array_keys($callableRelations)), (microtime(true) - $loadStart) * 1000);
        }

        if (! empty($nonCallableRelations)) {
            $normalStart = microtime(true);
            $relationGroups = [];

            foreach ($nonCallableRelations as $relation) {
                if (strpos($relation, 'userBadges') !== false) {
                    $group = 'badges';
                } elseif (strpos($relation, 'mentions') !== false || strpos($relation, 'mentionedBy') !== false) {
                    $group = 'mentions';
                } elseif (strpos($relation, 'recipient') === 0) {
                    $group = 'recipients';
                } elseif (strpos($relation, 'tags') === 0 || strpos($relation, 'stickyTags') === 0) {
                    $group = 'tags';
                } elseif (strpos($relation, 'polls') === 0) {
                    $group = 'polls';
                } elseif ($relation === 'state' || $relation === 'bookmarkState') {
                    $group = 'state';
                } elseif ($relation === 'firstPost' || $relation === 'mostRelevantPost' || $relation === 'discussion') {
                    $group = 'posts';
                } elseif ($relation === 'user' || $relation === 'lastPostedUser' || strpos($relation, '.user') !== false || strpos($relation, 'User') !== false || strpos($relation, 'user.groups') === 0) {
                    $group = 'users';
                } else {
                    $group = 'other';
                }

                $relationGroups[$group][] = $relation;
            }

            foreach ($relationGroups as $group => $groupRelations) {
                $loadStart = microtime(true);
                $models->loadMissing($groupRelations);
                \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.normalGroup:" . $controllerName . ":" . $group . ":r" . implode(",", $groupRelations), (microtime(true) - $loadStart) * 1000);
            }

            \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.normal:" . $controllerName . ":r" . implode(",", $nonCallableRelations), (microtime(true) - $normalStart) * 1000);
        }

        \CoroutineSerializerConfig::recordBeforeSerializationPhase("controller.loadRelations.total:" . $controllerName . ":models" . $modelCount, (microtime(true) - $profileStart) * 1000);
    }
PHP);

        if (!$loadRelationsMatched) {
            echo "[Boot] ⚠️ loadRelations 细分打点未匹配（AbstractSerializeController）。\n";
        }
    }

    // 使用正则替换掉原本的 handle 方法
    if (preg_match('/public\s+function\s+handle\s*\([^)]*\)[^{]*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
        $sigEnd = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $pos = $sigEnd;
        $len = strlen($src);
        while ($pos < $len && $depth > 0) {
            if ($src[$pos] === '{') $depth++;
            elseif ($src[$pos] === '}') $depth--;
            $pos++;
        }
        $lineStart = strrpos(substr($src, 0, $m[0][1]), "\n");
        $patched = substr($src, 0, $lineStart + 1) . $newMethod . "\n" . substr($src, $pos);
        
        $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
        try {
            eval($code);
            echo "[Boot] 细分打点注入成功（AbstractSerializeController::handle）。\n";
        } catch (\Throwable $e) {
            echo "[Boot] ⚠️ 细分打点注入失败: " . $e->getMessage() . "\n";
        }
    }
}

// 第四点五波注入：细分热点 API controller 的 data() 内部耗时
function patchControllerDataProfiler($composerLoader, string $class, callable $patcher, string $label): void {
    $file = $composerLoader->findFile($class);
    if (!$file || !file_exists($file)) {
        echo "[Boot] ⚠️ data 细分打点跳过（{$label}: 文件不存在）。\n";
        return;
    }

    if (class_exists($class, false)) {
        echo "[Boot] ⚠️ data 细分打点跳过（{$label}: 类已加载）。\n";
        return;
    }

    $src = file_get_contents($file);
    $patched = $patcher($src);
    if ($patched === $src || !controllerDataProfilerPatchLooksComplete($patched, $label)) {
        $fallbackPatched = fallbackControllerDataProfilerPatch($patched, $label);
        if ($fallbackPatched !== $patched) {
            $patched = $fallbackPatched;
        }
    }

    if ($patched === $src || !controllerDataProfilerPatchLooksComplete($patched, $label)) {
        echo "[Boot] ⚠️ data 细分打点未匹配（{$label}）。\n";
        return;
    }

    $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
    try {
        eval($code);
        echo "[Boot] data 细分打点注入成功（{$label}）。\n";
    } catch (\Throwable $e) {
        echo "[Boot] ⚠️ data 细分打点注入失败 ({$label}): " . $e->getMessage() . "\n";
    }
}

function controllerDataProfilerPatchLooksComplete(string $src, string $label): bool {
    $markers = [
        'ListDiscussionsController' => [
            'data.ListDiscussions.params',
            'data.ListDiscussions.loadRelations',
            'data.ListDiscussions.total',
        ],
        'ListPostsController' => [
            'data.ListPosts.params',
            'data.ListPosts.loadRelations',
            'data.ListPosts.total',
        ],
        'ShowDiscussionController' => [
            'data.ShowDiscussion.params',
            'data.ShowDiscussion.includePosts',
            'data.ShowDiscussion.loadPosts.query',
            'data.ShowDiscussion.loadPosts.loadRelations',
            'data.ShowDiscussion.total',
        ],
        'ShowPostController' => [
            'data.ShowPost.find',
            'data.ShowPost.loadRelations',
            'data.ShowPost.total',
        ],
    ][$label] ?? [];

    foreach ($markers as $marker) {
        if (strpos($src, $marker) === false) {
            return false;
        }
    }

    return true;
}

function fallbackControllerDataProfilerPatch(string $src, string $label): string {
    if ($label === 'ListDiscussionsController') {
        [$patched, $matched] = replaceWorkerClassMethod($src, 'data', <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $actor = RequestUtil::getActor($request);
        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);
        $sortIsDefault = $this->sortIsDefault($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);
        $include = array_merge($this->extractInclude($request), ['state']);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.params:i" . count($include) . ":f" . count($filters), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $criteria = new QueryCriteria($actor, $filters, $sort, $sortIsDefault);
        if (array_key_exists('q', $filters)) {
            $results = $this->searcher->search($criteria, $limit, $offset);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.searcher.search", (microtime(true) - $phaseStart) * 1000);
        } else {
            $results = $this->filterer->filter($criteria, $limit, $offset);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.filterer.filter", (microtime(true) - $phaseStart) * 1000);
        }

        $phaseStart = microtime(true);
        $document->addPaginationLinks(
            $this->url->to('api')->route('discussions.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->areMoreResults() ? null : 0
        );
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.pagination", (microtime(true) - $phaseStart) * 1000);

        Discussion::setStateUser($actor);

        $phaseStart = microtime(true);
        if (in_array('mostRelevantPost.user', $include)) {
            $include[] = 'mostRelevantPost.user.groups';

            if (! in_array('mostRelevantPost', $include)) {
                $include[] = 'mostRelevantPost';
            }
        }

        $results = $results->getResults();
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.getResults:n" . count($results), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations($results, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.loadRelations:n" . count($results), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        if ($relations = array_intersect($include, ['firstPost', 'lastPost', 'mostRelevantPost'])) {
            foreach ($results as $discussion) {
                foreach ($relations as $relation) {
                    if ($discussion->$relation) {
                        $discussion->$relation->discussion = $discussion;
                    }
                }
            }
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.backrefs", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.total", (microtime(true) - $profileStart) * 1000);

        return $results;
    }
PHP);

        return $matched ? $patched : $src;
    }

    if ($label === 'ListPostsController') {
        [$patched, $matched] = replaceWorkerClassMethod($src, 'data', <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);
        $sortIsDefault = $this->sortIsDefault($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.params:i" . count($include) . ":f" . count($filters), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $results = $this->filterer->filter(new QueryCriteria($actor, $filters, $sort, $sortIsDefault), $limit, $offset);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.filterer.filter", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $document->addPaginationLinks(
            $this->url->to('api')->route('posts.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->areMoreResults() ? null : 0
        );
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.pagination", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        if (! in_array('discussion', $include)) {
            $include[] = 'discussion';
        }

        if (in_array('user', $include)) {
            $include[] = 'user.groups';
        }

        $results = $results->getResults();
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.getResults:n" . count($results), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations($results, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.loadRelations:n" . count($results), (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.total", (microtime(true) - $profileStart) * 1000);

        return $results;
    }
PHP);

        return $matched ? $patched : $src;
    }

    if ($label === 'ShowDiscussionController') {
        $patched = $src;
        $matchedAny = false;

        [$patched, $matched] = replaceWorkerClassMethod($patched, 'data', <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $actor = RequestUtil::getActor($request);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.params:i" . count($include), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        if (Arr::get($request->getQueryParams(), 'bySlug', false)) {
            $discussion = $this->slugManager->forResource(Discussion::class)->fromSlug($discussionId, $actor);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.find.bySlug", (microtime(true) - $phaseStart) * 1000);
        } else {
            $discussion = $this->discussions->findOrFail($discussionId, $actor);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.find.byId", (microtime(true) - $phaseStart) * 1000);
        }

        if (in_array('posts', $include) || Str::contains(implode(',', $include), 'posts.')) {
            $phaseStart = microtime(true);
            $postRelationships = $this->getPostRelationships($include);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.postRelationships:r" . count($postRelationships), (microtime(true) - $phaseStart) * 1000);

            $phaseStart = microtime(true);
            $this->includePosts($discussion, $request, $postRelationships);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.includePosts", (microtime(true) - $phaseStart) * 1000);
        }

        $phaseStart = microtime(true);
        $this->loadRelations(new Collection([$discussion]), array_filter($include, function ($relationship) {
            return ! Str::startsWith($relationship, 'posts');
        }), $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadDiscussionRelations", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.total", (microtime(true) - $profileStart) * 1000);

        return $discussion;
    }
PHP);
        $matchedAny = $matchedAny || $matched;

        [$patched, $matched] = replaceWorkerClassMethod($patched, 'includePosts', <<<'PHP'
    private function includePosts(Discussion $discussion, ServerRequestInterface $request, array $include)
    {
        $profileStart = microtime(true);
        $actor = RequestUtil::getActor($request);
        $limit = $this->extractLimit($request);
        $offset = $this->getPostsOffset($request, $discussion, $limit);

        $phaseStart = microtime(true);
        $allPosts = $this->loadPostIds($discussion, $actor);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPostIds:n" . count($allPosts), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $loadedPosts = $this->loadPosts($discussion, $actor, $offset, $limit, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts:n" . count($loadedPosts), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        array_splice($allPosts, $offset, $limit, $loadedPosts);

        $discussion->setRelation('posts', $allPosts);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.spliceSetPosts", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.includePosts.total", (microtime(true) - $profileStart) * 1000);
    }
PHP);
        $matchedAny = $matchedAny || $matched;

        [$patched, $matched] = replaceWorkerClassMethod($patched, 'loadPosts', <<<'PHP'
    private function loadPosts($discussion, $actor, $offset, $limit, array $include, ServerRequestInterface $request)
    {
        $query = $discussion->posts()->whereVisibleTo($actor);

        $query->orderBy('number')->skip($offset)->take($limit);

        $phaseStart = microtime(true);
        $posts = $query->get();
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts.query:n" . $posts->count(), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        foreach ($posts as $post) {
            $post->discussion = $discussion;
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts.backrefs", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations($posts, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts.loadRelations:n" . $posts->count(), (microtime(true) - $phaseStart) * 1000);

        return $posts->all();
    }
PHP);
        $matchedAny = $matchedAny || $matched;

        return $matchedAny ? $patched : $src;
    }

    if ($label === 'ShowPostController') {
        [$patched, $matched] = replaceWorkerClassMethod($src, 'data', <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $post = $this->posts->findOrFail(Arr::get($request->getQueryParams(), 'id'), RequestUtil::getActor($request));
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.find", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.extractInclude:i" . count($include), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations(new Collection([$post]), $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.loadRelations", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.total", (microtime(true) - $profileStart) * 1000);

        return $post;
    }
PHP);

        return $matched ? $patched : $src;
    }

    return $src;
}

patchControllerDataProfiler($composerLoader, 'Flarum\Api\Controller\ListDiscussionsController', function (string $src): string {
    return str_replace(<<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);
        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);
        $sortIsDefault = $this->sortIsDefault($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);
        $include = array_merge($this->extractInclude($request), ['state']);

        $criteria = new QueryCriteria($actor, $filters, $sort, $sortIsDefault);
        if (array_key_exists('q', $filters)) {
            $results = $this->searcher->search($criteria, $limit, $offset);
        } else {
            $results = $this->filterer->filter($criteria, $limit, $offset);
        }

        $document->addPaginationLinks(
            $this->url->to('api')->route('discussions.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->areMoreResults() ? null : 0
        );

        Discussion::setStateUser($actor);

        // Eager load groups for use in the policies (isAdmin check)
        if (in_array('mostRelevantPost.user', $include)) {
            $include[] = 'mostRelevantPost.user.groups';

            // If the first level of the relationship wasn't explicitly included,
            // add it so the code below can look for it
            if (! in_array('mostRelevantPost', $include)) {
                $include[] = 'mostRelevantPost';
            }
        }

        $results = $results->getResults();

        $this->loadRelations($results, $include, $request);

        if ($relations = array_intersect($include, ['firstPost', 'lastPost', 'mostRelevantPost'])) {
            foreach ($results as $discussion) {
                foreach ($relations as $relation) {
                    if ($discussion->$relation) {
                        $discussion->$relation->discussion = $discussion;
                    }
                }
            }
        }

        return $results;
    }
PHP, <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $actor = RequestUtil::getActor($request);
        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);
        $sortIsDefault = $this->sortIsDefault($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);
        $include = array_merge($this->extractInclude($request), ['state']);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.params:i" . count($include) . ":f" . count($filters), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $criteria = new QueryCriteria($actor, $filters, $sort, $sortIsDefault);
        if (array_key_exists('q', $filters)) {
            $results = $this->searcher->search($criteria, $limit, $offset);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.searcher.search", (microtime(true) - $phaseStart) * 1000);
        } else {
            $results = $this->filterer->filter($criteria, $limit, $offset);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.filterer.filter", (microtime(true) - $phaseStart) * 1000);
        }

        $phaseStart = microtime(true);
        $document->addPaginationLinks(
            $this->url->to('api')->route('discussions.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->areMoreResults() ? null : 0
        );
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.pagination", (microtime(true) - $phaseStart) * 1000);

        Discussion::setStateUser($actor);

        $phaseStart = microtime(true);
        if (in_array('mostRelevantPost.user', $include)) {
            $include[] = 'mostRelevantPost.user.groups';

            if (! in_array('mostRelevantPost', $include)) {
                $include[] = 'mostRelevantPost';
            }
        }

        $results = $results->getResults();
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.getResults:n" . count($results), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations($results, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.loadRelations:n" . count($results), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        if ($relations = array_intersect($include, ['firstPost', 'lastPost', 'mostRelevantPost'])) {
            foreach ($results as $discussion) {
                foreach ($relations as $relation) {
                    if ($discussion->$relation) {
                        $discussion->$relation->discussion = $discussion;
                    }
                }
            }
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.backrefs", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListDiscussions.total", (microtime(true) - $profileStart) * 1000);

        return $results;
    }
PHP, $src);
}, 'ListDiscussionsController');

patchControllerDataProfiler($composerLoader, 'Flarum\Api\Controller\ListPostsController', function (string $src): string {
    return str_replace(<<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);
        $sortIsDefault = $this->sortIsDefault($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);
        $include = $this->extractInclude($request);

        $results = $this->filterer->filter(new QueryCriteria($actor, $filters, $sort, $sortIsDefault), $limit, $offset);

        $document->addPaginationLinks(
            $this->url->to('api')->route('posts.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->areMoreResults() ? null : 0
        );

        // Eager load discussion for use in the policies,
        // eager loading does not affect the JSON response,
        // the response only includes relations included in the request.
        if (! in_array('discussion', $include)) {
            $include[] = 'discussion';
        }

        if (in_array('user', $include)) {
            $include[] = 'user.groups';
        }

        $results = $results->getResults();

        $this->loadRelations($results, $include, $request);

        return $results;
    }
PHP, <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $actor = RequestUtil::getActor($request);

        $filters = $this->extractFilter($request);
        $sort = $this->extractSort($request);
        $sortIsDefault = $this->sortIsDefault($request);

        $limit = $this->extractLimit($request);
        $offset = $this->extractOffset($request);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.params:i" . count($include) . ":f" . count($filters), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $results = $this->filterer->filter(new QueryCriteria($actor, $filters, $sort, $sortIsDefault), $limit, $offset);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.filterer.filter", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $document->addPaginationLinks(
            $this->url->to('api')->route('posts.index'),
            $request->getQueryParams(),
            $offset,
            $limit,
            $results->areMoreResults() ? null : 0
        );
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.pagination", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        if (! in_array('discussion', $include)) {
            $include[] = 'discussion';
        }

        if (in_array('user', $include)) {
            $include[] = 'user.groups';
        }

        $results = $results->getResults();
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.getResults:n" . count($results), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations($results, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.loadRelations:n" . count($results), (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ListPosts.total", (microtime(true) - $profileStart) * 1000);

        return $results;
    }
PHP, $src);
}, 'ListPostsController');

patchControllerDataProfiler($composerLoader, 'Flarum\Api\Controller\ShowDiscussionController', function (string $src): string {
    $src = str_replace(<<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $actor = RequestUtil::getActor($request);
        $include = $this->extractInclude($request);

        if (Arr::get($request->getQueryParams(), 'bySlug', false)) {
            $discussion = $this->slugManager->forResource(Discussion::class)->fromSlug($discussionId, $actor);
        } else {
            $discussion = $this->discussions->findOrFail($discussionId, $actor);
        }

        // If posts is included or a sub relation of post is included.
        if (in_array('posts', $include) || Str::contains(implode(',', $include), 'posts.')) {
            $postRelationships = $this->getPostRelationships($include);

            $this->includePosts($discussion, $request, $postRelationships);
        }

        $this->loadRelations(new Collection([$discussion]), array_filter($include, function ($relationship) {
            return ! Str::startsWith($relationship, 'posts');
        }), $request);

        return $discussion;
    }
PHP, <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $discussionId = Arr::get($request->getQueryParams(), 'id');
        $actor = RequestUtil::getActor($request);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.params:i" . count($include), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        if (Arr::get($request->getQueryParams(), 'bySlug', false)) {
            $discussion = $this->slugManager->forResource(Discussion::class)->fromSlug($discussionId, $actor);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.find.bySlug", (microtime(true) - $phaseStart) * 1000);
        } else {
            $discussion = $this->discussions->findOrFail($discussionId, $actor);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.find.byId", (microtime(true) - $phaseStart) * 1000);
        }

        if (in_array('posts', $include) || Str::contains(implode(',', $include), 'posts.')) {
            $phaseStart = microtime(true);
            $postRelationships = $this->getPostRelationships($include);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.postRelationships:r" . count($postRelationships), (microtime(true) - $phaseStart) * 1000);

            $phaseStart = microtime(true);
            $this->includePosts($discussion, $request, $postRelationships);
            \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.includePosts", (microtime(true) - $phaseStart) * 1000);
        }

        $phaseStart = microtime(true);
        $this->loadRelations(new Collection([$discussion]), array_filter($include, function ($relationship) {
            return ! Str::startsWith($relationship, 'posts');
        }), $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadDiscussionRelations", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.total", (microtime(true) - $profileStart) * 1000);

        return $discussion;
    }
PHP, $src);

    $src = str_replace(<<<'PHP'
    private function includePosts(Discussion $discussion, ServerRequestInterface $request, array $include)
    {
        $actor = RequestUtil::getActor($request);
        $limit = $this->extractLimit($request);
        $offset = $this->getPostsOffset($request, $discussion, $limit);

        $allPosts = $this->loadPostIds($discussion, $actor);
        $loadedPosts = $this->loadPosts($discussion, $actor, $offset, $limit, $include, $request);

        array_splice($allPosts, $offset, $limit, $loadedPosts);

        $discussion->setRelation('posts', $allPosts);
    }
PHP, <<<'PHP'
    private function includePosts(Discussion $discussion, ServerRequestInterface $request, array $include)
    {
        $profileStart = microtime(true);
        $actor = RequestUtil::getActor($request);
        $limit = $this->extractLimit($request);
        $offset = $this->getPostsOffset($request, $discussion, $limit);

        $phaseStart = microtime(true);
        $allPosts = $this->loadPostIds($discussion, $actor);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPostIds:n" . count($allPosts), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $loadedPosts = $this->loadPosts($discussion, $actor, $offset, $limit, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts:n" . count($loadedPosts), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        array_splice($allPosts, $offset, $limit, $loadedPosts);

        $discussion->setRelation('posts', $allPosts);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.spliceSetPosts", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.includePosts.total", (microtime(true) - $profileStart) * 1000);
    }
PHP, $src);

    $src = str_replace(<<<'PHP'
    private function loadPosts($discussion, $actor, $offset, $limit, array $include, ServerRequestInterface $request)
    {
        $query = $discussion->posts()->whereVisibleTo($actor);

        $query->orderBy('number')->skip($offset)->take($limit);

        $posts = $query->get();

        foreach ($posts as $post) {
            $post->discussion = $discussion;
        }

        $this->loadRelations($posts, $include, $request);

        return $posts->all();
    }
PHP, <<<'PHP'
    private function loadPosts($discussion, $actor, $offset, $limit, array $include, ServerRequestInterface $request)
    {
        $query = $discussion->posts()->whereVisibleTo($actor);

        $query->orderBy('number')->skip($offset)->take($limit);

        $phaseStart = microtime(true);
        $posts = $query->get();
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts.query:n" . $posts->count(), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        foreach ($posts as $post) {
            $post->discussion = $discussion;
        }
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts.backrefs", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations($posts, $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowDiscussion.loadPosts.loadRelations:n" . $posts->count(), (microtime(true) - $phaseStart) * 1000);

        return $posts->all();
    }
PHP, $src);

    return $src;
}, 'ShowDiscussionController');

patchControllerDataProfiler($composerLoader, 'Flarum\Api\Controller\ShowPostController', function (string $src): string {
    return str_replace(<<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $post = $this->posts->findOrFail(Arr::get($request->getQueryParams(), 'id'), RequestUtil::getActor($request));

        $include = $this->extractInclude($request);

        $this->loadRelations(new Collection([$post]), $include, $request);

        return $post;
    }
PHP, <<<'PHP'
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $profileStart = microtime(true);
        $phaseStart = microtime(true);
        $post = $this->posts->findOrFail(Arr::get($request->getQueryParams(), 'id'), RequestUtil::getActor($request));
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.find", (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $include = $this->extractInclude($request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.extractInclude:i" . count($include), (microtime(true) - $phaseStart) * 1000);

        $phaseStart = microtime(true);
        $this->loadRelations(new Collection([$post]), $include, $request);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.loadRelations", (microtime(true) - $phaseStart) * 1000);
        \CoroutineSerializerConfig::recordBeforeSerializationPhase("data.ShowPost.total", (microtime(true) - $profileStart) * 1000);

        return $post;
    }
PHP, $src);
}, 'ShowPostController');

// 第五波注入：细分 HTML 路由中的 frontend content 和内部 Api\Client 调用
$apiClientFile = $composerLoader->findFile('Flarum\Api\Client');
if ($apiClientFile && file_exists($apiClientFile) && !class_exists('Flarum\Api\Client', false)) {
    $src = file_get_contents($apiClientFile);
    [$src, $clientSendMatched] = replaceWorkerClassMethod($src, 'send', <<<'PHP'
    public function send(string $method, string $path): ResponseInterface
    {
        $profileStart = microtime(true);
        $routeProfileRequestId = null;
        if (\Swoole\Coroutine::getCid() > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            $routeProfileRequestId = $ctx["route_profile_request_id"] ?? $ctx["root_request_id"] ?? $ctx["request_id"] ?? null;
        }

        try {
            $request = ServerRequestFactory::fromGlobals(null, $this->queryParams, $this->body)
                ->withMethod($method)
                ->withUri(new Uri($path));

            if ($routeProfileRequestId !== null) {
                $request = $request->withAttribute("routeProfileRequestId", $routeProfileRequestId);
            }

            if ($this->parent) {
                $request = $request
                    ->withAttribute('ipAddress', $this->parent->getAttribute('ipAddress'))
                    ->withAttribute('session', $this->parent->getAttribute('session'));
                $request = RequestUtil::withActor($request, RequestUtil::getActor($this->parent));
            }

            if ($this->actor) {
                $request = RequestUtil::withActor($request, $this->actor);
            }

            return $this->pipe->handle($request);
        } finally {
            \CoroutineSerializerConfig::recordRoutePhase("apiClient." . $method . " " . $path, (microtime(true) - $profileStart) * 1000);
        }
    }
PHP);

    $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
    if (!$clientSendMatched) {
        echo "[Boot] ⚠️ frontend/API Client 打点注入失败 (Client): send 方法未匹配。\n";
    } else {
        try {
            eval($code);
            echo "[Boot] frontend/API Client 细分打点注入成功（Flarum\\Api\\Client）。\n";
        } catch (\Throwable $e) {
            echo "[Boot] ⚠️ frontend/API Client 打点注入失败 (Client): " . $e->getMessage() . "\n";
        }
    }
}

$frontendFile = $composerLoader->findFile('Flarum\Frontend\Frontend');
if ($frontendFile && file_exists($frontendFile) && !class_exists('Flarum\Frontend\Frontend', false)) {
    $src = file_get_contents($frontendFile);
    $src = str_replace(
        '    public function document(Request $request): Document
    {
        $forumDocument = $this->getForumDocument($request);',
        '    public function document(Request $request): Document
    {
        $profileStart = microtime(true);
        try {
        $forumDocument = $this->getForumDocument($request);',
        $src
    );
    $src = str_replace(
        '        return $document;
    }',
        '        return $document;
        } finally {
            \CoroutineSerializerConfig::recordRoutePhase("frontend.document", (microtime(true) - $profileStart) * 1000);
        }
    }',
        $src
    );
    $src = str_replace(
        '    protected function populate(Document $document, Request $request)
    {
        foreach ($this->content as $content) {
            $content($document, $request);
        }
    }',
        '    protected function populate(Document $document, Request $request)
    {
        foreach ($this->content as $content) {
            $contentStart = microtime(true);
            try {
                $content($document, $request);
            } finally {
                \CoroutineSerializerConfig::recordRoutePhase("frontend.content:" . \CoroutineSerializerConfig::describeCallback($content), (microtime(true) - $contentStart) * 1000);
            }
        }
    }',
        $src
    );
    $src = str_replace(
        '    private function getForumDocument(Request $request): array
    {
        return $this->getResponseBody(
            $this->api->withParentRequest($request)->get(\'/\')
        );
    }',
        '    private function getForumDocument(Request $request): array
    {
        $profileStart = microtime(true);
        try {
            return $this->getResponseBody(
                $this->api->withParentRequest($request)->get(\'/\')
            );
        } finally {
            \CoroutineSerializerConfig::recordRoutePhase("frontend.getForumDocument", (microtime(true) - $profileStart) * 1000);
        }
    }',
        $src
    );

    $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
    try {
        eval($code);
        echo "[Boot] frontend 细分打点注入成功（Flarum\\Frontend\\Frontend）。\n";
    } catch (\Throwable $e) {
        echo "[Boot] ⚠️ frontend 打点注入失败: " . $e->getMessage() . "\n";
    }
}

$forumIndexFile = $composerLoader->findFile('Flarum\Forum\Content\Index');
if ($forumIndexFile && file_exists($forumIndexFile) && !class_exists('Flarum\Forum\Content\Index', false)) {
    $src = file_get_contents($forumIndexFile);
    $src = str_replace(
        '    protected function getApiDocument(Request $request, array $params)
    {
        return json_decode($this->api->withParentRequest($request)->withQueryParams($params)->get(\'/discussions\')->getBody());
    }',
        '    protected function getApiDocument(Request $request, array $params)
    {
        $profileStart = microtime(true);
        try {
            return \swoole_fast_json_decode($this->api->withParentRequest($request)->withQueryParams($params)->get(\'/discussions\')->getBody());
        } finally {
            \CoroutineSerializerConfig::recordRoutePhase("forum.content.index.getApiDocument", (microtime(true) - $profileStart) * 1000);
        }
    }',
        $src
    );

    $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
    try {
        eval($code);
        echo "[Boot] frontend 细分打点注入成功（Forum\\Content\\Index）。\n";
    } catch (\Throwable $e) {
        echo "[Boot] ⚠️ frontend 打点注入失败 (Index): " . $e->getMessage() . "\n";
    }
}

$forumDiscussionFile = $composerLoader->findFile('Flarum\Forum\Content\Discussion');
if ($forumDiscussionFile && file_exists($forumDiscussionFile) && !class_exists('Flarum\Forum\Content\Discussion', false)) {
    $src = file_get_contents($forumDiscussionFile);
    $src = str_replace(
        '    protected function getApiDocument(Request $request, string $id, array $params)
    {
        $params[\'bySlug\'] = true;
        $response = $this->api
            ->withParentRequest($request)
            ->withQueryParams($params)
            ->get("/discussions/$id");
        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new RouteNotFoundException;
        }

        return json_decode($response->getBody());
    }',
        '    protected function getApiDocument(Request $request, string $id, array $params)
    {
        $profileStart = microtime(true);
        try {
        $params[\'bySlug\'] = true;
        $response = $this->api
            ->withParentRequest($request)
            ->withQueryParams($params)
            ->get("/discussions/$id");
        $statusCode = $response->getStatusCode();

        if ($statusCode === 404) {
            throw new RouteNotFoundException;
        }

        $document = \swoole_fast_json_decode($response->getBody());
        if (\workerPluginPatchLoaded("fof-reactions")) {
            return \FoFReactionsWorkerPatch::ensureInDiscussionPreload($document);
        }

        return $document;
        } finally {
            \CoroutineSerializerConfig::recordRoutePhase("forum.content.discussion.getApiDocument", (microtime(true) - $profileStart) * 1000);
        }
    }',
        $src
    );

    $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
    try {
        eval($code);
        echo "[Boot] frontend 细分打点注入成功（Forum\\Content\\Discussion）。\n";
    } catch (\Throwable $e) {
        echo "[Boot] ⚠️ frontend 打点注入失败 (Discussion): " . $e->getMessage() . "\n";
    }
}

    if ($isDebugBoot) {
        $patchFile = $composerLoader->findFile('Flarum\Api\Controller\AbstractSerializeController');
        if ($patchFile && file_exists($patchFile) && !class_exists('Flarum\Api\Controller\AbstractSerializeController', false)) {
            $src = file_get_contents($patchFile);
            $newHandle = '
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = \Swoole\Coroutine::getContext();
        if ($ctx) {
            $ctx["bs_ctrl_start"] = microtime(true);
        }
        
        $document = new Document;

        if ($ctx) {
            $ctx["bs_data_start"] = microtime(true);
        }
        
        $data = $this->data($request, $document);

        if ($ctx) {
            $ctx["bs_data_end"] = microtime(true);
            $ctx["bs_build_start"] = microtime(true);
        }
        
        try {
            $serializer = $this->serializer;
            $serializer = new $serializer;
            $serializer->setRequest($request);

            $element = $this->createElement($data, $serializer)
                ->with($this->extractInclude($request))
                ->fields($this->extractFields($request));

            $document->setData($element);

            $response = new JsonApiResponse($document);

            if ($ctx) {
                $ctx["bs_build_end"] = microtime(true);
            }
            return $response;
            
        } catch (\Exception $e) {
            if ($ctx) {
                $ctx["bs_build_end"] = microtime(true);
            }
            throw $e;
        }
    }';
            if (preg_match('/public\s+function\s+handle\s*\([^)]*\)\s*:\s*ResponseInterface\s*\{.*?\}/is', $src, $m)) {
                $src = str_replace($m[0], $newHandle, $src);
                $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
                try { eval($code); echo "[Boot] AbstractSerializeController 性能打点注入成功。\n"; } catch (\Throwable $e) {}
            }
        }
    }
}

use Swoole\Http\Server;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface as PsrResponse;
use Psr\Http\Message\ServerRequestInterface;

// ============================================================
class CoroutineViewProxy implements \Illuminate\Contracts\View\Factory {
    protected $realView;

    public function __construct($realView) {
        $this->realView = $realView;
    }

    public function exists($view) {
        return $this->realView->exists($view);
    }

    public function file($path, $data = [], $mergeData = []) {
        $ctx = \Swoole\Coroutine::getContext();
        $shared = $ctx ? ($ctx['view_shared'] ?? []) : [];
        return $this->realView->file($path, array_merge($shared, $data), $mergeData);
    }

    public function make($view, $data = [], $mergeData = []) {
        $ctx = \Swoole\Coroutine::getContext();
        $shared = $ctx ? ($ctx['view_shared'] ?? []) : [];
        return $this->realView->make($view, array_merge($shared, $data), $mergeData);
    }

    public function share($key, $value = null) {
        $ctx = \Swoole\Coroutine::getContext();
        if ($ctx) {
            if (!isset($ctx['view_shared'])) {
                $ctx['view_shared'] = [];
            }
            if (is_array($key)) {
                $ctx['view_shared'] = array_merge($ctx['view_shared'], $key);
            } else {
                $ctx['view_shared'][$key] = $value;
            }
        }
        return $value;
    }

    public function composer($views, $callback) {
        return $this->realView->composer($views, $callback);
    }

    public function creator($views, $callback) {
        return $this->realView->creator($views, $callback);
    }

    public function addNamespace($namespace, $hints) {
        return $this->realView->addNamespace($namespace, $hints);
    }

    public function replaceNamespace($namespace, $hints) {
        return $this->realView->replaceNamespace($namespace, $hints);
    }

    public function __call($method, $args) {
        return $this->realView->$method(...$args);
    }
}

class CoroutineExtensionManager extends \Flarum\Extension\ExtensionManager {
    protected $enabledCache = null;

    public function __construct(\Flarum\Extension\ExtensionManager $realManager) {
        // 必须遍历完整继承链，否则父类的 private 属性无法被子类的 ReflectionClass 枚举到。
        // 若只 new ReflectionClass($realManager) 则只能拿到 CoroutineExtensionManager 自己声明的属性。
        $class = new \ReflectionClass($realManager);
        do {
            foreach ($class->getProperties() as $prop) {
                // 跳过自身声明的缓存属性（enabledCache），避免 parent 里同名属性覆盖掉我们的 null 初始值
                if ($prop->getDeclaringClass()->getName() === self::class) {
                    continue;
                }
                $prop->setAccessible(true);
                try {
                    $prop->setValue($this, $prop->getValue($realManager));
                } catch (\Throwable $ignored) {}
            }
        } while ($class = $class->getParentClass());
    }

    public function getEnabledExtensions() {
        if ($this->enabledCache !== null) {
            return $this->enabledCache;
        }
        return $this->enabledCache = parent::getEnabledExtensions();
    }

    public function enable($name) {
        $this->enabledCache = null;
        parent::enable($name);
    }

    public function disable($name) {
        $this->enabledCache = null;
        parent::disable($name);
    }

    public function syncExtensionOrder(): void {
        $this->enabledCache = null;
        parent::syncExtensionOrder();
    }
}


class CoroutineDbProxy implements \Illuminate\Database\ConnectionResolverInterface {
    protected $channel;
    protected $fallbackManager = null;
    private int $validateInterval;
    private array $lastValidatedAt = [];
    private const LAZY_RESOLVER = '__lazy_db_resolver__';

    public function __construct($original, int $size = 20) {
        $this->channel = new \Swoole\Coroutine\Channel($size);
        $this->validateInterval = defined('DB_CONNECTION_VALIDATE_SECONDS') ? DB_CONNECTION_VALIDATE_SECONDS : 30;

        for ($i = 0; $i < $size; $i++) {
            $this->channel->push(self::LAZY_RESOLVER);
        }
    }

    private function createResolver(): \Illuminate\Database\ConnectionResolver {
        $container = $GLOBALS['flarum_container'];
        $factory = new \Illuminate\Database\Connectors\ConnectionFactory($container);
        $dbConfig = $container->make('flarum.config')['database'];

        // 极其关键：必须强制禁用 PDO 持久连接（PDO::ATTR_PERSISTENT）。
        // 否则 PHP 底层会复用相同凭据的 C 级 Socket，导致即使创建了多个 Connection 对象，
        // 它们底层也依然共用同一个 Socket，从而引发 Swoole 跨协程 Socket 污染。
        if (!isset($dbConfig['options'])) {
            $dbConfig['options'] = [];
        }
        $dbConfig['options'][\PDO::ATTR_PERSISTENT] = false;

        $newConnection = $factory->make($dbConfig, 'flarum');

        // 将池中的连接也升级为 AutoBatchingMySqlConnection，以便激活请求合并日志和功能。
        if ($newConnection instanceof \Illuminate\Database\MySqlConnection) {
            $batchingConn = new AutoBatchingMySqlConnection(
                $newConnection->getPdo(),
                $newConnection->getDatabaseName(),
                $newConnection->getTablePrefix(),
                $newConnection->getConfig()
            );

            if ($dispatcher = $newConnection->getEventDispatcher()) {
                $batchingConn->setEventDispatcher($dispatcher);
            }

            $newConnection = $batchingConn;
        }

        $newResolver = new \Illuminate\Database\ConnectionResolver([
            'flarum' => $newConnection
        ]);
        $newResolver->setDefaultConnection('flarum');

        return $newResolver;
    }

    protected function getManager() {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid < 0) {
            // 非协程环境（workerStart 阶段）：直接创建一个临时连接用于预热/心跳，不占用池
            // 这样避免了 fallbackManager 从池里永久借走一个连接不归还的泄漏问题
            if (!$this->fallbackManager) {
                $container = $GLOBALS['flarum_container'];
                $factory = new \Illuminate\Database\Connectors\ConnectionFactory($container);
                $dbConfig = $container->make('flarum.config')['database'];
                if (!isset($dbConfig['options'])) { $dbConfig['options'] = []; }
                $dbConfig['options'][\PDO::ATTR_PERSISTENT] = false;
                $conn = $factory->make($dbConfig, 'flarum');
                $resolver = new \Illuminate\Database\ConnectionResolver(['flarum' => $conn]);
                $resolver->setDefaultConnection('flarum');
                $this->fallbackManager = $resolver;
            }
            return $this->fallbackManager;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!isset($ctx['db_manager'])) {
            // Fix #2: pop() 必须有超时，防止池耗尽时协程永久挂起
            $resolver = $this->channel->pop(5.0);
            if ($resolver === false) {
                throw new \RuntimeException('[CoroutineDbProxy] DB 连接池已耗尽，等待超时 (5s)');
            }
            if ($resolver === self::LAZY_RESOLVER) {
                try {
                    $resolver = $this->createResolver();
                } catch (\Throwable $e) {
                    $this->channel->push(self::LAZY_RESOLVER);
                    throw $e;
                }
            }

            $conn = $resolver->connection();
            $connId = spl_object_id($conn);
            $lastValidatedAt = $this->lastValidatedAt[$connId] ?? 0;
            if ($this->validateInterval <= 0 || time() - (int)$lastValidatedAt >= $this->validateInterval) {
                try {
                    $conn->getPdo()->query('SELECT 1');
                    $this->lastValidatedAt[$connId] = time();
                } catch (\Throwable $e) {
                // 旧连接已死，重建一条新连接
                    try {
                        $container = $GLOBALS['flarum_container'];
                        $factory   = new \Illuminate\Database\Connectors\ConnectionFactory($container);
                        $dbConfig  = $container->make('flarum.config')['database'];
                        if (!isset($dbConfig['options'])) { $dbConfig['options'] = []; }
                        $dbConfig['options'][\PDO::ATTR_PERSISTENT] = false;
                        $newConn = $factory->make($dbConfig, 'flarum');
                        if ($newConn instanceof \Illuminate\Database\MySqlConnection) {
                            $batching = new AutoBatchingMySqlConnection(
                                $newConn->getPdo(),
                                $newConn->getDatabaseName(),
                                $newConn->getTablePrefix(),
                                $newConn->getConfig()
                            );
                            if ($dispatcher = $newConn->getEventDispatcher()) {
                                $batching->setEventDispatcher($dispatcher);
                            }
                            $newConn = $batching;
                        }
                        $resolver = new \Illuminate\Database\ConnectionResolver(['flarum' => $newConn]);
                        $resolver->setDefaultConnection('flarum');
                        $this->lastValidatedAt[spl_object_id($newConn)] = time();
                    } catch (\Throwable $rebuildErr) {
                        // 重建也失败（MySQL 可能还在启动中），把旧 resolver 还回池，抛出异常让调用方处理
                        $this->channel->push($resolver);
                        throw new \RuntimeException('[CoroutineDbProxy] DB 连接断开且重建失败: ' . $rebuildErr->getMessage());
                    }
                }
            }

            $ctx['db_manager'] = $resolver;
            \Swoole\Coroutine\defer(function() use ($ctx) {
                if (isset($ctx['db_manager'])) {
                    try {
                        $conn = $ctx['db_manager']->connection();
                        if ($conn->transactionLevel() > 0) {
                            $conn->rollBack(0);
                        }
                        $conn->disableQueryLog();
                        $conn->flushQueryLog();
                    } catch (\Throwable $e) {}
                    $this->channel->push($ctx['db_manager']);
                }
            });
        }
        return $ctx['db_manager'];
    }

    public function heartbeat() {
        $conns = [];
        $poolSize = $this->channel->capacity;
        for ($i = 0; $i < $poolSize; $i++) {
            $resolver = $this->channel->pop(1.0);
            if ($resolver) {
                if ($resolver === self::LAZY_RESOLVER) {
                    $conns[] = $resolver;
                    continue;
                }

                try {
                    $resolver->connection()->getPdo()->query('SELECT 1');
                    $conns[] = $resolver;
                } catch (\Throwable $e) {
                    // Drop dead connection, the pool will automatically refill it when `getManager` fetches it and detects it's dead, 
                    // or we can recreate it here. For simplicity, just don't push it back and let it timeout on next pop or recreate.
                    // Wait, if we don't push it back, capacity drops. Better to push it back and let `getManager` rebuild it.
                    $conns[] = $resolver;
                }
            }
        }
        foreach ($conns as $resolver) {
            $this->channel->push($resolver);
        }
    }

    public function getRealConnection($name = null) {
        return $this->getManager()->connection($name);
    }

    public function connection($name = null) {
        // 必须直接返回 Illuminate\Database\Connection 物理单例！
        // 否则 staudenmeir/eloquent-eager-limit 等第三方库的强类型检测 (TypeError) 会直接阻断程序运行！
        // 串号问题已被我们在更底层的 PDO 替换机制（核弹级 PDO 反射注入）中完美解决！
        return $this->getRealConnection($name);
    }
    public function getDefaultConnection() { return $this->getManager()->getDefaultConnection(); }
    public function setDefaultConnection($name) { $this->getManager()->setDefaultConnection($name); }
    public function __call($method, $args) { return $this->getManager()->$method(...$args); }
}

class CoroutineRedisProxy implements \Illuminate\Contracts\Redis\Factory {
    private $originalManager;
    private array $rawConfigs = [];
    private array $pools = [];
    private int $poolSize;

    public function __construct($manager, int $poolSize = 20) {
        $this->originalManager = $manager;
        $this->poolSize = $poolSize;

        try {
            $ref = new \ReflectionClass($manager);
            if ($ref->hasProperty('config')) {
                $configProp = $ref->getProperty('config');
                $configProp->setAccessible(true);
                $managerConfig = $configProp->getValue($manager);
                $connections = $managerConfig['connections'] ?? $managerConfig;

// 处理 Flarum 可能存在的扁平化 Redis 配置数组结构
                if (isset($connections['host'])) {
                    $this->rawConfigs['default'] = $connections;
                } else {
                    foreach ($connections as $connName => $connConfig) {
                        if (is_array($connConfig)) {
                            $this->rawConfigs[$connName] = $connConfig;
                        }
                    }
                }

                $default = $managerConfig['default'] ?? 'default';
                if (isset($this->rawConfigs[$default]) && !isset($this->rawConfigs['default'])) {
                    $this->rawConfigs['default'] = $this->rawConfigs[$default];
                }
            }
        } catch (\Throwable $e) {}

// -------------------------------------------------------
// 关键修复：在构造函数里立即预热所有已知连接名的池。
// 禁止懒加载：getPool() 在首次调用时需要 I/O 建立 TCP 连接，
// 此时 Swoole 会 yield，并发的其他协程会看到 !isset(pools[name])=true，
// 各自建立新 Channel，后者覆盖前者，defer 归还到了错误的 Channel，
// 最终两个协程拿到同一 socket，触发 "Socket has already been bound" 致命错误。
// -------------------------------------------------------
        foreach (array_keys($this->rawConfigs) as $connName) {
            $channel = new \Swoole\Coroutine\Channel($this->poolSize);
            for ($i = 0; $i < $this->poolSize; $i++) {
                $channel->push($this->makeFreshPredisConnection($connName));
            }
            $this->pools[$connName] = $channel;
        }
    }

    private function makeFreshPredisConnection(string $name): \Illuminate\Redis\Connections\PredisConnection {
        $config = $this->rawConfigs[$name] ?? $this->rawConfigs['default'] ?? [];

        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 6379;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? 0;
        $timeout = $config['timeout'] ?? 2.0;
        $prefix = $config['prefix'] ?? '';

        $params = ['host' => $host, 'port' => (int)$port, 'database' => (int)$database, 'timeout' => (float)$timeout];
        if ($password !== null && $password !== '') {
            $params['password'] = $password;
        }
        if ($prefix !== '') {
            $params['prefix'] = $prefix;
        }

        $client = new \Predis\Client($params, ['exceptions' => true]);
        return new \Illuminate\Redis\Connections\PredisConnection($client);
    }

    private function isConnectionAlive($conn): bool {
        if (!$conn instanceof \Illuminate\Redis\Connections\PredisConnection) {
            return false;
        }

        try {
            $conn->client()->ping();
            return true;
        } catch (\Throwable $e) {
            try {
                $conn->client()->disconnect();
            } catch (\Throwable $ignored) {}

            return false;
        }
    }

    private function getPool(string $name): \Swoole\Coroutine\Channel {
        if (!isset($this->pools[$name])) {
            $this->pools[$name] = new \Swoole\Coroutine\Channel($this->poolSize);
            for ($i = 0; $i < $this->poolSize; $i++) {
                $this->pools[$name]->push($this->makeFreshPredisConnection($name));
            }
        }
        return $this->pools[$name];
    }

    public function connection($name = null) {
        $name = $name ?? 'default';
        $cid = \Swoole\Coroutine::getCid();

        if ($cid < 0) {
            return $this->originalManager->connection($name);
        }

        $ctx = \Swoole\Coroutine::getContext();
        $key = 'predis_conn_' . $name;

        if (!isset($ctx[$key])) {
            if (!empty($this->pools)) {
// 直接用预热好的 pool 对象（不经过 getPool()，避免任何懒加载路径）
                $pool = $this->pools[$name] ?? $this->pools['default'] ?? null;
                if ($pool === null) {
// 极端兜底：运行时出现了预热时没有的新连接名，降级走原始 manager
                    $ctx[$key] = $this->originalManager->connection($name);
                } else {
                    $conn = $pool->pop(3.0);
                    if ($conn === false) {
                        throw new \RuntimeException('[CoroutineRedisProxy] Redis 连接池已耗尽（等待 3s 超时），连接名: ' . $name);
                    }
                    if (!$this->isConnectionAlive($conn)) {
                        $conn = $this->makeFreshPredisConnection($name);
                    }
                    $ctx[$key] = $conn;
// defer 捕获 $pool 对象引用而非通过 getPool() 再取，避免任何竞态
                    \Swoole\Coroutine\defer(function() use ($pool, $ctx, $key, $name) {
                        if (isset($ctx[$key])) {
                            $conn = $ctx[$key];
                            if (!$this->isConnectionAlive($conn)) {
                                $conn = $this->makeFreshPredisConnection($name);
                            }
                            $pool->push($conn);
                        }
                    });
                }
            } else {
                $ctx[$key] = $this->originalManager->connection($name);
            }
        }

        return $ctx[$key];
    }

    public function __call($method, $args) {
        return $this->connection()->$method(...$args);
    }

    public function heartbeat() {
        foreach ($this->pools as $name => $channel) {
            $conns = [];
            $poolSize = $channel->capacity;
            for ($i = 0; $i < $poolSize; $i++) {
                $conn = $channel->pop(1.0);
                if ($conn) {
                    $conns[] = $this->isConnectionAlive($conn) ? $conn : $this->makeFreshPredisConnection($name);
                }
            }
            foreach ($conns as $conn) {
                $channel->push($conn);
            }
        }
    }
}

class ProfilingMiddleware implements \Psr\Http\Server\MiddlewareInterface {
    private string $name;
    private \Psr\Http\Server\MiddlewareInterface $inner;

    public function __construct(string $name, \Psr\Http\Server\MiddlewareInterface $inner) {
        $this->name = $name;
        $this->inner = $inner;
    }

    public function process(\Psr\Http\Message\ServerRequestInterface $request, \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface {
        $start = microtime(true);
        try {
            return $this->inner->process($request, $handler);
        } finally {
            \CoroutineSerializerConfig::recordMiddlewareTime($this->name, (microtime(true) - $start) * 1000);
        }
    }
}

// ============================================================
// 服务器配置
// ============================================================
$host = SWOOLE_LISTEN_HOST;
$port = 0;
$workerCount = SWOOLE_WORKER_COUNT;

$pidFile = '/tmp/flarum-swoole.pid';
$action = $argv[1] ?? 'start';

if ($action === 'stop') {
    if (!file_exists($pidFile)) {
        echo "[ERROR] PID 文件不存在，进程可能未在运行。\n";
        exit(1);
    }
    $pid = (int) file_get_contents($pidFile);
    if (posix_kill($pid, SIGTERM)) {
        echo "[OK] 已发送 SIGTERM 给进程 #{$pid}，服务正在停止...\n";
        @unlink($pidFile);
    } else {
        echo "[ERROR] 无法向进程 #{$pid} 发送信号，请手动执行: kill {$pid}\n";
    }
    exit(0);
}

if ($action === 'reload') {
    if (!file_exists($pidFile)) {
        echo "[ERROR] PID 文件不存在，进程可能未在运行。\n";
        exit(1);
    }
    $pid = (int) file_get_contents($pidFile);
    if (posix_kill($pid, SIGUSR1)) {
        echo "[OK] 已发送 SIGUSR1 给进程 #{$pid}，Worker 正在平滑重载...\n";
    } else {
        echo "[ERROR] 无法向进程 #{$pid} 发送信号。\n";
    }
    exit(0);
}

$server = new Server($host, 0, SWOOLE_BASE, SWOOLE_SOCK_UNIX_STREAM);

$server->set([
    'worker_num' => $workerCount,

    'open_cpu_affinity' => true, // 开启 CPU 亲和性设置
    'task_worker_num' => 0, // 未来可调整为 4 开启异步任务处理
    'max_request'           => 1000,
    'max_request_grace'     => 100, // （可选参数，防止所有 worker 同时重启，给一个波动的冗余量）
    'reload_async' => true,
    'max_wait_time' => 60,
    'enable_reuse_port' => false,
    'http_compression' => false,
    'enable_coroutine' => true, // <--- 关键：开启请求级别的协程

    'open_tcp_nodelay' => true,
    'open_tcp_keepalive' => true,
    'tcp_keepidle' => 60,
    'tcp_keepinterval' => 10,
    'tcp_keepcount' => 3,

    'buffer_output_size' => 16 * 1024 * 1024,
    'socket_buffer_size' => 512 * 1024,
    'package_max_length' => 50 * 1024 * 1024,

    'max_coroutine' => 10000,
    'stack_size' => 8 * 1024 * 1024,

    'daemonize' => false,
    'pid_file' => '/tmp/flarum-swoole.pid',
    'log_file' => WORKER_BASE_DIR . '/storage/logs/swoole.log',
    'log_level' => SWOOLE_LOG_WARNING,
]);

// ============================================================
// Worker 进程启动
// ============================================================
$server->on('workerStart', function (Server $server, int $workerId) {
    ini_set('memory_limit', PHP_MEMORY_LIMIT);

    $_SERVER['X-LSCACHE'] = 'on';
    $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed Web Server (Swoole Emulator)';

    echo "[Worker #{$workerId}] 启动中，正在加载 Flarum 应用...\n";

    try {
        $site = require WORKER_BASE_DIR . '/site.php';
        $app = $site->bootApp();

        $gateClass = null;
        if (class_exists('SwooleMemoryGate')) {
            $gateClass = 'SwooleMemoryGate';
        } else {
            foreach (get_declared_classes() as $class) {
                if (str_ends_with($class, 'SwooleMemoryGate')) {
                    $gateClass = $class;
                    break;
                }
            }
        }

        if ($gateClass && property_exists($gateClass, 'permissionsMap')) {
            $map = [];
            foreach (\Flarum\Group\Permission::get() as $p) {
                $map[$p->group_id][] = $p->permission;
            }
            $gateClass::$permissionsMap = $map;
            echo "[Worker #{$workerId}] {$gateClass} 权限表预加载完成。\n";
        } else {
            echo "[Worker #{$workerId}] ⚠️ 未找到 SwooleMemoryGate，跳过权限预加载。\n";
        }

    } catch (\Throwable $e) {
        fwrite(STDERR, "[Worker #{$workerId}] Flarum 启动失败: {$e->getMessage()}\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
        $server->stop($workerId);
        return;
    }

    $container = $app->getContainer();
    $GLOBALS['flarum_container'] = $container;
    $GLOBALS['flarum_worker_id'] = $workerId;
    $GLOBALS['flarum_handler']   = $app->getRequestHandler();

// ----------------------------------------------------------
// Post contentHtml 静态缓存：缓存 TextFormatter render 结果，actor 相关后处理仍留给 serializer mutator
// ----------------------------------------------------------
    try {
        $formatterKey = \Flarum\Formatter\Formatter::class;
        $realFormatter = $container->bound('flarum.formatter')
            ? $container->make('flarum.formatter')
            : $container->make($formatterKey);

        if (!$realFormatter instanceof CachedPostFormatter) {
            $cachedFormatter = new CachedPostFormatter($realFormatter);
            $container->instance('flarum.formatter', $cachedFormatter);
            $container->instance($formatterKey, $cachedFormatter);
            \Flarum\Post\CommentPost::setFormatter($cachedFormatter);
            echo "[Worker #{$workerId}] Post contentHtml 静态缓存代理挂载成功。\n";
        }
    } catch (\Throwable $e) {
        echo "[Worker #{$workerId}] ⚠️ Post contentHtml 缓存代理挂载失败: {$e->getMessage()}\n";
    }

// ----------------------------------------------------------
// katosdev/signature 使用独立 formatter 服务，不能被 flarum.formatter 代理覆盖。
// 这里单独包一层 render($xml) 缓存，避免 UserSerializer 每次重复渲染签名 HTML。
// ----------------------------------------------------------
    try {
        $signatureFormatterKey = \katosdev\Signature\Formatter\SignatureFormatter::class;
        if (class_exists($signatureFormatterKey) && $container->bound('katosdev-signature.formatter')) {
            $realSignatureFormatter = $container->make('katosdev-signature.formatter');

            if ($realSignatureFormatter instanceof \katosdev\Signature\Formatter\SignatureFormatter) {
                $cachedSignatureFormatter = new class($realSignatureFormatter) extends \katosdev\Signature\Formatter\SignatureFormatter {
                    private \katosdev\Signature\Formatter\SignatureFormatter $inner;

                    public function __construct(\katosdev\Signature\Formatter\SignatureFormatter $inner) {
                        $this->inner = $inner;
                    }

                    public function addConfigurationCallback($callback) { return $this->inner->addConfigurationCallback($callback); }
                    public function addParsingCallback($callback) { return $this->inner->addParsingCallback($callback); }
                    public function addUnparsingCallback($callback) { return $this->inner->addUnparsingCallback($callback); }
                    public function addRenderingCallback($callback) { return $this->inner->addRenderingCallback($callback); }
                    public function parse($text, $context = null, \Flarum\User\User $user = null) { return $this->inner->parse($text, $context, $user); }
                    public function unparse($xml, $context = null) { return $this->inner->unparse($xml, $context); }
                    public function getJs() { return $this->inner->getJs(); }

                    public function flush() {
                        \SignatureHtmlCache::clearShared();
                        return $this->inner->flush();
                    }

                    public function render($xml, $context = null, \Psr\Http\Message\ServerRequestInterface $request = null) {
                        if (!is_string($xml) || $xml === '') {
                            return $this->inner->render($xml, $context, $request);
                        }

                        $key = \SignatureHtmlCache::makeKey($xml);
                        if ($key === null) {
                            return $this->inner->render($xml, $context, $request);
                        }

                        $profileStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                        $cached = \SignatureHtmlCache::get($key);
                        if ($cached !== null) {
                            if ($profileStart > 0) {
                                \CoroutineSerializerConfig::recordPostSerializerPhase('signatureHtml.l1_hit', (microtime(true) - $profileStart) * 1000);
                            }
                            return $cached;
                        }

                        $redis = \FragmentCache::getRedis();
                        if ($redis) {
                            try {
                                $redisStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                                $cached = $redis->get(\SignatureHtmlCache::redisKey($key));
                                if (is_string($cached) && $cached !== '') {
                                    \SignatureHtmlCache::set($key, $cached);
                                    if ($redisStart > 0) {
                                        \CoroutineSerializerConfig::recordPostSerializerPhase('signatureHtml.redis_hit', (microtime(true) - $redisStart) * 1000);
                                    }
                                    return $cached;
                                }
                            } catch (\Throwable $e) {}
                        }

                        $renderStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                        $html = $this->inner->render($xml, $context, $request);
                        if ($renderStart > 0) {
                            \CoroutineSerializerConfig::recordPostSerializerPhase('signatureHtml.cache_miss_render', (microtime(true) - $renderStart) * 1000);
                        }
                        \SignatureHtmlCache::setShared($key, $html);

                        return $html;
                    }
                };

                $container->instance('katosdev-signature.formatter', $cachedSignatureFormatter);
                $container->instance($signatureFormatterKey, $cachedSignatureFormatter);
                $container->bind('katosdev-signature.formatter', fn() => $cachedSignatureFormatter);
                $container->bind($signatureFormatterKey, fn() => $cachedSignatureFormatter);
                echo "[Worker #{$workerId}] Signature HTML 静态缓存代理挂载成功。\n";
            }
        }
    } catch (\Throwable $e) {
        echo "[Worker #{$workerId}] ⚠️ Signature HTML 缓存代理挂载失败: {$e->getMessage()}\n";
    }

// ----------------------------------------------------------
// 拦截并替换视图单例为协程代理 (防止 $shared['errors'] 串号)
// ----------------------------------------------------------
    if ($container->bound('view')) {
        $realView = $container->make('view');
        $container->instance('view', new CoroutineViewProxy($realView));
        echo "[Worker #{$workerId}] 视图引擎协程代理挂载成功。\n";
    }

// ----------------------------------------------------------
// 拦截并替换 DB 和 Redis 为协程连接池代理
// ----------------------------------------------------------
    if ($container->bound('db')) {
        // 在安装代理前先保存原始实例，后续所有对原始连接的操作都用这两个变量，
        // 绝不能在安装代理后再调 $container->make('db') 或 $container->make('flarum.db')，
        // 否则会取到代理对象，导致从连接池 pop 出连接并污染其真实 PDO。
        $originalDb = $container->make('db');       // 原始 DatabaseManager
        $flarumDb   = $container->make('flarum.db'); // 原始底层 Connection 单例

        $dbProxy = new CoroutineDbProxy($originalDb, POOL_SIZE);
        $GLOBALS['coroutine_db_proxy'] = $dbProxy;
        $container->instance('db', $dbProxy);
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($dbProxy);

        // flarum.db 绑定到原始单例，不能绑池连接！
        // 池连接的 PDO 必须是真实 PDO，proxyPdo 只装在原始单例上。
        $container->instance('flarum.db', $flarumDb);
        $container->instance(\Illuminate\Database\ConnectionInterface::class, $flarumDb);

        // proxyPdo：兜底拦截层，用于那些绕过 CoroutineDbProxy 直接持有旧单例引用的代码。
        // proxyPdo::getRealPdo() → 取当前协程 ctx 持有的池连接（真实 PDO）→ 无递归。
        $proxyPdo = new class extends \PDO {
            public function __construct() {}
            private function getRealPdo() {
                if (isset($GLOBALS['coroutine_db_proxy'])) {
                    return $GLOBALS['coroutine_db_proxy']->getRealConnection()->getPdo();
                }
                return $GLOBALS['flarum_container']->make('db')->connection()->getPdo();
            }
            public function prepare($statement, $options = []) { return $this->getRealPdo()->prepare($statement, $options ?: []); }
            public function beginTransaction() { return $this->getRealPdo()->beginTransaction(); }
            public function commit() { return $this->getRealPdo()->commit(); }
            public function rollBack() { return $this->getRealPdo()->rollBack(); }
            public function inTransaction() { return $this->getRealPdo()->inTransaction(); }
            public function setAttribute($attribute, $value) { return $this->getRealPdo()->setAttribute($attribute, $value); }
            public function exec($statement) { return $this->getRealPdo()->exec($statement); }
            public function query(...$args) { return $this->getRealPdo()->query(...$args); }
            public function lastInsertId($name = null) { return $this->getRealPdo()->lastInsertId($name); }
            public function errorCode() { return $this->getRealPdo()->errorCode(); }
            public function errorInfo() { return $this->getRealPdo()->errorInfo(); }
            public function getAttribute($attribute) { return $this->getRealPdo()->getAttribute($attribute); }
            public function quote($string, $paramtype = \PDO::PARAM_STR) { return $this->getRealPdo()->quote($string, $paramtype); }
        };

        // 将 proxyPdo 安装到原始单例（$flarumDb 和 $originalDb 内部缓存的连接）
        foreach ([$flarumDb, $originalDb->connection()] as $connToWrap) {
            $r = new \ReflectionClass($connToWrap);
            foreach (['pdo', 'readPdo'] as $prop) {
                if ($r->hasProperty($prop)) {
                    $p = $r->getProperty($prop);
                    $p->setAccessible(true);
                    $p->setValue($connToWrap, $proxyPdo);
                }
            }
        }

        // 替换 DatabaseManager 内部的 ConnectionFactory，确保之后通过 $originalDb 新建的连接
        // 都是 AutoBatchingMySqlConnection，且装有 proxyPdo（因为是通过 $originalDb 路径来的）。
        $dbReflection = new \ReflectionClass($originalDb);
        if ($dbReflection->hasProperty('factory')) {
            $factoryProp = $dbReflection->getProperty('factory');
            $factoryProp->setAccessible(true);
            $originalFactory = $factoryProp->getValue($originalDb);

            $proxyFactory = new class($originalFactory, $proxyPdo) {
                private $factory;
                private $proxyPdo;
                public function __construct($factory, $proxyPdo) {
                    $this->factory   = $factory;
                    $this->proxyPdo  = $proxyPdo;
                }
                public function make($config, $name = null) {
                    $conn = $this->factory->make($config, $name);
                    if ($conn instanceof \Illuminate\Database\MySqlConnection) {
                        $newConn = new AutoBatchingMySqlConnection(
                            $conn->getPdo(),
                            $conn->getDatabaseName(),
                            $conn->getTablePrefix(),
                            $conn->getConfig()
                        );
                        if ($dispatcher = $conn->getEventDispatcher()) {
                            $newConn->setEventDispatcher($dispatcher);
                        }
                        $r = new \ReflectionClass($newConn);
                        if ($r->hasProperty('pdo')) {
                            $p = $r->getProperty('pdo');
                            $p->setAccessible(true);
                            $p->setValue($newConn, $this->proxyPdo);
                        }
                        return $newConn;
                    }
                    return $conn;
                }
                public function __call($method, $args) { return $this->factory->$method(...$args); }
            };

            $factoryProp->setValue($originalDb, $proxyFactory);
        }

        echo "[Worker #{$workerId}] DB 引擎协程池代理挂载成功 (包含 AutoBatching 升级 & PDO 拦截)。\n";
    }

// FoF Redis 不在 'redis' 键下注册，而是注册为全限定类名和接口别名。
// 我们需要探测正确的键并进行替换。
    $redisKeys = [
        'FoF\Redis\Overrides\RedisManager',
        'Illuminate\Contracts\Redis\Factory',
        'redis'
    ];
    $redisManager = null;
    $foundKey = null;

    foreach ($redisKeys as $key) {
        if ($container->bound($key)) {
            try {
                $redisManager = $container->make($key);
                $foundKey = $key;
                break;
            } catch (\Throwable $e) {}
        }
    }

    if ($redisManager) {
        try {
            $redisProxy = new CoroutineRedisProxy($redisManager, POOL_SIZE);

// 检查 rawConfigs 是否提取成功，失败则输出警告
            $ref = new \ReflectionClass($redisProxy);
            $rcProp = $ref->getProperty('rawConfigs');
            $rcProp->setAccessible(true);
            $rawCfgs = $rcProp->getValue($redisProxy);
            if (empty($rawCfgs)) {
                echo "[Worker #{$workerId}] ⚠️ Redis rawConfigs 提取失败，降级为原始 manager（可能存在协程竞争）。\n";
            } else {
                echo "[Worker #{$workerId}] Redis 协程代理挂载成功（连接名: " . implode(',', array_keys($rawCfgs)) . "）。\n";
            }

// 只替换接口和别名绑定，保留具体类绑定以防止强类型注入失败
// （如 FoF\Horizon\AdminContent 构造函数要求 FoF\Redis\Overrides\RedisManager 具体类型）
            $interfaceKeysOnly = [
                'Illuminate\Contracts\Redis\Factory',
                'redis',
            ];
            foreach ($interfaceKeysOnly as $key) {
                if ($container->bound($key)) {
                    $container->instance($key, $redisProxy);
                }
            }
        } catch (\Throwable $e) {
            echo "[Worker #{$workerId}] ❌ Redis 代理安装失败: {$e->getMessage()}\n";
        }
    } else {
        echo "[Worker #{$workerId}] ℹ️ 容器中未找到 Redis 服务，跳过代理安装。\n";
    }

// 清除由于启动时可能已经实例化的依赖组件，强制它们使用新的 db 和 redis 代理
    $container->forgetInstance('cache');
    $container->forgetInstance('cache.store');
    $container->forgetInstance('session');
    $container->forgetInstance('session.store');
    $container->forgetInstance('session.handler');
    $container->forgetInstance('queue');
    $container->forgetInstance('queue.connection');
    $container->forgetInstance('flarum.queue.connection');

// ----------------------------------------------------------
// 拦截并替换 ExtensionManager 解决 JSON 解析性能瓶颈
// ----------------------------------------------------------
// 【修复】部分插件（如 katosdev/signature）的 ServiceProvider 用 bind() 注册工厂闭包，
// 在 forgetInstance + getRequestHandler() 重建后会绕过 instance() 走 DI 自动注入，
// 此时若注入的对象不继承 ExtensionManager 则触发 TypeError。
// 修复策略：
//   1. instance() 注入代理（最高优先级覆盖）
//   2. 同时用 bind() 固化，防止 forgetInstance 后回退到旧 binding
//   3. 通过反射遍历容器所有 alias，对所有指向 ExtensionManager 的别名也做 instance() 覆盖
    $extManagerKey = \Flarum\Extension\ExtensionManager::class;
    if ($container->bound($extManagerKey)) {
        $realExtManager = $container->make($extManagerKey);
        $extManagerProxy = new CoroutineExtensionManager($realExtManager);

        // 主键注入
        $container->instance($extManagerKey, $extManagerProxy);

        // bind() 固化：即使 forgetInstance 被调用后重新 resolve，也返回同一个对象
        $container->bind($extManagerKey, function() use ($extManagerProxy) {
            return $extManagerProxy;
        });

        // 遍历容器内所有别名，把指向 ExtensionManager 的别名也覆盖掉
        try {
            $containerRef = new \ReflectionClass($container);
            if ($containerRef->hasProperty('aliases')) {
                $aliasesProp = $containerRef->getProperty('aliases');
                $aliasesProp->setAccessible(true);
                foreach ($aliasesProp->getValue($container) as $alias => $abstract) {
                    if ($abstract === $extManagerKey && $alias !== $extManagerKey) {
                        $container->instance($alias, $extManagerProxy);
                    }
                }
            }
        } catch (\Throwable $ignored) {}

        echo "[Worker #{$workerId}] 扩展管理器代理挂载成功 (已开启缓存 + bind 固化 + 别名覆盖).\n";
    }

// 必须清除 Middleware 管道缓存，否则 StartSession 依然持有旧的单例
    $container->forgetInstance('flarum.api.middleware');
    $container->forgetInstance('flarum.forum.middleware');
    $container->forgetInstance('flarum.admin.middleware');
    $container->forgetInstance('flarum.api.handler');
    $container->forgetInstance('flarum.forum.handler');
    $container->forgetInstance('flarum.admin.handler');

// 重新构建 Handler 以让所有的中间件获取到代理后的单例
    $GLOBALS['flarum_handler'] = $app->getRequestHandler();

// ----------------------------------------------------------
// LSCache 兼容层：Redis 连接池
// ----------------------------------------------------------
    try {
        $redisSettings = [];
        $extendFilePath = WORKER_BASE_DIR . '/extend.php';

        if (file_exists($extendFilePath)) {
            $extenders = require $extendFilePath;
            if (is_array($extenders)) {
                foreach ($extenders as $extender) {
                    if (is_object($extender) && str_ends_with(get_class($extender), 'Redis\Extend\Redis')) {
                        $ref = new \ReflectionClass($extender);
                        if ($ref->hasProperty('configuration')) {
                            $prop = $ref->getProperty('configuration');
                            $prop->setAccessible(true);
                            $configObj = $prop->getValue($extender);
                            if (is_object($configObj)) {
                                $configRef = new \ReflectionClass($configObj);
                                if ($configRef->hasProperty('config')) {
                                    $innerProp = $configRef->getProperty('config');
                                    $innerProp->setAccessible(true);
                                    $val = $innerProp->getValue($configObj);
                                    if (is_array($val) && (isset($val['path']) || isset($val['host']) || isset($val['database']))) {
                                        $redisSettings = $val;
                                        if ($configRef->hasProperty('databases')) {
                                            $dbProp = $configRef->getProperty('databases');
                                            $dbProp->setAccessible(true);
                                            $databases = $dbProp->getValue($configObj) ?: [];
                                            if (isset($databases['session'])) {
                                                $redisSettings['session_database'] = $databases['session'];
                                            }
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (empty($redisSettings)) {
            echo "[Worker #{$workerId}] 致命错误：在 extend.php 中未提取到 FoF\Redis 配置！\n";
            throw new \Exception("提取 Redis 配置失败，服务启动终止。");
        }

        $baseDatabase = (int)($redisSettings['database'] ?? 0);
        $sessionDatabase = isset($redisSettings['session_database']) ? (int)$redisSettings['session_database'] : null;
        $lscacheDatabase = resolveRedisDatabase(
            $redisSettings,
            LSCACHE_REDIS_DATABASE,
            [$baseDatabase, $sessionDatabase]
        );
        $fragmentCacheDatabase = resolveRedisDatabase(
            $redisSettings,
            FRAGMENT_CACHE_REDIS_DATABASE,
            [$baseDatabase, $sessionDatabase, $lscacheDatabase]
        );

        $lscacheRedisSettings = redisSettingsForDatabase($redisSettings, $lscacheDatabase);
        $swooleRedisPool = createPredisPool($lscacheRedisSettings, POOL_SIZE);
        $GLOBALS['swoole_redis_pool'] = $swooleRedisPool;
        $GLOBALS['swoole_redis_settings'] = $lscacheRedisSettings;
        $GLOBALS['swoole_lscache_redis_database'] = $lscacheDatabase;

        $fragmentCacheRedisSettings = redisSettingsForDatabase($redisSettings, $fragmentCacheDatabase);
        $fragmentCacheRedisPool = createPredisPool($fragmentCacheRedisSettings, POOL_SIZE);
        $GLOBALS['fragment_cache_redis_pool'] = $fragmentCacheRedisPool;
        $GLOBALS['fragment_cache_redis_settings'] = $fragmentCacheRedisSettings;
        $GLOBALS['fragment_cache_redis_database'] = $fragmentCacheDatabase;

        if (isset($redisSettings['session_database'])) {
            $sessionRedisSettings = redisSettingsForDatabase($redisSettings, (int)$redisSettings['session_database']);
            $swooleSessionRedisPool = createPredisPool($sessionRedisSettings, POOL_SIZE);
            $GLOBALS['swoole_session_redis_pool'] = $swooleSessionRedisPool;
            $GLOBALS['swoole_session_redis_settings'] = $sessionRedisSettings;
        } else {
            $GLOBALS['swoole_session_redis_pool'] = null;
            $GLOBALS['swoole_session_redis_settings'] = null;
        }

        echo "[Worker #{$workerId}] Redis 独立库: lscache={$lscacheDatabase}, fragment_cache={$fragmentCacheDatabase}, base={$baseDatabase}" . ($sessionDatabase !== null ? ", session={$sessionDatabase}" : "") . "。\n";

    } catch (\Throwable $e) {
        echo "[Worker #{$workerId}] LSCache: Redis 连接异常: {$e->getMessage()}\n";
    }

// ----------------------------------------------------------
// 提前预热 Flarum 引擎，消除首个真实请求的冷启动开销
// (必须在 DB/Redis 代理池完全初始化之后执行！)
// ----------------------------------------------------------
    \Swoole\Coroutine::create(function() use ($workerId) {
        try {
            $warmupUri = new \Laminas\Diactoros\Uri('http://localhost/api/discussions');
            $warmupRequest = new \Laminas\Diactoros\ServerRequest(
                [], [], $warmupUri, 'GET', 'php://temp',
                ['Accept' => ['application/json']]
            );

            // 模拟提供 Redis 池连接到上下文，防止内部逻辑报错
            $ctx = \Swoole\Coroutine::getContext();
            if (isset($GLOBALS['swoole_redis_pool'])) {
                $ctx['lscache_redis'] = $GLOBALS['swoole_redis_pool']->pop(1);
                if ($ctx['lscache_redis']) {
                    \Swoole\Coroutine\defer(function() use ($ctx) {
                        $GLOBALS['swoole_redis_pool']->push($ctx['lscache_redis']);
                    });
                }
            }
            if (isset($GLOBALS['fragment_cache_redis_pool'])) {
                $ctx['fragment_cache_redis'] = $GLOBALS['fragment_cache_redis_pool']->pop(1);
                if ($ctx['fragment_cache_redis']) {
                    \Swoole\Coroutine\defer(function() use ($ctx) {
                        $GLOBALS['fragment_cache_redis_pool']->push($ctx['fragment_cache_redis']);
                    });
                }
            }

            $GLOBALS['flarum_handler']->handle($warmupRequest);
            echo "[Worker #{$workerId}] Flarum 引擎预热完毕 (冷启动已消除)。\n";
        } catch (\Throwable $e) {
            echo "[Worker #{$workerId}] ⚠️ 预热遇到错误(通常可忽略): {$e->getMessage()}\n";
        }
    });

// ----------------------------------------------------------
// DB/Redis 保活定时器
// ----------------------------------------------------------
    \Swoole\Timer::tick((int)(DB_HEARTBEAT_HOURS * 3600 * 1000), function () use ($workerId) {
        \Swoole\Coroutine::create(function() use ($workerId) {
            try {
                if (isset($GLOBALS['coroutine_db_proxy'])) {
                    $GLOBALS['coroutine_db_proxy']->heartbeat();
                } else {
                    $GLOBALS['flarum_container']->make('db')->connection()->select('SELECT 1');
                }
            } catch (\Throwable $e) {
                error_log("[Worker #{$workerId}] DB 心跳失败: " . $e->getMessage());
            }

            try {
                $redisKeys = ['Illuminate\Contracts\Redis\Factory', 'redis'];
                foreach ($redisKeys as $key) {
                    if ($GLOBALS['flarum_container']->bound($key)) {
                        $manager = $GLOBALS['flarum_container']->make($key);
                        if ($manager instanceof CoroutineRedisProxy) {
                            $manager->heartbeat();
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("[Worker #{$workerId}] Flarum Redis 心跳失败: " . $e->getMessage());
            }

            try {
                $pingSwooleRedisPool = function($pool) {
                    $conns = [];
                    $poolSize = $pool->capacity;
                    for ($i = 0; $i < $poolSize; $i++) {
                        $conn = $pool->pop(1.0);
                        if ($conn) {
                            try { $conn->ping(); } catch (\Throwable $e) {}
                            $conns[] = $conn;
                        }
                    }
                    foreach ($conns as $conn) {
                        $pool->push($conn);
                    }
                };

                if (isset($GLOBALS['swoole_redis_pool'])) {
                    $pingSwooleRedisPool($GLOBALS['swoole_redis_pool']);
                }
                if (isset($GLOBALS['swoole_session_redis_pool']) && $GLOBALS['swoole_session_redis_pool'] !== ($GLOBALS['swoole_redis_pool'] ?? null)) {
                    $pingSwooleRedisPool($GLOBALS['swoole_session_redis_pool']);
                }
                if (isset($GLOBALS['fragment_cache_redis_pool'])) {
                    $pingSwooleRedisPool($GLOBALS['fragment_cache_redis_pool']);
                }
            } catch (\Throwable $e) {
                error_log("[Worker #{$workerId}] LSCache Redis 心跳失败: " . $e->getMessage());
            }
        });
    });

    $recycleInterval = (int)(WORKER_RECYCLE_HOURS * 3600 * 1000);
    $recycleOffset = $workerId * 180 * 1000;
    \Swoole\Timer::after($recycleOffset, function () use ($server, $workerId, $recycleInterval) {
        \Swoole\Timer::tick($recycleInterval, function () use ($server, $workerId) {
            echo "[Worker #{$workerId}] [" . date('H:i:s') . "] 定时清理碎片...\n";
            $server->stop($workerId);
        });
    });

    echo "[Worker #{$workerId}] Flarum 加载成功(协程模式)。\n";
});

// ============================================================
// 处理每个 HTTP 请求
// ============================================================
$server->on('request', function (SwooleRequest $swooleReq, SwooleResponse $swooleRes) use ($server) {
    $startTime = microtime(true);
    $ctx = \Swoole\Coroutine::getContext();
    $ctx['request_id'] = bin2hex(random_bytes(8));
    $ctx['root_request_id'] = $ctx['request_id'];
    $ctx['route_profile_request_id'] = $ctx['request_id'];
    $ctx['serialization_ms'] = 0.0;
    $ctx['serializer_resource_times'] = [];
    $ctx['post_html_prefetch'] = [];

// 请求结束时自动清理 AutoBatching 状态，防止跨请求污染
    \Swoole\Coroutine\defer(function () {
        AutoBatchingDataLoader::cleanup();
    });

    if (isset($GLOBALS['swoole_redis_pool'])) {
        $poolStart = microtime(true);
        $conn = $GLOBALS['swoole_redis_pool']->pop(3.0);
        \CoroutineSerializerConfig::recordPostSerializerPhase('redisPool.lscache_pop', (microtime(true) - $poolStart) * 1000);
        if ($conn !== false) {
            if (!isPredisClientAlive($conn)) {
                $conn = createFreshPredisClient($GLOBALS['swoole_redis_settings']);
            }
            $ctx['lscache_redis'] = $conn;
            \Swoole\Coroutine\defer(function() use ($ctx) {
                $conn = $ctx['lscache_redis'];
                if (!isPredisClientAlive($conn)) {
                    $conn = createFreshPredisClient($GLOBALS['swoole_redis_settings']);
                }
                $GLOBALS['swoole_redis_pool']->push($conn);
            });
        }
        // pop() 返回 false 说明池已耗尽，降级处理：跳过缓存读取，直接走 Flarum
    }

    if (isset($GLOBALS['swoole_session_redis_pool'])) {
        $poolStart = microtime(true);
        $conn = $GLOBALS['swoole_session_redis_pool']->pop(3.0);
        \CoroutineSerializerConfig::recordPostSerializerPhase('redisPool.session_pop', (microtime(true) - $poolStart) * 1000);
        if ($conn !== false) {
            if (!isPredisClientAlive($conn)) {
                $conn = createFreshPredisClient($GLOBALS['swoole_session_redis_settings']);
            }
            $ctx['lscache_session_redis'] = $conn;
            \Swoole\Coroutine\defer(function() use ($ctx) {
                $conn = $ctx['lscache_session_redis'];
                if (!isPredisClientAlive($conn)) {
                    $conn = createFreshPredisClient($GLOBALS['swoole_session_redis_settings']);
                }
                $GLOBALS['swoole_session_redis_pool']->push($conn);
            });
        }
    }

    if (isset($GLOBALS['fragment_cache_redis_pool'])) {
        $poolStart = microtime(true);
        $conn = $GLOBALS['fragment_cache_redis_pool']->pop(3.0);
        \CoroutineSerializerConfig::recordPostSerializerPhase('redisPool.fragment_pop', (microtime(true) - $poolStart) * 1000);
        if ($conn !== false) {
            if (!isPredisClientAlive($conn)) {
                $conn = createFreshPredisClient($GLOBALS['fragment_cache_redis_settings']);
            }
            $ctx['fragment_cache_redis'] = $conn;
            \Swoole\Coroutine\defer(function() use ($ctx) {
                $conn = $ctx['fragment_cache_redis'];
                if (!isPredisClientAlive($conn)) {
                    $conn = createFreshPredisClient($GLOBALS['fragment_cache_redis_settings']);
                }
                $GLOBALS['fragment_cache_redis_pool']->push($conn);
            });
        }
    }

    $method = strtoupper($swooleReq->server['request_method'] ?? 'GET');
    $uri = $swooleReq->server['request_uri'] ?? '/';

    if ($uri === '/__swoole/fragment-cache/purge') {
        if ($method !== 'POST') {
            $swooleRes->status(405);
            $swooleRes->header('Allow', 'POST');
            $swooleRes->end('Method Not Allowed');
            return;
        }

        $expectedToken = defined('FRAGMENT_CACHE_PURGE_TOKEN') ? (string)FRAGMENT_CACHE_PURGE_TOKEN : '';
        $providedToken = (string)($swooleReq->header['x-swoole-purge-token'] ?? ($swooleReq->get['token'] ?? ''));
        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $swooleRes->status(403);
            $swooleRes->header('Content-Type', 'application/json; charset=utf-8');
            $swooleRes->end(json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE));
            return;
        }

        $params = is_array($swooleReq->post ?? null) ? $swooleReq->post : [];
        $raw = method_exists($swooleReq, 'rawContent') ? (string)$swooleReq->rawContent() : '';
        if ($raw !== '') {
                $decoded = \swoole_fast_json_decode($raw, true);
            if (is_array($decoded)) {
                $params = array_merge($params, $decoded);
            }
        }
        if (is_array($swooleReq->get ?? null)) {
            $params = array_merge($swooleReq->get, $params);
        }

        $parseIds = static function ($value): array {
            if ($value === null || $value === '') {
                return [];
            }
            if (!is_array($value)) {
                $value = preg_split('/[,\s]+/', (string)$value) ?: [];
            }

            $ids = [];
            foreach ($value as $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }

            return array_values($ids);
        };

        $postIds = array_values(array_unique(array_merge(
            $parseIds($params['post'] ?? null),
            $parseIds($params['posts'] ?? null)
        )));
        $userIds = array_values(array_unique(array_merge(
            $parseIds($params['user'] ?? null),
            $parseIds($params['users'] ?? null)
        )));

        $deleted = 0;
        foreach ($postIds as $postId) {
            $deleted += \PostHtmlCache::forgetPostShared($postId);
        }
        foreach ($userIds as $userId) {
            $deleted += \UserHtmlCache::forgetUserShared($userId);
        }

        $swooleRes->header('Content-Type', 'application/json; charset=utf-8');
        $swooleRes->end(json_encode([
            'ok' => true,
            'posts' => $postIds,
            'users' => $userIds,
            'redis_deleted' => $deleted,
        ], JSON_UNESCAPED_UNICODE));
        return;
    }

    $isDoc = in_array($method, ['GET', 'HEAD'], true)
        && !str_contains($uri, '.')
        && !str_starts_with($uri, '/api')
        && !str_starts_with($uri, '/webmanifest');

    $linkHeaderString = '';
 
    if ($isDoc) {
        $assets = getEarlyHintsAssets($uri);
        if (!empty($assets)) {
            $headerLinks103 = [];
            $headerLinksMain = [];
            foreach ($assets as $asset) {
                $n = $asset[0];
                $h = $asset[1];
                $type = str_ends_with($n, '.js') ? 'script' : 'style';
                $headerLinks103[] = "Link: <" . CDN_PREFIX . "/assets/{$n}?v={$h}>; rel=preload; as={$type}";
                $headerLinksMain[] = "<" . CDN_PREFIX . "/assets/{$n}?v={$h}>; rel=preload; as={$type}";
            }

            $linkHeaderString = implode(', ', $headerLinksMain);

            if (!empty($headerLinks103)) {
                $earlyHints = "HTTP/1.1 103 Early Hints\r\n" . implode("\r\n", $headerLinks103) . "\r\n\r\n";
                $server->send($swooleRes->fd, $earlyHints);
                // if (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') {
                //     error_log("[Debug] Early Hints sent for: {$uri}");
                // }
            }
        }
    }
    

    $isGuest = true;
    if (isset($swooleReq->header['authorization'])) {
        // API Token 鉴权（直接放行，不走缓存）
        $isGuest = false;
    } elseif (!empty($swooleReq->cookie['flarum_remember'])) {
        // Fix #9: flarum_remember 可能已过期，保守处理：检查数据库
        // RememberFromCookie 中间件会在首次携带此 cookie 时写入 session 的 access_token
        // 由于我们此时还没有 session，直接信任 cookie 存在但不走缓存
        $isGuest = false;
    } elseif (!empty($swooleReq->cookie['flarum_session']) && isset($ctx['lscache_session_redis'])) {
        $sessionId = $swooleReq->cookie['flarum_session'];
        $redis = $ctx['lscache_session_redis'];

        // Fix #6: fof/redis 使用 CacheBasedSessionHandler，写入 key 就是裸 session ID（无前缀）
        // 值是 PHP serialize() 后的字符串，内容是 Laravel session 数组
        $sessionRedisStart = microtime(true);
        $sessionData = $redis->get($sessionId);
        \CoroutineSerializerConfig::recordPostSerializerPhase('redis.session_get_cmd', (microtime(true) - $sessionRedisStart) * 1000);

        if ($sessionData) {
            $sessionParseStart = microtime(true);
            // fof/redis 写入的格式是 s:N:"a:..."; （外层 serialize(string)）
            // 需要先 unserialize 一次拿到内层序列化字符串，再 unserialize 一次拿到 PHP array
            $inner = @unserialize($sessionData);
            if (is_string($inner)) {
                $parsed = @unserialize($inner);
            } else {
                $parsed = $inner; // 兼容某些版本直接存 array 的情况
            }
            \CoroutineSerializerConfig::recordPostSerializerPhase('redis.session_parse:' . strlen((string)$sessionData), (microtime(true) - $sessionParseStart) * 1000);

            // Flarum 在用户登录时（SessionAuthenticator::logIn）会把 access_token 写入 session
            // 游客 session 只有 _token（CSRF）和 _flash，没有 access_token
            $accessToken = is_array($parsed) ? ($parsed['access_token'] ?? null) : null;

            if ($accessToken) {
                // WorkerCache: access_token 查询结果缓存 60 秒。
                // token 只在登录/登出时变化，60s TTL 完全安全，每个 worker 节省一次 DB 查询。
                $cacheKey = 'at:' . $accessToken;
                $tokenRow = WorkerCache::get($cacheKey);
                if ($tokenRow === null) {
                    try {
                        $db = $GLOBALS['flarum_container']->make('db')->connection();
                        $tokenRow = $db->table('access_tokens')->where('token', $accessToken)->first();
                        // false 表示「查过了，不存在」，区别于「还没查过」的 null
                        WorkerCache::set($cacheKey, $tokenRow ?? false, 60);
                    } catch (\Throwable $e) {
                        $tokenRow = false;
                        error_log('[Worker] Session DB Check Error: ' . $e->getMessage());
                    }
                }
                if ($tokenRow && !empty($tokenRow->user_id)) {
                    $isGuest = false;
                    $ctx['prefetched_access_token'] = $tokenRow;
                }
            } elseif (is_array($parsed) && isset($parsed['_token'])) {
                // session 存在但没有 access_token → 这是游客的 session（只有 CSRF token）
                // $isGuest 保持 true，正确走缓存
            } else {
                // 无法解析 session → 保守处理，不走缓存
                $isGuest = false;
            }
        }
    }

    if (LSCACHE_ENABLED && $isGuest && in_array($method, ['GET', 'HEAD']) && isset($ctx['lscache_redis']) && !isset($swooleReq->get['uptime_kuma_cachebuster'])) {
        try {
            $redis = $ctx['lscache_redis'];
            $cacheKey = buildLSCacheKey($swooleReq);
            $lscacheGetStart = microtime(true);
            $cached = $redis->get($cacheKey);
            \CoroutineSerializerConfig::recordPostSerializerPhase('redis.lscache_get_cmd', (microtime(true) - $lscacheGetStart) * 1000);

            if ($cached) {
                $lscacheDecodeStart = microtime(true);
                $cachedData = \swoole_fast_json_decode($cached, true);
                \CoroutineSerializerConfig::recordPostSerializerPhase('redis.lscache_decode:' . strlen((string)$cached), (microtime(true) - $lscacheDecodeStart) * 1000);
                if ($cachedData && isset($cachedData['headers'], $cachedData['body'], $cachedData['status'])) {
                    $swooleRes->status($cachedData['status']);
                    foreach ($cachedData['headers'] as $k => $v) {
                        $swooleRes->header($k, $v);
                    }
                    $swooleRes->header('X-Swoole-LSCache', 'HIT');
                    if ($linkHeaderString !== '') {
                        $swooleRes->header('Link', $linkHeaderString);
                    }
                    $swooleRes->end($cachedData['body']);
                    logRequest($swooleReq, $cachedData['status'], microtime(true) - $startTime);
                    return;
                }
            }
        } catch (\Throwable $e) {}
    }

    $requestUri = $swooleReq->server['request_uri'] ?? '/';
    if (str_starts_with($requestUri, '/assets/')) {
        $filePath = WORKER_BASE_DIR . '/public' . $requestUri;
        $realPath = realpath($filePath);
        $assetDir = realpath(WORKER_BASE_DIR . '/public/assets');
        if ($realPath !== false && str_starts_with($realPath, $assetDir) && is_file($realPath)) {
            $swooleRes->sendfile($realPath);
            logRequest($swooleReq, 200, microtime(true) - $startTime);
            return;
        }
    }

    // Fix #1/#5: DB 代理已在 workerStart 中完整挂载，请求处理时无需重复绑定。
    // 重复调用 container->instance() 和 Model::setConnectionResolver() 在高并发下会引入不必要的全局状态竞争。

    try {
        $psrRequest = buildPsr7Request($swooleReq);
    } catch (\Throwable $e) {
        $swooleRes->status(400);
        $swooleRes->end('Bad Request: ' . $e->getMessage());
        return;
    }

    try {
        $isDebug = defined('LOG_LEVEL') && LOG_LEVEL === 'debug';

        if ($isDebug) {
            // ---- Profiling: 通过 Flarum 序列化器容器获取 DB 连接 ----
            $_dbResolver = \Flarum\Api\Serializer\AbstractSerializer::getContainer()->make('db');
            $_db = $_dbResolver->connection();
            $_db->enableQueryLog();
            $_handleStart = microtime(true);
            $_serializationBefore = 0.0;
            $_serializationMs = 0.0;
            $_serializerTop = [];
        }

        try {
            if (empty($GLOBALS['flarum_handler'])) {
                $swooleRes->status(503);
                $swooleRes->header('Retry-After', '2');
                $swooleRes->end('Service Unavailable: worker initializing');
                return;
            }
            $psrResponse = $GLOBALS['flarum_handler']->handle($psrRequest);
        } finally {
            if ($isDebug) {
                // 无论是否异常都清理 query log，防止连接回池时带着开启的日志导致内存增长
                $_handleTime = (microtime(true) - $_handleStart) * 1000;
                $_queries = $_db->getQueryLog();
                $_serializationMs = $ctx['serialization_ms'] ?? 0.0;
                $_serializationBefore = max(0.0, $_handleTime - $_serializationMs);
                $_serializerTop = \CoroutineSerializerConfig::pullResourceTimes($ctx['request_id'] ?? '');
                $_postSerializerProfile = \CoroutineSerializerConfig::pullPostSerializerProfile($ctx['request_id'] ?? '');
                $_middlewareProfile = \CoroutineSerializerConfig::pullMiddlewareProfile($ctx['request_id'] ?? '');
                $_beforeSerializationProfile = \CoroutineSerializerConfig::pullBeforeSerializationProfile($ctx['request_id'] ?? '');
                $_db->disableQueryLog();
                $_db->flushQueryLog();
            }
        }

        if ($isDebug) {
            // ---- Profiling: 输出查询统计 ----
            $_queryCount = count($_queries);
            $_queryTotalMs = 0;
            foreach ($_queries as $_q) { $_queryTotalMs += $_q['time'] ?? 0; }

            if ($_handleTime > 30) {
                $_phpTime = $_handleTime - $_queryTotalMs;
                
                $_bsCtrlStart = $ctx["bs_ctrl_start"] ?? 0;
                $_bsDataStart = $ctx["bs_data_start"] ?? 0;
                $_bsDataEnd   = $ctx["bs_data_end"] ?? 0;
                $_bsBuildStart= $ctx["bs_build_start"] ?? 0;
                $_bsBuildEnd  = $ctx["bs_build_end"] ?? 0;
                
                $_tRouteMid = $_bsCtrlStart ? ($_bsCtrlStart - $_handleStart) * 1000 : 0;
                $_tData     = $_bsDataStart ? ($_bsDataEnd - $_bsDataStart) * 1000 : 0;
                $_tBuild    = $_bsBuildStart ? ($_bsBuildEnd - $_bsBuildStart) * 1000 : 0;
                
                error_log(sprintf('[Profile] %s %s -> handle=%dms before_serialization=%dms (route_mid=%dms data=%dms build=%dms) serialization=%dms sql=%dms(x%d) php=%dms',
                    $swooleReq->server['request_method'] ?? 'GET',
                    $requestUri,
                    (int)$_handleTime,
                    (int)$_serializationBefore,
                    (int)$_tRouteMid,
                    (int)$_tData,
                    (int)$_tBuild,
                    (int)$_serializationMs,
                    (int)$_queryTotalMs,
                    $_queryCount,
                    (int)$_phpTime
                ));
                if (!empty($_serializerTop)) {
                    uasort($_serializerTop, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
                    $_rank = 1;
                    foreach (array_slice($_serializerTop, 0, 5, true) as $_type => $_stat) {
                        error_log(sprintf('  [Serializer#%d] %dms x%d %s',
                            $_rank++,
                            (int)($_stat['time'] ?? 0),
                            (int)($_stat['count'] ?? 0),
                            $_type
                        ));
                    }
                }
                if (!empty($_postSerializerProfile)) {
                    uasort($_postSerializerProfile, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
                    $_rank = 1;
                    foreach (array_slice($_postSerializerProfile, 0, 12, true) as $_phase => $_stat) {
                        error_log(sprintf('  [PostSerializer#%d] %dms x%d max=%dms %s sample:%s',
                            $_rank++,
                            (int)($_stat['time'] ?? 0),
                            (int)($_stat['count'] ?? 0),
                            (int)($_stat['max'] ?? 0),
                            $_phase,
                            $_stat['sample'] ?? ''
                        ));
                    }
                }
                if (!empty($_middlewareProfile)) {
                    uasort($_middlewareProfile, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
                    $_rank = 1;
                    foreach (array_slice($_middlewareProfile, 0, 12, true) as $_phase => $_stat) {
                        error_log(sprintf('  [Middleware#%d] %dms x%d max=%dms %s',
                            $_rank++,
                            (int)($_stat['time'] ?? 0),
                            (int)($_stat['count'] ?? 0),
                            (int)($_stat['max'] ?? 0),
                            $_phase
                        ));
                    }
                }
                if (!empty($_beforeSerializationProfile)) {
                    uasort($_beforeSerializationProfile, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
                    $_rank = 1;
                    foreach (array_slice($_beforeSerializationProfile, 0, 16, true) as $_phase => $_stat) {
                        error_log(sprintf('  [BeforeSerialization#%d] %dms x%d max=%dms %s',
                            $_rank++,
                            (int)($_stat['time'] ?? 0),
                            (int)($_stat['count'] ?? 0),
                            (int)($_stat['max'] ?? 0),
                            $_phase
                        ));
                    }
                }
                // 输出最慢的 3 条 SQL
                if ($_queryCount > 0) {
                    usort($_queries, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
                    for ($_i = 0; $_i < min(3, $_queryCount); $_i++) {
                        $_sq = $_queries[$_i];
                        error_log(sprintf('  [Slow#%d] %.1fms: %s', $_i+1, $_sq['time'] ?? 0, substr($_sq['query'] ?? '', 0, 200)));
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[Flarum-Swoole-Co] 未捕获异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        $swooleRes->status(500);
        $swooleRes->end('Internal Server Error');
        logRequest($swooleReq, 500, microtime(true) - $startTime);
        return;
    }

    if ($linkHeaderString !== '') {
        $swooleRes->header('Link', $linkHeaderString);
    }
    emitResponse($psrResponse, $swooleRes);
    logRequest($swooleReq, $psrResponse->getStatusCode(), microtime(true) - $startTime);

    if (LSCACHE_ENABLED && isset($ctx['lscache_redis'])) {
        try {
            $redis = $ctx['lscache_redis'];
            $purgeHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Purge');
            if ($purgeHeader) {
                $purges = array_map('trim', explode(',', $purgeHeader));
                $keysToDelete = [];
                foreach ($purges as $purgeObj) {
                    if ($purgeObj === '*') {
                        $cursor = 0;
                        do {
                            $result = $redis->executeRaw(['SCAN', $cursor, 'MATCH', 'lscache:page:*', 'COUNT', 1000]);
                            if (!is_array($result) || count($result) < 2) break;
                            $cursor = $result[0];
                            $keys = $result[1];
                            if (!empty($keys)) {
                                $keysToDelete = array_merge($keysToDelete, $keys);
                            }
                        } while ($cursor > 0);
                    } elseif (str_starts_with($purgeObj, 'tag=')) {
                        $tag = substr($purgeObj, 4);
                        $pageKeys = $redis->smembers("lscache:tag:{$tag}");
                        if (!empty($pageKeys)) {
                            $keysToDelete = array_merge($keysToDelete, $pageKeys);
                            $redis->del(["lscache:tag:{$tag}"]);
                        }
                    }
                }
                if (!empty($keysToDelete)) {
                    $redis->del(array_unique($keysToDelete));
                }
            }

            $cacheControl = $psrResponse->getHeaderLine('X-LiteSpeed-Cache-Control');
            if ($isGuest && in_array($method, ['GET', 'HEAD']) && (str_contains($cacheControl, 'public') || isset($swooleReq->get['uptime_kuma_cachebuster']))) {
                $body = $psrResponse->getBody();
                $body->rewind();
                $ttl = LSCACHE_DEFAULT_TTL;
                if (preg_match('/max-age=(\d+)/', $cacheControl, $m)) {
                    $ttl = (int)$m[1];
                }
                $storeData = [
                    'status' => $psrResponse->getStatusCode(),
                    'headers' => [],
                    'body' => $body->getContents()
                ];
                foreach ($psrResponse->getHeaders() as $name => $values) {
                    $lName = strtolower($name);
                    if (!in_array($lName, ['set-cookie', 'x-litespeed-cache-control', 'x-litespeed-tag', 'x-litespeed-purge'])) {
                        $storeData['headers'][$name] = implode(', ', $values);
                    }
                }
                $cacheKey = buildLSCacheKey($swooleReq);
                $lscacheEncodeStart = microtime(true);
                $encodedStoreData = json_encode($storeData, JSON_UNESCAPED_UNICODE);
                \CoroutineSerializerConfig::recordPostSerializerPhase('redis.lscache_encode:' . strlen((string)$storeData['body']), (microtime(true) - $lscacheEncodeStart) * 1000);
                $lscacheSetStart = microtime(true);
                $redis->setex($cacheKey, $ttl, $encodedStoreData);
                \CoroutineSerializerConfig::recordPostSerializerPhase('redis.lscache_setex_cmd:' . strlen((string)$encodedStoreData), (microtime(true) - $lscacheSetStart) * 1000);

                $tagHeader = $psrResponse->getHeaderLine('X-LiteSpeed-Tag');
                if ($tagHeader) {
                    $tags = array_map('trim', explode(',', $tagHeader));
                    foreach ($tags as $tag) {
                        if ($tag !== '') {
                            $lscacheTagStart = microtime(true);
                            $redis->sadd("lscache:tag:{$tag}", $cacheKey);
                            $redis->expire("lscache:tag:{$tag}", $ttl);
                            \CoroutineSerializerConfig::recordPostSerializerPhase('redis.lscache_tag_write', (microtime(true) - $lscacheTagStart) * 1000);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {}
    }

    if (memory_get_usage() > WORKER_MEMORY_WATERMARK) {
        if ($server instanceof \Swoole\Server) {
            \Swoole\Coroutine\System::sleep(0.1);
            $server->stop($GLOBALS['flarum_worker_id']);
        }
    }
});

$server->on('start', function ($server) use ($host) {
    if (str_starts_with($host, '/') && file_exists($host)) {
        chmod($host, 0777);
        echo "Socket 权限已自动修改为 777\n";
    }
});

echo "Flarum Swoole Worker 启动中... (Coroutine 模式)\n";
echo "监听地址: http://{$host}:{$port}\n";
echo "Worker 数量: {$workerCount}\n";
echo "---\n";

$server->start();
