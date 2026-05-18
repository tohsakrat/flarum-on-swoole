<?php
declare(strict_types=1);

final class FoFReactionsWorkerPatch
{
    private static array $batchCache = [];

    private static function getFoFReactionArrayResources(): array
    {
        if (!class_exists('FoF\\Reactions\\Reaction')) {
            return [[], []];
        }

        static $cached = null;
        static $cachedAt = 0.0;
        $ttl = defined('REACTION_RESOURCE_CACHE_TTL') ? (float) REACTION_RESOURCE_CACHE_TTL : 60.0;
        if (is_array($cached) && (microtime(true) - $cachedAt) < $ttl) {
            return $cached;
        }

        try {
            $reactions = \FoF\Reactions\Reaction::query()->get();
        } catch (\Throwable $e) {
            return [[], []];
        }

        $resources = [];
        $refs = [];
        foreach ($reactions as $reaction) {
            if (!isset($reaction->id)) {
                continue;
            }

            $id = (string) $reaction->id;
            $refs[] = ['type' => 'reactions', 'id' => $id];
            $resources[] = [
                'type' => 'reactions',
                'id' => $id,
                'attributes' => [
                    'identifier' => $reaction->identifier,
                    'display' => $reaction->display,
                    'type' => $reaction->type,
                    'enabled' => (bool) $reaction->enabled,
                ],
            ];
        }

        $cached = [$resources, $refs];
        $cachedAt = microtime(true);

        return $cached;
    }

    private static function getFoFReactionObjectResources(): array
    {
        [$arrayResources, $arrayRefs] = self::getFoFReactionArrayResources();

        return [
            array_map(fn($resource) => json_decode(json_encode($resource)), $arrayResources),
            array_map(fn($ref) => (object) $ref, $arrayRefs),
        ];
    }

    public static function ensureInForumDocument(array $document): array
    {
        if (($document['data']['type'] ?? null) !== 'forums') {
            return $document;
        }

        $attributes = $document['data']['attributes'] ?? [];
        $looksEnabled = isset($attributes['fofReactionsAllowAnonymous'])
            || isset($attributes['fofReactionsCdnUrl'])
            || isset($document['data']['relationships']['reactions']);
        if (!$looksEnabled) {
            return $document;
        }

        [$resources, $refs] = self::getFoFReactionArrayResources();
        if (empty($resources)) {
            return $document;
        }

        $relationshipData = $document['data']['relationships']['reactions']['data'] ?? [];
        if (!is_array($relationshipData)) {
            $relationshipData = [];
        }

        $seenRefs = [];
        foreach ($relationshipData as $ref) {
            if (is_array($ref) && ($ref['type'] ?? null) === 'reactions' && isset($ref['id'])) {
                $seenRefs[(string) $ref['id']] = true;
            }
        }
        foreach ($refs as $ref) {
            if (!isset($seenRefs[$ref['id']])) {
                $relationshipData[] = $ref;
                $seenRefs[$ref['id']] = true;
            }
        }
        $document['data']['relationships']['reactions']['data'] = array_values($relationshipData);

        $included = $document['included'] ?? [];
        if (!is_array($included)) {
            $included = [];
        }

        $seenIncluded = [];
        foreach ($included as $resource) {
            if (is_array($resource) && ($resource['type'] ?? null) === 'reactions' && isset($resource['id'])) {
                $seenIncluded[(string) $resource['id']] = true;
            }
        }
        foreach ($resources as $resource) {
            if (!isset($seenIncluded[$resource['id']])) {
                $included[] = $resource;
                $seenIncluded[$resource['id']] = true;
            }
        }
        $document['included'] = array_values($included);

        return $document;
    }

    private static function arrayResourceHasReactionCounts($resource): bool
    {
        return is_array($resource)
            && ($resource['type'] ?? null) === 'posts'
            && is_array($resource['attributes'] ?? null)
            && array_key_exists('reactionCounts', $resource['attributes']);
    }

    private static function arrayDocumentHasPostReactionCounts(array $document): bool
    {
        $data = $document['data'] ?? null;
        if (self::arrayResourceHasReactionCounts($data)) {
            return true;
        }

        if (is_array($data)) {
            foreach ($data as $resource) {
                if (self::arrayResourceHasReactionCounts($resource)) {
                    return true;
                }
            }
        }

        $included = $document['included'] ?? [];
        if (is_array($included)) {
            foreach ($included as $resource) {
                if (self::arrayResourceHasReactionCounts($resource)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function sanitizeArrayPostReactionCounts(&$resource, array $validReactionIds): void
    {
        if (!self::arrayResourceHasReactionCounts($resource)) {
            return;
        }

        $counts = $resource['attributes']['reactionCounts'];
        if (!is_array($counts)) {
            return;
        }

        $filtered = [];
        foreach ($counts as $reactionId => $count) {
            $reactionId = (string) $reactionId;
            if (isset($validReactionIds[$reactionId])) {
                $filtered[$reactionId] = $count;
            }
        }

        $resource['attributes']['reactionCounts'] = $filtered;
    }

    private static function sanitizeArrayDocumentReactionCounts(array $document, array $validReactionIds): array
    {
        if (isset($document['data'])) {
            if (self::arrayResourceHasReactionCounts($document['data'])) {
                self::sanitizeArrayPostReactionCounts($document['data'], $validReactionIds);
            } elseif (is_array($document['data'])) {
                foreach ($document['data'] as &$resource) {
                    self::sanitizeArrayPostReactionCounts($resource, $validReactionIds);
                }
                unset($resource);
            }
        }

        if (isset($document['included']) && is_array($document['included'])) {
            foreach ($document['included'] as &$resource) {
                self::sanitizeArrayPostReactionCounts($resource, $validReactionIds);
            }
            unset($resource);
        }

        return $document;
    }

    public static function ensureForDocument(array $document): array
    {
        $document = self::ensureInForumDocument($document);

        if (!self::arrayDocumentHasPostReactionCounts($document)) {
            return $document;
        }

        [$resources, $refs] = self::getFoFReactionArrayResources();
        $validReactionIds = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? null) === 'reactions' && isset($ref['id'])) {
                $validReactionIds[(string) $ref['id']] = true;
            }
        }

        $document = self::sanitizeArrayDocumentReactionCounts($document, $validReactionIds);

        if (empty($resources)) {
            return $document;
        }

        $included = $document['included'] ?? [];
        if (!is_array($included)) {
            $included = [];
        }

        $seenIncluded = [];
        $forumIndex = null;
        foreach ($included as $idx => $resource) {
            if (!is_array($resource)) {
                continue;
            }

            if (($resource['type'] ?? null) === 'reactions' && isset($resource['id'])) {
                $seenIncluded[(string) $resource['id']] = true;
            }

            if (($resource['type'] ?? null) === 'forums' && (string) ($resource['id'] ?? '') === '1') {
                $forumIndex = $idx;
            }
        }

        foreach ($resources as $resource) {
            if (!isset($seenIncluded[$resource['id']])) {
                $included[] = $resource;
                $seenIncluded[$resource['id']] = true;
            }
        }

        if ($forumIndex === null) {
            $included[] = ['type' => 'forums', 'id' => '1', 'relationships' => []];
            $forumIndex = array_key_last($included);
        }
        if (!isset($included[$forumIndex]['relationships']) || !is_array($included[$forumIndex]['relationships'])) {
            $included[$forumIndex]['relationships'] = [];
        }
        $included[$forumIndex]['relationships']['reactions']['data'] = $refs;

        $document['included'] = array_values($included);

        return $document;
    }

    private static function apiDocumentHasPostReactionCounts($apiDocument): bool
    {
        if (!is_object($apiDocument) || !is_array($apiDocument->included ?? null)) {
            return false;
        }

        foreach ($apiDocument->included as $resource) {
            if (!is_object($resource) || ($resource->type ?? null) !== 'posts') {
                continue;
            }

            if (isset($resource->attributes) && is_object($resource->attributes) && property_exists($resource->attributes, 'reactionCounts')) {
                return true;
            }
        }

        return false;
    }

    private static function sanitizeObjectPostReactionCounts($resource, array $validReactionIds): void
    {
        if (!is_object($resource) || ($resource->type ?? null) !== 'posts') {
            return;
        }

        if (!isset($resource->attributes) || !is_object($resource->attributes) || !property_exists($resource->attributes, 'reactionCounts')) {
            return;
        }

        $counts = $resource->attributes->reactionCounts;
        if (is_object($counts)) {
            $counts = get_object_vars($counts);
        }
        if (!is_array($counts)) {
            return;
        }

        $filtered = [];
        foreach ($counts as $reactionId => $count) {
            $reactionId = (string) $reactionId;
            if (isset($validReactionIds[$reactionId])) {
                $filtered[$reactionId] = $count;
            }
        }

        $resource->attributes->reactionCounts = (object) $filtered;
    }

    public static function ensureInDiscussionPreload($apiDocument)
    {
        if (!self::apiDocumentHasPostReactionCounts($apiDocument)) {
            return $apiDocument;
        }

        [$resources, $refs] = self::getFoFReactionObjectResources();
        $included = is_array($apiDocument->included ?? null) ? $apiDocument->included : [];
        $validReactionIds = [];
        foreach ($refs as $ref) {
            if (($ref->type ?? null) === 'reactions' && isset($ref->id)) {
                $validReactionIds[(string) $ref->id] = true;
            }
        }
        foreach ($included as $resource) {
            self::sanitizeObjectPostReactionCounts($resource, $validReactionIds);
        }

        if (empty($resources)) {
            $apiDocument->included = array_values($included);
            return $apiDocument;
        }

        $seenIncluded = [];
        $forumResource = null;

        foreach ($included as $resource) {
            if (!is_object($resource)) {
                continue;
            }

            if (($resource->type ?? null) === 'reactions' && isset($resource->id)) {
                $seenIncluded[(string) $resource->id] = true;
            }

            if (($resource->type ?? null) === 'forums' && (string) ($resource->id ?? '') === '1') {
                $forumResource = $resource;
            }
        }

        foreach ($resources as $resource) {
            if (!isset($seenIncluded[(string) $resource->id])) {
                $included[] = $resource;
                $seenIncluded[(string) $resource->id] = true;
            }
        }

        if (!$forumResource) {
            $forumResource = (object) ['type' => 'forums', 'id' => '1'];
            $included[] = $forumResource;
        }
        if (!isset($forumResource->relationships) || !is_object($forumResource->relationships)) {
            $forumResource->relationships = (object) [];
        }
        $forumResource->relationships->reactions = (object) ['data' => $refs];

        $apiDocument->included = array_values($included);

        return $apiDocument;
    }

    public static function prepareForResources(array $resources): void
    {
        if (!class_exists('FoF\\Reactions\\PostAttributes', false) || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        if (!$ctx) {
            return;
        }

        $requestId = $ctx["request_id"] ?? (string)\Swoole\Coroutine::getCid();
        $postIds = [];
        $sampleResource = null;

        foreach ($resources as $resource) {
            $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
            if (!is_object($serializer) || !str_contains(get_class($serializer), 'Flarum\\Api\\Serializer\\PostSerializer')) {
                continue;
            }

            $post = method_exists($resource, 'getData') ? $resource->getData() : null;
            if (is_object($post) && str_contains(get_class($post), 'Flarum\\Post\\') && isset($post->id)) {
                $postIds[(int)$post->id] = (int)$post->id;
                $sampleResource ??= $resource;
            }
        }

        if (empty($postIds) || $sampleResource === null) {
            return;
        }

        if (!isset(self::$batchCache[$requestId])) {
            self::$batchCache[$requestId] = [
                "counts" => [],
                "actor" => [],
                "prepared" => [],
            ];
        }

        $cache =& self::$batchCache[$requestId];
        $missingIds = array_values(array_filter($postIds, fn($id) => !isset($cache["prepared"][$id])));
        if (empty($missingIds)) {
            return;
        }

        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($sampleResource);
        $post = method_exists($sampleResource, 'getData') ? $sampleResource->getData() : null;
        if (!is_object($post) || !method_exists($post, 'getConnection')) {
            return;
        }

        $start = microtime(true);
        try {
            $db = $post->getConnection();

            foreach ($missingIds as $id) {
                $cache["counts"][$id] = [];
                $cache["actor"][$id] = null;
                $cache["prepared"][$id] = true;
            }

            static $reactionIdsCache = null;
            if ($reactionIdsCache === null) {
                $reactionIdsCache = $db->table('reactions')->pluck('id')->map(fn($id) => (int)$id)->all();
            }
            $validReactionIds = array_fill_keys(array_map('intval', $reactionIdsCache), true);

            foreach ($missingIds as $id) {
                foreach ($reactionIdsCache as $reactionId) {
                    $cache["counts"][$id][$reactionId] = 0;
                }
            }

            $registered = $db->table('post_reactions')
                ->whereIn('post_id', $missingIds)
                ->groupBy('post_id', 'reaction_id')
                ->selectRaw('post_id, reaction_id, COUNT(*) as count')
                ->get();

            foreach ($registered as $row) {
                $postId = (int)$row->post_id;
                $reactionId = (int)$row->reaction_id;
                if (!isset($validReactionIds[$reactionId])) {
                    continue;
                }
                $cache["counts"][$postId][$reactionId] = ($cache["counts"][$postId][$reactionId] ?? 0) + (int)$row->count;
            }

            $request = is_object($serializer) && method_exists($serializer, 'getRequest') ? $serializer->getRequest() : null;
            $actor = is_object($serializer) && method_exists($serializer, 'getActor') ? $serializer->getActor() : null;
            $settings = null;
            try {
                $settings = \Flarum\Api\Serializer\AbstractSerializer::getContainer()->make('flarum.settings');
            } catch (\Throwable $e) {}

            $allowAnonymous = $settings && method_exists($settings, 'get') && (bool)$settings->get('fof-reactions.anonymousReactions');
            if ($allowAnonymous) {
                $anonymous = $db->table('post_anonymous_reactions')
                    ->whereIn('post_id', $missingIds)
                    ->groupBy('post_id', 'reaction_id')
                    ->selectRaw('post_id, reaction_id, COUNT(*) as count')
                    ->get();

                foreach ($anonymous as $row) {
                    $postId = (int)$row->post_id;
                    $reactionId = (int)$row->reaction_id;
                    if (!isset($validReactionIds[$reactionId])) {
                        continue;
                    }
                    $cache["counts"][$postId][$reactionId] = ($cache["counts"][$postId][$reactionId] ?? 0) + (int)$row->count;
                }
            }

            if (is_object($actor) && method_exists($actor, 'isGuest') && $actor->isGuest()) {
                $session = $request && method_exists($request, 'getAttribute') ? $request->getAttribute('session') : null;
                if ($session !== null && method_exists($session, 'getId')) {
                    $rows = $db->table('post_anonymous_reactions')
                        ->whereIn('post_id', $missingIds)
                        ->where('guest_id', $session->getId())
                        ->select('post_id', 'reaction_id')
                        ->get();

                    foreach ($rows as $row) {
                        $reactionId = (int)$row->reaction_id;
                        if (isset($validReactionIds[$reactionId])) {
                            $cache["actor"][(int)$row->post_id] = $reactionId;
                        }
                    }
                }
            } elseif (is_object($actor) && isset($actor->id)) {
                $rows = $db->table('post_reactions')
                    ->whereIn('post_id', $missingIds)
                    ->where('user_id', $actor->id)
                    ->select('post_id', 'reaction_id')
                    ->get();

                foreach ($rows as $row) {
                    $reactionId = (int)$row->reaction_id;
                    if (isset($validReactionIds[$reactionId])) {
                        $cache["actor"][(int)$row->post_id] = $reactionId;
                    }
                }
            }

            if (defined("LOG_LEVEL") && LOG_LEVEL === "debug") {
                \CoroutineSerializerConfig::recordPostSerializerPhase("fofReactions.batch_prepare:" . count($missingIds), (microtime(true) - $start) * 1000, $serializer, $post);
            }
        } catch (\Throwable $e) {
            unset(self::$batchCache[$requestId]);
        }
    }

    public static function getPreparedReactionCounts($post): ?array
    {
        if (!is_object($post) || !isset($post->id) || \Swoole\Coroutine::getCid() <= 0) {
            return null;
        }

        $ctx = \Swoole\Coroutine::getContext();
        $requestId = $ctx["request_id"] ?? null;
        if (!$requestId || !isset(self::$batchCache[$requestId]["prepared"][(int)$post->id])) {
            return null;
        }

        return self::$batchCache[$requestId]["counts"][(int)$post->id] ?? [];
    }

    public static function getPreparedActorReaction($post): array
    {
        if (!is_object($post) || !isset($post->id) || \Swoole\Coroutine::getCid() <= 0) {
            return [false, null];
        }

        $ctx = \Swoole\Coroutine::getContext();
        $requestId = $ctx["request_id"] ?? null;
        if (!$requestId || !isset(self::$batchCache[$requestId]["prepared"][(int)$post->id])) {
            return [false, null];
        }

        return [true, self::$batchCache[$requestId]["actor"][(int)$post->id] ?? null];
    }

    public static function clearRequestCache(string $requestId): void
    {
        unset(self::$batchCache[$requestId]);
    }

    public static function installPostAttributesPatch($composerLoader, bool $isDebugBoot): void
    {
        $fofReactionsAttrFile = $composerLoader->findFile('FoF\Reactions\PostAttributes');
        if (!$fofReactionsAttrFile || !file_exists($fofReactionsAttrFile)) {
            echo "[Boot] FoF\\Reactions PostAttributes 注入跳过：目标文件不存在。\n";
            return;
        }

        if (class_exists('FoF\Reactions\PostAttributes', false)) {
            echo "[Boot] FoF\\Reactions PostAttributes 注入跳过：目标类已加载。\n";
            return;
        }

        $src = file_get_contents($fofReactionsAttrFile);
        $patchMatches = [
            'reaction_cache' => 0,
            'registered_query_table' => 0,
            'anonymous_query_table' => 0,
            'reaction_counts_fast_path' => 0,
            'actor_reaction_fast_path' => 0,
        ];
        $patched = str_replace(
            '$reactions = Reaction::all();',
            'static $reactionsCache = null; $reactions = $reactionsCache ?? ($reactionsCache = Reaction::all());',
            $src,
            $patchMatches['reaction_cache']
        );
        $patched = str_replace(
            'PostReaction::where(',
            '$post->getConnection()->table(\'post_reactions\')->where(',
            $patched,
            $patchMatches['registered_query_table']
        );
        $patched = str_replace(
            'PostAnonymousReaction::where(',
            '$post->getConnection()->table(\'post_anonymous_reactions\')->where(',
            $patched,
            $patchMatches['anonymous_query_table']
        );
        $patched = str_replace(
            '    protected function getReactionCountsForPost(Post $post): array
    {
        // Initialize counts array
        $counts = [];

        // Query for reactions from registered users
        $registeredReactions = $post->getConnection()->table(\'post_reactions\')->where(\'post_id\', $post->id)
            ->groupBy(\'reaction_id\')
            ->selectRaw(\'reaction_id, COUNT(*) as count\')
            ->pluck(\'count\', \'reaction_id\');

        // Query for anonymous reactions if allowed
        $anonymousReactions = collect([]);
        if ($this->settings->get(\'fof-reactions.anonymousReactions\')) {
            $anonymousReactions = $post->getConnection()->table(\'post_anonymous_reactions\')->where(\'post_id\', $post->id)
                ->groupBy(\'reaction_id\')
                ->selectRaw(\'reaction_id, COUNT(*) as count\')
                ->pluck(\'count\', \'reaction_id\');
        }

        // Merge the registered and anonymous reactions
        static $reactionsCache = null; $reactions = $reactionsCache ?? ($reactionsCache = Reaction::all());
        foreach ($reactions as $reaction) {
            $counts[$reaction->id] = $registeredReactions->get($reaction->id, 0) + $anonymousReactions->get($reaction->id, 0);
        }

        return $counts;
    }',
            '    protected function getReactionCountsForPost(Post $post): array
    {
        if (($prepared = \FoFReactionsWorkerPatch::getPreparedReactionCounts($post)) !== null) {
            return $prepared;
        }

        // Initialize counts array
        $counts = [];

        // Query for reactions from registered users
        $registeredReactions = $post->getConnection()->table(\'post_reactions\')->where(\'post_id\', $post->id)
            ->groupBy(\'reaction_id\')
            ->selectRaw(\'reaction_id, COUNT(*) as count\')
            ->pluck(\'count\', \'reaction_id\');

        // Query for anonymous reactions if allowed
        $anonymousReactions = collect([]);
        if ($this->settings->get(\'fof-reactions.anonymousReactions\')) {
            $anonymousReactions = $post->getConnection()->table(\'post_anonymous_reactions\')->where(\'post_id\', $post->id)
                ->groupBy(\'reaction_id\')
                ->selectRaw(\'reaction_id, COUNT(*) as count\')
                ->pluck(\'count\', \'reaction_id\');
        }

        // Merge the registered and anonymous reactions
        static $reactionsCache = null; $reactions = $reactionsCache ?? ($reactionsCache = Reaction::all());
        foreach ($reactions as $reaction) {
            $counts[$reaction->id] = $registeredReactions->get($reaction->id, 0) + $anonymousReactions->get($reaction->id, 0);
        }

        return $counts;
    }',
            $patched,
            $patchMatches['reaction_counts_fast_path']
        );
        $patched = str_replace(
            '    protected function getActorReactionForPost(User $actor, Post $post, ServerRequestInterface $request): ?int
    {
        if ($actor->isGuest()) {',
            '    protected function getActorReactionForPost(User $actor, Post $post, ServerRequestInterface $request): ?int
    {
        [$hasPrepared, $prepared] = \FoFReactionsWorkerPatch::getPreparedActorReaction($post);
        if ($hasPrepared) {
            return $prepared;
        }

        if ($actor->isGuest()) {',
            $patched,
            $patchMatches['actor_reaction_fast_path']
        );
        if ($patched !== $src) {
            $code = preg_replace('/^.*?<\?php\s*/is', '', $patched);
            try {
                eval($code);
                echo "[Boot] 协程序列化引擎注入成功（FoF\\Reactions 静态缓存拦截）。\n";
                foreach ($patchMatches as $name => $count) {
                    if ($count === 0) {
                        echo "[Boot] ⚠️ FoF\\Reactions 补丁片段未命中: {$name}\n";
                    }
                }
            } catch (\Throwable $e) {
                echo "[Boot] ⚠️ FoF\\Reactions 拦截注入失败: " . $e->getMessage() . "\n";
            }
        } else {
            echo "[Boot] FoF\\Reactions PostAttributes 注入跳过：目标源码未发生替换。\n";
        }
    }
}
