<?php
declare(strict_types=1);

final class FoFBadgesWorkerPatch
{
    private static array $resourceCache = [];
    private static array $modelCache = [];

    public static function shouldSkipStandaloneIncludedResource($resource): bool
    {
        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
        if (!$serializer) {
            return false;
        }

        $serializerClass = get_class($serializer);
        if (!str_contains($serializerClass, 'FoF\\Badges\\Api\\Serializer\\UserBadgeSerializer')) {
            return false;
        }

        $path = \CoroutineSerializerConfig::requestPathFromSerializerForPatch($serializer);
        if (str_starts_with($path, '/api/badges')
            || str_starts_with($path, '/api/user-badges')
            || str_starts_with($path, '/api/badge-categories')) {
            return false;
        }

        return \CoroutineSerializerConfig::isDiscussionOrPostDocumentContextForPatch($serializer);
    }

    public static function prepareUserRelationsForResources(array $resources): void
    {
        if (!class_exists('FoF\\Badges\\UserBadge') || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $users = \CoroutineSerializerConfig::collectUserResourcesForPatch($resources, true);
        if (empty($users)) {
            return;
        }

        $ctx = \Swoole\Coroutine::getContext();
        $prepared = $ctx["fof_badges_prepared_users"] ?? [];
        if (!is_array($prepared)) {
            $prepared = [];
        }

        $missing = [];
        foreach ($users as $id => $user) {
            if (!isset($prepared[$id])) {
                $missing[$id] = $user;
            }
        }

        if (empty($missing)) {
            return;
        }

        $start = microtime(true);
        try {
            $collection = new \Illuminate\Database\Eloquent\Collection(array_values($missing));
            if (method_exists($collection, 'loadMissing')) {
                $collection->loadMissing('userBadges');
            } else {
                $collection->load('userBadges');
            }
            self::attachCachedBadgesToUsers($collection);
            foreach (array_keys($missing) as $id) {
                $prepared[$id] = true;
            }
            $ctx["fof_badges_prepared_users"] = $prepared;
        } catch (\Throwable $e) {
            foreach (array_keys($missing) as $id) {
                $prepared[$id] = true;
            }
            $ctx["fof_badges_prepared_users"] = $prepared;
        } finally {
            \CoroutineSerializerConfig::recordUserSerializerPhase('fofBadges.batch_load:' . count($missing), (microtime(true) - $start) * 1000);
        }
    }

    private static function attachCachedBadgesToUsers($users): void
    {
        $badgeIds = [];
        foreach ($users as $user) {
            if (!is_object($user) || !method_exists($user, 'relationLoaded') || !$user->relationLoaded('userBadges')) {
                continue;
            }

            $userBadges = $user->getRelation('userBadges');
            foreach ($userBadges as $userBadge) {
                if (is_object($userBadge) && isset($userBadge->badge_id)) {
                    $badgeIds[(int) $userBadge->badge_id] = (int) $userBadge->badge_id;
                }
            }
        }

        if (empty($badgeIds)) {
            return;
        }

        $badges = self::getCachedBadgeModels(array_values($badgeIds));
        foreach ($users as $user) {
            if (!is_object($user) || !method_exists($user, 'relationLoaded') || !$user->relationLoaded('userBadges')) {
                continue;
            }

            foreach ($user->getRelation('userBadges') as $userBadge) {
                $badgeId = is_object($userBadge) ? (int) ($userBadge->badge_id ?? 0) : 0;
                if ($badgeId > 0 && isset($badges[$badgeId]) && method_exists($userBadge, 'setRelation')) {
                    $userBadge->setRelation('badge', $badges[$badgeId]);
                }
            }
        }
    }

    private static function getCachedBadgeModels(array $badgeIds): array
    {
        if (!class_exists('FoF\\Badges\\Badge')) {
            return [];
        }

        $now = time();
        $ttl = defined('BADGE_MODEL_CACHE_TTL') ? BADGE_MODEL_CACHE_TTL : 7200;
        $result = [];
        $missing = [];

        foreach (array_unique(array_map('intval', $badgeIds)) as $badgeId) {
            if ($badgeId <= 0) {
                continue;
            }

            $cached = self::$modelCache[$badgeId] ?? null;
            if (is_array($cached) && ($cached['exp'] ?? 0) > $now && isset($cached['model'])) {
                $result[$badgeId] = $cached['model'];
            } else {
                unset(self::$modelCache[$badgeId]);
                $missing[$badgeId] = $badgeId;
            }
        }

        if (!empty($missing)) {
            $start = microtime(true);
            try {
                $models = \FoF\Badges\Badge::query()
                    ->whereIn('id', array_values($missing))
                    ->get();
                foreach ($models as $badge) {
                    $id = (int) $badge->id;
                    self::$modelCache[$id] = ['model' => $badge, 'exp' => $now + $ttl];
                    $result[$id] = $badge;
                }
            } catch (\Throwable $e) {
                // Fallback leaves relation unloaded; the original extension path can still query normally.
            } finally {
                \CoroutineSerializerConfig::recordUserSerializerPhase('fofBadges.badge_model_cache_miss:' . count($missing), (microtime(true) - $start) * 1000);
            }
        }

        $limit = defined('BADGE_MODEL_CACHE_LIMIT') ? BADGE_MODEL_CACHE_LIMIT : 2048;
        while (count(self::$modelCache) > $limit) {
            array_shift(self::$modelCache);
        }

        return $result;
    }

    private static function resourceCacheKey($resource, string $cacheKey): ?string
    {
        if (!str_contains($cacheKey, 'FoF\\Badges\\Api\\Serializer\\BadgeSerializer')) {
            return null;
        }
        if (!method_exists($resource, 'getData')) {
            return null;
        }

        $badge = $resource->getData();
        if (!is_object($badge) || !str_contains(get_class($badge), 'FoF\\Badges\\Badge') || !isset($badge->id)) {
            return null;
        }

        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
        if ($serializer && method_exists($serializer, 'getRequest') && $serializer->getRequest()) {
            $request = $serializer->getRequest();
            if (method_exists($request, 'getQueryParams')) {
                $params = $request->getQueryParams();
                if (isset($params['fields']['badges'])) {
                    return null;
                }
            }
        }

        $updatedAt = '';
        if (isset($badge->updated_at) && is_object($badge->updated_at) && method_exists($badge->updated_at, 'getTimestamp')) {
            $updatedAt = (string) $badge->updated_at->getTimestamp();
        }

        return implode('|', [
            'badge',
            (string) $badge->id,
            $updatedAt,
            (string) ($badge->earned_count ?? 0),
            is_object($serializer) ? get_class($serializer) : 'unknown',
        ]);
    }

    private static function stripDynamicResource(array $resource): array
    {
        unset($resource['attributes']['isEarned'], $resource['attributes']['canEdit']);
        unset($resource['attributes']['triggerConfig'], $resource['attributes']['actions']);

        return $resource;
    }

    private static function hydrateDynamicResource(array $resource, $jsonApiResource): array
    {
        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($jsonApiResource);
        $badge = method_exists($jsonApiResource, 'getData') ? $jsonApiResource->getData() : null;
        $actor = $serializer && method_exists($serializer, 'getActor') ? $serializer->getActor() : null;

        $canModerate = is_object($actor) && method_exists($actor, 'hasPermission')
            ? (bool) $actor->hasPermission('badges.moderate')
            : false;

        $resource['attributes']['canEdit'] = $canModerate;
        $resource['attributes']['isEarned'] = false;

        if (is_object($actor) && method_exists($actor, 'isGuest') && !$actor->isGuest() && is_object($badge) && isset($badge->id)) {
            $earnedIds = [];
            try {
                if (\Swoole\Coroutine::getCid() > 0) {
                    $ctx = \Swoole\Coroutine::getContext();
                    $earnedIds = $ctx["fof_badges_actor_earned_ids"] ?? null;
                    if (!is_array($earnedIds)) {
                        $earnedIds = \FoF\Badges\UserBadge::where('user_id', $actor->id)
                            ->pluck('badge_id')
                            ->map(fn ($id) => (int) $id)
                            ->toArray();
                        $ctx["fof_badges_actor_earned_ids"] = $earnedIds;
                    }
                } else {
                    $earnedIds = \FoF\Badges\UserBadge::where('user_id', $actor->id)
                        ->pluck('badge_id')
                        ->map(fn ($id) => (int) $id)
                        ->toArray();
                }
                $resource['attributes']['isEarned'] = in_array((int) $badge->id, $earnedIds, true);
            } catch (\Throwable $e) {}
        }

        if ($canModerate && is_object($badge)) {
            $resource['attributes']['triggerConfig'] = $badge->trigger_config ?? null;
            $resource['attributes']['actions'] = $badge->actions ?? null;
        }

        return $resource;
    }

    public static function getCachedResource($resource, string $cacheKey): ?array
    {
        $resourceCacheKey = self::resourceCacheKey($resource, $cacheKey);
        if ($resourceCacheKey === null || !isset(self::$resourceCache[$resourceCacheKey])) {
            return null;
        }

        return self::hydrateDynamicResource(self::$resourceCache[$resourceCacheKey], $resource);
    }

    public static function rememberResource($resource, string $cacheKey, array $result): void
    {
        $resourceCacheKey = self::resourceCacheKey($resource, $cacheKey);
        if ($resourceCacheKey === null) {
            return;
        }

        self::$resourceCache[$resourceCacheKey] = self::stripDynamicResource($result);
        $limit = defined('BADGE_RESOURCE_CACHE_LIMIT') ? BADGE_RESOURCE_CACHE_LIMIT : 4096;
        if (count(self::$resourceCache) > $limit) {
            array_shift(self::$resourceCache);
        }
    }
}
