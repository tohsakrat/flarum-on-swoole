<?php
declare(strict_types=1);

final class FoFIgnoreUsersWorkerPatch
{
    private const RELATION = 'ignoredUsers';

    public static function prepareForResources(array $resources): void
    {
        if (\Swoole\Coroutine::getCid() <= 0 || !self::hasUserSerializerResource($resources)) {
            return;
        }

        foreach ($resources as $resource) {
            $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
            if ($serializer && method_exists($serializer, 'getActor')) {
                self::ensureActorIgnoredUsersReady($serializer->getActor());
                return;
            }
        }
    }

    public static function ensureResourceActorIgnoredUsersReady($resource): void
    {
        if (\Swoole\Coroutine::getCid() <= 0 || !self::isUserSerializerResource($resource)) {
            return;
        }

        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
        if ($serializer && method_exists($serializer, 'getActor')) {
            self::ensureActorIgnoredUsersReady($serializer->getActor());
        }
    }

    private static function ensureActorIgnoredUsersReady($actor): void
    {
        if (!self::canHaveIgnoredUsersRelation($actor)) {
            return;
        }

        try {
            if (method_exists($actor, 'isGuest') && $actor->isGuest()) {
                if (!self::hasUsableRelation($actor) && method_exists($actor, 'setRelation')) {
                    $actor->setRelation(self::RELATION, self::emptyUserCollection($actor));
                }
                return;
            }

            if (self::hasUsableRelation($actor)) {
                return;
            }

            if (method_exists($actor, 'relationLoaded')
                && $actor->relationLoaded(self::RELATION)
                && method_exists($actor, 'getRelation')
                && $actor->getRelation(self::RELATION) === null) {
                if (method_exists($actor, 'unsetRelation')) {
                    $actor->unsetRelation(self::RELATION);
                } elseif (method_exists($actor, 'setRelation')) {
                    $actor->setRelation(self::RELATION, self::emptyUserCollection($actor));
                    return;
                }
            }

            if (method_exists($actor, 'loadMissing')) {
                $actor->loadMissing(self::RELATION);
            }

            if (self::hasUsableRelation($actor)) {
                return;
            }

            $ignoredUsers = null;
            try {
                $ignoredUsers = method_exists($actor, 'getAttribute')
                    ? $actor->getAttribute(self::RELATION)
                    : null;
            } catch (\Throwable $e) {
                $ignoredUsers = null;
            }

            if (is_object($ignoredUsers) && method_exists($ignoredUsers, 'contains')) {
                if (method_exists($actor, 'setRelation')) {
                    $actor->setRelation(self::RELATION, $ignoredUsers);
                }
                return;
            }

            if (is_callable([$actor, 'ignoredUsers'])) {
                $relation = $actor->ignoredUsers();
                if (is_object($relation) && method_exists($relation, 'getResults')) {
                    $ignoredUsers = $relation->getResults();
                    if (is_object($ignoredUsers) && method_exists($ignoredUsers, 'contains') && method_exists($actor, 'setRelation')) {
                        $actor->setRelation(self::RELATION, $ignoredUsers);
                        return;
                    }
                }
            }

            if (method_exists($actor, 'relationLoaded')
                && $actor->relationLoaded(self::RELATION)
                && method_exists($actor, 'getRelation')
                && $actor->getRelation(self::RELATION) === null
                && method_exists($actor, 'setRelation')) {
                $actor->setRelation(self::RELATION, self::emptyUserCollection($actor));
            }
        } catch (\Throwable $e) {
            // Keep the original serializer path in control if the relation itself fails to load.
        }
    }

    private static function hasUserSerializerResource(array $resources): bool
    {
        foreach ($resources as $resource) {
            if (self::isUserSerializerResource($resource)) {
                return true;
            }
        }

        return false;
    }

    private static function isUserSerializerResource($resource): bool
    {
        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
        return $serializer && is_a($serializer, 'Flarum\\Api\\Serializer\\UserSerializer');
    }

    private static function canHaveIgnoredUsersRelation($actor): bool
    {
        if (!is_object($actor) || !str_contains(get_class($actor), 'Flarum\\User\\User')) {
            return false;
        }

        if (!class_exists('Flarum\\Database\\AbstractModel', false)) {
            return false;
        }

        $customRelations = \Flarum\Database\AbstractModel::$customRelations ?? [];
        foreach (array_merge([get_class($actor)], class_parents($actor)) as $class) {
            if (isset($customRelations[$class][self::RELATION])) {
                return true;
            }
        }

        return false;
    }

    private static function hasUsableRelation($actor): bool
    {
        if (!method_exists($actor, 'relationLoaded') || !$actor->relationLoaded(self::RELATION) || !method_exists($actor, 'getRelation')) {
            return false;
        }

        $relation = $actor->getRelation(self::RELATION);

        return is_object($relation) && method_exists($relation, 'contains');
    }

    private static function emptyUserCollection($actor)
    {
        if (is_object($actor) && method_exists($actor, 'newCollection')) {
            return $actor->newCollection([]);
        }

        return new \Illuminate\Database\Eloquent\Collection();
    }
}
