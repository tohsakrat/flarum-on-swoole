<?php
declare(strict_types=1);

/**
 * WorkerCache: 进程级内存缓存，利用 Swoole worker 长驻特性。
 * 同一 worker 内的所有请求共享，TTL 过期后自动刷新。
 * 注意：8 个 worker 各自独立，不跨进程共享，也无实时失效。
 * 仅适合变化极慢的数据（token、设置等）。
 */
class WorkerCache {
    private static array $store = [];

    public static function get(string $key): mixed {
        $item = self::$store[$key] ?? null;
        if ($item !== null && $item['exp'] > time()) {
            return $item['val'];
        }
        unset(self::$store[$key]);
        return null;
    }

    public static function set(string $key, mixed $val, int $ttl = 60): void {
        self::$store[$key] = ['val' => $val, 'exp' => time() + $ttl];
    }

    /** 主动失效（如登出时调用） */
    public static function delete(string $key): void {
        unset(self::$store[$key]);
    }
}

function resolveRedisDatabase(array $baseSettings, $configured, array $avoid): int {
    if ($configured !== null) {
        return max(0, (int)$configured);
    }

    $avoidSet = [];
    foreach ($avoid as $db) {
        if ($db !== null) {
            $avoidSet[(int)$db] = true;
        }
    }

    $candidate = (int)($baseSettings['database'] ?? 0);
    while (isset($avoidSet[$candidate])) {
        $candidate++;
    }

    return $candidate;
}

function redisSettingsForDatabase(array $baseSettings, int $database): array {
    $settings = $baseSettings;
    $settings['database'] = $database;
    unset($settings['session_database']);

    return $settings;
}

function createPredisPool(array $settings, int $size): \Swoole\Coroutine\Channel {
    $pool = new \Swoole\Coroutine\Channel($size);
    for ($i = 0; $i < $size; $i++) {
        $pool->push(createFreshPredisClient($settings));
    }

    return $pool;
}

function createFreshPredisClient(array $settings): \Predis\Client {
    $client = new \Predis\Client($settings);
    $client->connect();

    return $client;
}

function isPredisClientAlive($client): bool {
    if (!$client instanceof \Predis\Client) {
        return false;
    }

    try {
        $client->ping();
        return true;
    } catch (\Throwable $e) {
        try {
            $client->disconnect();
        } catch (\Throwable $ignored) {}

        return false;
    }
}

class FragmentCache {
    private static function recordRedisPhase(string $phase, float $durationMs): void {
        if (class_exists('CoroutineSerializerConfig', false)) {
            \CoroutineSerializerConfig::recordPostSerializerPhase('fragmentCache.' . $phase, $durationMs);
        }
    }

    public static function getRedis() {
        if (\Swoole\Coroutine::getCid() <= 0) {
            return null;
        }

        $ctx = \Swoole\Coroutine::getContext();
        return $ctx['fragment_cache_redis'] ?? null;
    }

    public static function mget(array $keys): array {
        if (empty($keys)) {
            return [];
        }

        $redis = self::getRedis();
        if (!$redis) {
            return [];
        }

        try {
            $start = microtime(true);
            $values = $redis->mget(array_values($keys));
            self::recordRedisPhase('redis_mget_cmd:' . count($keys), (microtime(true) - $start) * 1000);
            return is_array($values) ? $values : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function setex(string $key, int $ttl, string $value): void {
        $redis = self::getRedis();
        if (!$redis) {
            return;
        }

        try {
            $start = microtime(true);
            $redis->setex($key, $ttl, $value);
            self::recordRedisPhase('redis_setex_cmd:' . strlen($value), (microtime(true) - $start) * 1000);
        } catch (\Throwable $e) {}
    }

    public static function deleteMatching(string $pattern, int $limit = 10000): int {
        $redis = self::getRedis();
        if (!$redis) {
            return 0;
        }

        $deleted = 0;
        $cursor = 0;

        try {
            do {
                $result = $redis->executeRaw(['SCAN', (string)$cursor, 'MATCH', $pattern, 'COUNT', '1000']);
                if (!is_array($result) || count($result) < 2) {
                    break;
                }

                $cursor = (int)$result[0];
                $keys = is_array($result[1] ?? null) ? $result[1] : [];
                if (!empty($keys)) {
                    if ($deleted + count($keys) > $limit) {
                        $keys = array_slice($keys, 0, max(0, $limit - $deleted));
                    }

                    if (!empty($keys)) {
                        $redis->del($keys);
                        $deleted += count($keys);
                    }
                }
            } while ($cursor > 0 && $deleted < $limit);
        } catch (\Throwable $e) {}

        return $deleted;
    }
}

class PostHtmlCache {
    private static array $store = [];
    private static array $order = [];
    private const REDIS_PREFIX = 'flarum:post-html:';

    public static function makeKey($post, ?string $xml = null): ?string {
        if (is_object($post)) {
            $postId = $post->id ?? null;
            $editedAt = $post->edited_at ?? null;
            $createdAt = $post->created_at ?? null;
            $revision = $editedAt ?: $createdAt;
            $revisionKey = is_object($revision) && method_exists($revision, 'getTimestamp')
                ? (string)$revision->getTimestamp()
                : (string)($revision ?? '');
            $xml ??= $post->getAttributes()['content'] ?? null;
        } else {
            $postId = $post;
            $revisionKey = '';
        }

        if (!$postId || $xml === null) {
            return null;
        }

        return 'post-html:' . $postId . ':' . $revisionKey . ':' . sha1($xml);
    }

    public static function redisKey(string $key): string {
        return self::REDIS_PREFIX . $key;
    }

    public static function get(string $key): ?string {
        return self::$store[$key] ?? null;
    }

    public static function set(string $key, string $html): void {
        if (!isset(self::$store[$key])) {
            self::$order[] = $key;
        }

        self::$store[$key] = $html;
        $limit = defined('POST_HTML_CACHE_LIMIT') ? POST_HTML_CACHE_LIMIT : 4096;
        while (count(self::$order) > $limit) {
            $oldest = array_shift(self::$order);
            if ($oldest !== null) {
                unset(self::$store[$oldest]);
            }
        }
    }

    public static function forgetPost($postOrId): void {
        $postId = is_object($postOrId) ? ($postOrId->id ?? null) : $postOrId;
        if (!$postId) {
            return;
        }

        $prefix = 'post-html:' . $postId . ':';
        foreach (array_keys(self::$store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$store[$key]);
            }
        }

        self::$order = array_values(array_filter(
            self::$order,
            fn($key) => !str_starts_with($key, $prefix)
        ));
    }

    public static function forgetPostShared($postOrId): int {
        $postId = is_object($postOrId) ? ($postOrId->id ?? null) : $postOrId;
        self::forgetPost($postOrId);

        if (!$postId) {
            return 0;
        }

        return \FragmentCache::deleteMatching(self::REDIS_PREFIX . 'post-html:' . $postId . ':*');
    }

    public static function clear(): void {
        self::$store = [];
        self::$order = [];
    }

    public static function getRequestPrefetch(string $key): ?string {
        if (\Swoole\Coroutine::getCid() <= 0) {
            return null;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx || !isset($ctx['post_html_prefetch']) || !array_key_exists($key, $ctx['post_html_prefetch'])) {
            return null;
        }

        $value = $ctx['post_html_prefetch'][$key];

        return is_string($value) ? $value : null;
    }

    public static function rememberRequestMiss(string $key): void {
        if (\Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if ($ctx) {
            $ctx['post_html_prefetch'][$key] = false;
        }
    }

    public static function setRequestPrefetch(string $key, string $html): void {
        if (\Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if ($ctx) {
            $ctx['post_html_prefetch'][$key] = $html;
        }
    }

    public static function prefetchForResources(array $resources): void {
        if (\Swoole\Coroutine::getCid() <= 0 || empty($resources)) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $keys = [];
        foreach ($resources as $resource) {
            $post = method_exists($resource, 'getData') ? $resource->getData() : null;
            if (!($post instanceof \Flarum\Post\CommentPost)) {
                continue;
            }

            $xml = $post->getAttributes()['content'] ?? null;
            if (!is_string($xml) || $xml === '') {
                continue;
            }

            $key = self::makeKey($post, $xml);
            if ($key === null || isset($ctx['post_html_prefetch'][$key])) {
                continue;
            }

            $cached = self::get($key);
            if ($cached !== null) {
                $ctx['post_html_prefetch'][$key] = $cached;
                continue;
            }

            $keys[$key] = $key;
            $limit = defined('POST_HTML_REDIS_PREFETCH_LIMIT') ? POST_HTML_REDIS_PREFETCH_LIMIT : 128;
            if (count($keys) >= $limit) {
                break;
            }
        }

        if (empty($keys) || !isset($ctx['fragment_cache_redis'])) {
            foreach ($keys as $key) {
                $ctx['post_html_prefetch'][$key] = false;
            }
            return;
        }

        $profileStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
        try {
            $keyBuildStart = $profileStart > 0 ? microtime(true) : 0.0;
            $redisKeys = array_map(fn($key) => self::redisKey($key), array_values($keys));
            if ($keyBuildStart > 0) {
                \CoroutineSerializerConfig::recordPostSerializerPhase(
                    'contentHtml.redis_key_build:' . count($keys),
                    (microtime(true) - $keyBuildStart) * 1000
                );
            }

            $values = \FragmentCache::mget($redisKeys);

            $applyStart = $profileStart > 0 ? microtime(true) : 0.0;
            $hits = 0;
            $misses = 0;
            $bytes = 0;
            foreach (array_values($keys) as $idx => $key) {
                $value = $values[$idx] ?? null;
                if (is_string($value) && $value !== '') {
                    $ctx['post_html_prefetch'][$key] = $value;
                    self::set($key, $value);
                    $hits++;
                    $bytes += strlen($value);
                } else {
                    $ctx['post_html_prefetch'][$key] = false;
                    $misses++;
                }
            }
            if ($applyStart > 0) {
                \CoroutineSerializerConfig::recordPostSerializerPhase(
                    'contentHtml.redis_apply:h' . $hits . '_m' . $misses . '_b' . $bytes,
                    (microtime(true) - $applyStart) * 1000
                );
            }
        } catch (\Throwable $e) {
            foreach ($keys as $key) {
                $ctx['post_html_prefetch'][$key] = false;
            }
        } finally {
            if ($profileStart > 0) {
                \CoroutineSerializerConfig::recordPostSerializerPhase(
                    'contentHtml.redis_mget:' . count($keys),
                    (microtime(true) - $profileStart) * 1000
                );
            }
        }
    }

    public static function setShared(string $key, string $html): void {
        self::set($key, $html);
        self::setRequestPrefetch($key, $html);

        if (\Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx || !isset($ctx['fragment_cache_redis'])) {
            return;
        }

        $ttl = defined('POST_HTML_REDIS_TTL') ? POST_HTML_REDIS_TTL : 604800;
        \FragmentCache::setex(self::redisKey($key), $ttl, $html);
    }
}

class UserHtmlCache {
    private static array $store = [];
    private static array $order = [];
    private const REDIS_PREFIX = 'flarum:user-html:';

    public static function makeKey($user, ?string $xml = null): ?string {
        if (!is_object($user) || !($user instanceof \Flarum\User\User)) {
            return null;
        }

        $userId = $user->id ?? null;
        if (!$userId || $xml === null || $xml === '') {
            return null;
        }

        $revision = $user->edited_at
            ?? $user->updated_at
            ?? $user->joined_at
            ?? null;
        $revisionKey = is_object($revision) && method_exists($revision, 'getTimestamp')
            ? (string)$revision->getTimestamp()
            : (string)($revision ?? '');

        return 'user-html:' . $userId . ':' . $revisionKey . ':' . sha1($xml);
    }

    public static function redisKey(string $key): string {
        return self::REDIS_PREFIX . $key;
    }

    public static function get(string $key): ?string {
        return self::$store[$key] ?? null;
    }

    public static function set(string $key, string $html): void {
        if (!isset(self::$store[$key])) {
            self::$order[] = $key;
        }

        self::$store[$key] = $html;
        $limit = defined('USER_HTML_CACHE_LIMIT') ? USER_HTML_CACHE_LIMIT : 4096;
        while (count(self::$order) > $limit) {
            $oldest = array_shift(self::$order);
            if ($oldest !== null) {
                unset(self::$store[$oldest]);
            }
        }
    }

    public static function forgetUser($userOrId): void {
        $userId = is_object($userOrId) ? ($userOrId->id ?? null) : $userOrId;
        if (!$userId) {
            return;
        }

        $prefix = 'user-html:' . $userId . ':';
        foreach (array_keys(self::$store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$store[$key]);
            }
        }

        self::$order = array_values(array_filter(
            self::$order,
            fn($key) => !str_starts_with($key, $prefix)
        ));
    }

    public static function forgetUserShared($userOrId): int {
        $userId = is_object($userOrId) ? ($userOrId->id ?? null) : $userOrId;
        self::forgetUser($userOrId);

        if (!$userId) {
            return 0;
        }

        return \FragmentCache::deleteMatching(self::REDIS_PREFIX . 'user-html:' . $userId . ':*');
    }

    public static function clear(): void {
        self::$store = [];
        self::$order = [];
    }

    public static function setShared(string $key, string $html): void {
        self::set($key, $html);

        if (\Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx || !isset($ctx['fragment_cache_redis'])) {
            return;
        }

        $ttl = defined('USER_HTML_REDIS_TTL') ? USER_HTML_REDIS_TTL : 604800;
        \FragmentCache::setex(self::redisKey($key), $ttl, $html);
    }
}

class SignatureHtmlCache {
    private static array $store = [];
    private static array $order = [];
    private const REDIS_PREFIX = 'flarum:signature-html:';

    public static function makeKey(?string $xml): ?string {
        if ($xml === null || $xml === '') {
            return null;
        }

        return 'signature-html:' . sha1($xml);
    }

    public static function redisKey(string $key): string {
        return self::REDIS_PREFIX . $key;
    }

    public static function get(string $key): ?string {
        return self::$store[$key] ?? null;
    }

    public static function set(string $key, string $html): void {
        if (!isset(self::$store[$key])) {
            self::$order[] = $key;
        }

        self::$store[$key] = $html;
        $limit = defined('SIGNATURE_HTML_CACHE_LIMIT') ? SIGNATURE_HTML_CACHE_LIMIT : 4096;
        while (count(self::$order) > $limit) {
            $oldest = array_shift(self::$order);
            if ($oldest !== null) {
                unset(self::$store[$oldest]);
            }
        }
    }

    public static function clear(): void {
        self::$store = [];
        self::$order = [];
    }

    public static function clearShared(): int {
        self::clear();

        return \FragmentCache::deleteMatching(self::REDIS_PREFIX . 'signature-html:*');
    }

    public static function setShared(string $key, string $html): void {
        self::set($key, $html);

        if (\Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx || !isset($ctx['fragment_cache_redis'])) {
            return;
        }

        $ttl = defined('SIGNATURE_HTML_REDIS_TTL') ? SIGNATURE_HTML_REDIS_TTL : 604800;
        \FragmentCache::setex(self::redisKey($key), $ttl, $html);
    }
}
