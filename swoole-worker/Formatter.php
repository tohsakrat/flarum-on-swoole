<?php
declare(strict_types=1);

// 视图引擎魔术代理 (解决 Flarum 视图 $shared 串号问题)
// ============================================================
class CachedPostFormatter extends \Flarum\Formatter\Formatter {
    private \Flarum\Formatter\Formatter $inner;

    public function __construct(\Flarum\Formatter\Formatter $inner) {
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
        \PostHtmlCache::clear();
        if (class_exists('UserHtmlCache', false)) {
            \UserHtmlCache::clear();
        }
        if (class_exists('SignatureHtmlCache', false)) {
            \SignatureHtmlCache::clear();
        }
        return $this->inner->flush();
    }

    public function render($xml, $context = null, \Psr\Http\Message\ServerRequestInterface $request = null) {
        if ($context instanceof \Flarum\Post\CommentPost && is_string($xml) && $xml !== '') {
            $key = \PostHtmlCache::makeKey($context, $xml);
            if ($key !== null) {
                $profileStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                $cached = \PostHtmlCache::get($key);
                if ($cached !== null) {
                    if ($profileStart > 0) {
                        \CoroutineSerializerConfig::recordPostSerializerPhase("contentHtml.l1_hit", (microtime(true) - $profileStart) * 1000, null, $context);
                    }
                    return $cached;
                }

                $cached = \PostHtmlCache::getRequestPrefetch($key);
                if ($cached !== null) {
                    \PostHtmlCache::set($key, $cached);
                    if ($profileStart > 0) {
                        \CoroutineSerializerConfig::recordPostSerializerPhase("contentHtml.redis_prefetch_hit", (microtime(true) - $profileStart) * 1000, null, $context);
                    }
                    return $cached;
                }

                \PostHtmlCache::rememberRequestMiss($key);

                $redis = \FragmentCache::getRedis();
                if ($redis) {
                    try {
                        $redisStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                        $cached = $redis->get(\PostHtmlCache::redisKey($key));
                        if (is_string($cached) && $cached !== '') {
                            \PostHtmlCache::set($key, $cached);
                            \PostHtmlCache::setRequestPrefetch($key, $cached);
                            if ($redisStart > 0) {
                                \CoroutineSerializerConfig::recordPostSerializerPhase("contentHtml.redis_hit", (microtime(true) - $redisStart) * 1000, null, $context);
                            }
                            return $cached;
                        }
                    } catch (\Throwable $e) {}
                }

                $renderStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                $html = $this->inner->render($xml, $context, $request);
                if ($renderStart > 0) {
                    \CoroutineSerializerConfig::recordPostSerializerPhase("contentHtml.cache_miss_render", (microtime(true) - $renderStart) * 1000, null, $context);
                }
                \PostHtmlCache::setShared($key, $html);

                return $html;
            }
        }

        if ($context instanceof \Flarum\User\User && is_string($xml) && $xml !== '') {
            $key = \UserHtmlCache::makeKey($context, $xml);
            if ($key !== null) {
                $profileStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                $cached = \UserHtmlCache::get($key);
                if ($cached !== null) {
                    if ($profileStart > 0) {
                        \CoroutineSerializerConfig::recordPostSerializerPhase("userHtml.l1_hit", (microtime(true) - $profileStart) * 1000, null, $context);
                    }
                    return $cached;
                }

                $redis = \FragmentCache::getRedis();
                if ($redis) {
                    try {
                        $redisStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                        $cached = $redis->get(\UserHtmlCache::redisKey($key));
                        if (is_string($cached) && $cached !== '') {
                            \UserHtmlCache::set($key, $cached);
                            if ($redisStart > 0) {
                                \CoroutineSerializerConfig::recordPostSerializerPhase("userHtml.redis_hit", (microtime(true) - $redisStart) * 1000, null, $context);
                            }
                            return $cached;
                        }
                    } catch (\Throwable $e) {}
                }

                $renderStart = (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') ? microtime(true) : 0.0;
                $html = $this->inner->render($xml, $context, $request);
                if ($renderStart > 0) {
                    \CoroutineSerializerConfig::recordPostSerializerPhase("userHtml.cache_miss_render", (microtime(true) - $renderStart) * 1000, null, $context);
                }
                \UserHtmlCache::setShared($key, $html);

                return $html;
            }
        }

        return $this->inner->render($xml, $context, $request);
    }
}



