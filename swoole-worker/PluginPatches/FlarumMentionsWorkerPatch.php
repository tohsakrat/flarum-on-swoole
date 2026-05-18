<?php
declare(strict_types=1);

final class FlarumMentionsWorkerPatch
{
    public static function prepareForResources(array $resources): void
    {
        if (\Swoole\Coroutine::getCid() <= 0) {
            return;
        }

        $posts = [];
        $wantedRelations = [];

        foreach ($resources as $resource) {
            $post = self::getPostFromResource($resource);
            if ($post === null) {
                continue;
            }

            $relations = self::relationsNeededForContent($post);
            if (empty($relations)) {
                continue;
            }

            self::clearBrokenLoadedRelation($post, 'mentionsPosts');
            self::clearBrokenLoadedRelation($post, 'mentionsUsers');
            self::clearBrokenLoadedRelation($post, 'mentionsGroups');

            foreach ($relations as $relation) {
                $wantedRelations[$relation] = true;
            }

            if (isset($post->id)) {
                $posts[(int) $post->id] = $post;
            } else {
                $posts[] = $post;
            }
        }

        if (empty($posts)) {
            return;
        }

        try {
            $first = reset($posts);
            $relations = self::availableRelations($first, array_keys($wantedRelations));
            if (empty($relations)) {
                return;
            }

            $collection = method_exists($first, 'newCollection')
                ? $first->newCollection(array_values($posts))
                : new \Illuminate\Database\Eloquent\Collection(array_values($posts));

            $collection->loadMissing($relations);
            if (in_array('mentionsPosts.discussion', $relations, true)) {
                self::clearBrokenMentionedPostDiscussions($collection);
                $collection->loadMissing('mentionsPosts.discussion');
            }
        } catch (\Throwable $e) {
            if (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') {
                echo '[Boot] Mentions relation preload skipped: ' . $e->getMessage() . "\n";
            }
        }
    }

    private static function getPostFromResource($resource): ?object
    {
        $serializer = \CoroutineSerializerConfig::getResourceSerializerForPatch($resource);
        if (!$serializer) {
            return null;
        }

        $serializerClass = get_class($serializer);
        if (!str_contains($serializerClass, 'Flarum\\Api\\Serializer\\PostSerializer')
            && !str_contains($serializerClass, 'Flarum\\Api\\Serializer\\BasicPostSerializer')) {
            return null;
        }

        $data = method_exists($resource, 'getData') ? $resource->getData() : null;
        if (!is_object($data) || !str_contains(get_class($data), 'Flarum\\Post\\')) {
            return null;
        }

        return $data;
    }

    private static function relationsNeededForContent(object $post): array
    {
        $content = null;

        try {
            if (method_exists($post, 'getAttribute')) {
                $content = $post->getAttribute('content');
            } elseif (isset($post->content)) {
                $content = $post->content;
            }
        } catch (\Throwable $e) {
            $content = null;
        }

        if (!is_string($content) || $content === '') {
            return [];
        }

        $relations = [];
        if (str_contains($content, '<POSTMENTION')) {
            $relations[] = 'mentionsPosts';
            $relations[] = 'mentionsPosts.user';
            $relations[] = 'mentionsPosts.discussion';
        }
        if (str_contains($content, '<USERMENTION')) {
            $relations[] = 'mentionsUsers';
        }
        if (str_contains($content, '<GROUPMENTION')) {
            $relations[] = 'mentionsGroups';
        }

        return $relations;
    }

    private static function availableRelations(object $post, array $wantedRelations): array
    {
        if (!class_exists('Flarum\\Database\\AbstractModel', false)) {
            return [];
        }

        $customRelations = \Flarum\Database\AbstractModel::$customRelations ?? [];
        $classes = array_merge([get_class($post)], class_parents($post) ?: []);
        $available = [];

        foreach ($wantedRelations as $relation) {
            $root = strtok($relation, '.');
            foreach ($classes as $class) {
                if (isset($customRelations[$class][$root])) {
                    $available[] = $relation;
                    break;
                }
            }
        }

        return $available;
    }

    private static function clearBrokenLoadedRelation(object $model, string $relation): void
    {
        if (!method_exists($model, 'relationLoaded')
            || !$model->relationLoaded($relation)
            || !method_exists($model, 'getRelation')
            || $model->getRelation($relation) !== null) {
            return;
        }

        if (method_exists($model, 'unsetRelation')) {
            $model->unsetRelation($relation);
        }
    }

    private static function clearBrokenMentionedPostDiscussions($posts): void
    {
        foreach ($posts as $post) {
            if (!method_exists($post, 'relationLoaded') || !$post->relationLoaded('mentionsPosts')) {
                continue;
            }

            $mentionedPosts = method_exists($post, 'getRelation') ? $post->getRelation('mentionsPosts') : null;
            if (!is_iterable($mentionedPosts)) {
                continue;
            }

            foreach ($mentionedPosts as $mentionedPost) {
                if (!is_object($mentionedPost) || empty($mentionedPost->discussion_id)) {
                    continue;
                }

                if (method_exists($mentionedPost, 'relationLoaded')
                    && $mentionedPost->relationLoaded('discussion')
                    && method_exists($mentionedPost, 'getRelation')
                    && $mentionedPost->getRelation('discussion') === null
                    && method_exists($mentionedPost, 'unsetRelation')) {
                    $mentionedPost->unsetRelation('discussion');
                }
            }
        }
    }
}
