<?php
declare(strict_types=1);

final class IanMFollowUsersWorkerPatch
{
    private static function collectUserResources(array $resources, bool $userSerializerOnly = false): array
    {
        $users = [];
        foreach ($resources as $resource) {
            $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
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

    public static function prepareForResources(array $resources): void
    {
        if (!class_exists('IanM\\FollowUsers\\FollowState') || \Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $users = self::collectUserResources($resources);
        $countUsers = self::collectUserResources($resources, true);
        if (empty($users) && empty($countUsers)) {
            return;
        }

        $sampleSerializer = null;
        foreach ($resources as $resource) {
            $sampleSerializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
            if ($sampleSerializer && method_exists($sampleSerializer, 'getActor')) {
                break;
            }
        }

        $actor = $sampleSerializer && method_exists($sampleSerializer, 'getActor') ? $sampleSerializer->getActor() : null;
        $ctx = \Swoole\Coroutine::getContext();
        $prepared = $ctx["follow_users_prepared_users"] ?? [];
        if (!is_array($prepared)) {
            $prepared = [];
        }

        $missing = [];
        foreach ($countUsers as $id => $user) {
            if (!isset($prepared[$id])) {
                $missing[$id] = $user;
            }
        }

        $actorNeedsLoad = is_object($actor)
            && method_exists($actor, 'isGuest')
            && !$actor->isGuest()
            && method_exists($actor, 'relationLoaded')
            && !$actor->relationLoaded('followedUsers');

        if (empty($missing) && !$actorNeedsLoad) {
            return;
        }

        $start = microtime(true);
        try {
            if ($actorNeedsLoad && method_exists($actor, 'loadMissing')) {
                $actor->loadMissing('followedUsers');
            }

            if (!empty($missing)) {
                $settings = null;
                try {
                    $settings = resolve('flarum.settings');
                } catch (\Throwable $e) {}

                $statsEnabled = !$settings || (bool) $settings->get('ianm-follow-users.stats-on-profile');
                if ($statsEnabled) {
                    $collection = new \Illuminate\Database\Eloquent\Collection(array_values($missing));
                    $collection->loadCount(['followedUsers', 'followedBy']);
                    foreach ($collection as $user) {
                        \IanM\FollowUsers\FollowState::seedCountCache(
                            (int) $user->id,
                            (int) ($user->followed_by_count ?? 0),
                            (int) ($user->followed_users_count ?? 0)
                        );
                    }
                }

                foreach (array_keys($missing) as $id) {
                    $prepared[$id] = true;
                }
            }
            $ctx["follow_users_prepared_users"] = $prepared;
        } catch (\Throwable $e) {
            foreach (array_keys($missing) as $id) {
                $prepared[$id] = true;
            }
            $ctx["follow_users_prepared_users"] = $prepared;
        } finally {
            \CoroutineSerializerConfig::recordUserSerializerPhase('followUsers.batch_prepare:' . count($missing), (microtime(true) - $start) * 1000);
        }
    }

    public static function installPatches($composerLoader, bool $isDebugBoot): void
    {
        if (!function_exists('replaceWorkerClassMethod')) {
            return;
        }

        if ($isDebugBoot) {
            self::installBasicUserAttributesPatch($composerLoader);
        }

        self::installLoadRelationsPatch($composerLoader, $isDebugBoot);
    }

    private static function installBasicUserAttributesPatch($composerLoader): void
    {
        $followBasicFile = $composerLoader->findFile('IanM\FollowUsers\Api\AddBasicUserAttributes');
        if (!$followBasicFile || !file_exists($followBasicFile) || class_exists('IanM\FollowUsers\Api\AddBasicUserAttributes', false)) {
            return;
        }

        $src = file_get_contents($followBasicFile);
        [$src, $matched] = \replaceWorkerClassMethod($src, '__invoke', <<<'PHP'
    public function __invoke(BasicUserSerializer $serializer, User $user, array $attributes): array
    {
        $actor = $serializer->getActor();
        $actorLoaded = is_object($actor) && method_exists($actor, 'relationLoaded') && $actor->relationLoaded('followedUsers');
        $actorFollowedCount = -1;
        if ($actorLoaded && method_exists($actor, 'getRelation')) {
            try {
                $relation = $actor->getRelation('followedUsers');
                $actorFollowedCount = is_object($relation) && method_exists($relation, 'count') ? (int) $relation->count() : -1;
            } catch (\Throwable $e) {}
        }

        $followStart = microtime(true);
        $attributes['followed'] = FollowState::forFromRelation($actor, $user);
        \CoroutineSerializerConfig::recordPostSerializerPhase(
            'followUsers.attr.followed:' . ($actorLoaded ? 'actor_loaded' : 'actor_unloaded') . ':actor_followed_' . $actorFollowedCount,
            (microtime(true) - $followStart) * 1000,
            $serializer,
            $user
        );

        $settingsStart = microtime(true);
        $statsEnabled = (bool) $this->settings->get('ianm-follow-users.stats-on-profile');
        \CoroutineSerializerConfig::recordPostSerializerPhase(
            'followUsers.attr.settings',
            (microtime(true) - $settingsStart) * 1000,
            $serializer,
            $user
        );

        $serializerClass = get_class($serializer);
        $shouldIncludeCounts = $statsEnabled
            && str_contains($serializerClass, 'Flarum\\Api\\Serializer\\UserSerializer');

        if ($shouldIncludeCounts) {
            $followerStart = microtime(true);
            $attributes['followerCount'] = FollowState::getFollowerCount($user);
            \CoroutineSerializerConfig::recordPostSerializerPhase(
                'followUsers.attr.followerCount',
                (microtime(true) - $followerStart) * 1000,
                $serializer,
                $user
            );

            $followingStart = microtime(true);
            $attributes['followingCount'] = FollowState::getFollowingCount($user);
            \CoroutineSerializerConfig::recordPostSerializerPhase(
                'followUsers.attr.followingCount',
                (microtime(true) - $followingStart) * 1000,
                $serializer,
                $user
            );
        } elseif ($statsEnabled) {
            \CoroutineSerializerConfig::recordPostSerializerPhase(
                'followUsers.attr.counts_skipped_basic',
                0.0,
                $serializer,
                $user
            );
        }

        return $attributes;
    }
PHP);

        if ($matched) {
            $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
            try {
                eval($code);
                echo "[Boot] follow-users BasicUserAttributes 细分打点注入成功。\n";
            } catch (\Throwable $e) {
                echo "[Boot] ⚠️ follow-users BasicUserAttributes 打点注入失败: " . $e->getMessage() . "\n";
            }
        } else {
            echo "[Boot] ⚠️ follow-users BasicUserAttributes 打点未匹配。\n";
        }
    }

    private static function installLoadRelationsPatch($composerLoader, bool $isDebugBoot): void
    {
        $followRelationsFile = $composerLoader->findFile('IanM\FollowUsers\Api\LoadRelations');
        if (!$followRelationsFile || !file_exists($followRelationsFile)) {
            echo "[Boot] follow-users LoadRelations::countRelation 注入跳过：目标文件不存在。\n";
            return;
        }

        if (class_exists('IanM\FollowUsers\Api\LoadRelations', false)) {
            echo "[Boot] follow-users LoadRelations::countRelation 注入跳过：目标类已加载。\n";
            return;
        }

        $src = file_get_contents($followRelationsFile);
        [$src, $matched] = \replaceWorkerClassMethod($src, 'countRelation', <<<'PHP'
    public static function countRelation($controller, $data): void
    {
        $controllerClass = is_object($controller) ? get_class($controller) : '';
        $skipCounts = str_contains($controllerClass, 'ListDiscussionsController')
            || str_contains($controllerClass, 'ListPostsController');

        $users = null;

        if ($data instanceof Discussion) {
            $data->loadMissing(['user', 'lastPostedUser']);
            $discussionUsers = array_filter([$data->user, $data->lastPostedUser]);

            $postUsers = $data->relationLoaded('posts')
                ? collect($data->posts)
                    ->filter(fn ($p) => $p instanceof Post)
                    ->map(fn ($p) => $p->user)
                    ->filter()
                    ->all()
                : [];

            $users = (new Collection(array_merge($discussionUsers, $postUsers)))
                ->unique('id')
                ->values();
        } elseif ($data instanceof Collection) {
            if ($data->first() instanceof Discussion) {
                $data->loadMissing(['user', 'lastPostedUser']);
                $users = new Collection(
                    $data->flatMap(fn ($d) => array_filter([$d->user, $d->lastPostedUser]))
                        ->unique('id')->values()->all()
                );
            } elseif ($data->first() instanceof Post) {
                $data->loadMissing('user');
                $users = new Collection(
                    $data->map(fn ($p) => $p->user)
                        ->filter()
                        ->unique('id')
                        ->values()
                        ->all()
                );
            }
        }

        if (!$users || $users->isEmpty()) {
            return;
        }

        if ($skipCounts) {
            if (class_exists('\CoroutineSerializerConfig', false)) {
                \CoroutineSerializerConfig::recordBeforeSerializationPhase(
                    'followUsers.countRelation.skipCounts:' . $controllerClass . ':users' . $users->count(),
                    0.0
                );
            }
            return;
        }

        $users->loadCount(['followedUsers', 'followedBy']);

        foreach ($users as $user) {
            FollowState::seedCountCache(
                (int) $user->id,
                (int) ($user->followed_by_count ?? 0),
                (int) ($user->followed_users_count ?? 0)
            );
        }
    }
PHP);

        if ($matched) {
            $code = preg_replace('/^.*?<\?php\s*/is', '', $src);
            try {
                eval($code);
                echo "[Boot] follow-users LoadRelations::countRelation 列表 count 跳过补丁注入成功。\n";
            } catch (\Throwable $e) {
                echo "[Boot] ⚠️ follow-users LoadRelations::countRelation 补丁注入失败: " . $e->getMessage() . "\n";
            }
        } else {
            echo "[Boot] ⚠️ follow-users LoadRelations::countRelation 补丁未匹配。\n";
        }
    }
}
