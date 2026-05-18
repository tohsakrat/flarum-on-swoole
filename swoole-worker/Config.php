<?php
declare(strict_types=1);


/** 监听地址：Unix Socket 路径（由 Nginx 反代）或 TCP 地址（如 '0.0.0.0'） */
const SWOOLE_LISTEN_HOST = '/www/wwwroot/tmp/flarum.sock';

/** Worker 进程数量（建议 = 并发页面 API 请求数 × 1.5，通常 6~10） */
const SWOOLE_WORKER_COUNT =  4;

/** PHP 内存上限（峰值在清除缓存触发 LESS 编译时可达 ~1GB） */
const PHP_MEMORY_LIMIT = '2048M';

/** 单 Worker 内存水位（超过后平滑重启以清理堆碎片，单位：字节） */
const WORKER_MEMORY_WATERMARK = 128 * 1024 * 1024; // 256 MB

/** Worker 定时轮换间隔（防止长期运行内存碎片化，单位：小时） */
const WORKER_RECYCLE_HOURS = 0.5;

/** DB 心跳间隔（防止 MySQL wait_timeout 断连，单位：小时） */
const DB_HEARTBEAT_HOURS = 1;

/** DB / Redis 协程连接池大小（每个 Worker 持有的最大连接数） */
const POOL_SIZE = 40;

/** DB 连接借出前的最小校验间隔（秒），避免每个请求都 SELECT 1 */
const DB_CONNECTION_VALIDATE_SECONDS = 30;

/** Post contentHtml 进程内静态缓存上限 */
const POST_HTML_CACHE_LIMIT = 4096;

/** User signature/profile HTML 进程内静态缓存上限 */
const USER_HTML_CACHE_LIMIT = 4096;

/** katosdev/signature HTML 进程内静态缓存上限 */
const SIGNATURE_HTML_CACHE_LIMIT = 4096;

/** Post contentHtml Redis 共享缓存 TTL（秒） */
const POST_HTML_REDIS_TTL = 604800;

/** User signature/profile HTML Redis 共享缓存 TTL（秒） */
const USER_HTML_REDIS_TTL = 604800;

/** katosdev/signature HTML Redis 共享缓存 TTL（秒） */
const SIGNATURE_HTML_REDIS_TTL = 604800;

/** 单次请求预取 contentHtml 的最大帖子数，避免异常大 included 列表打爆 Redis 命令 */
const POST_HTML_REDIS_PREFETCH_LIMIT = 128;

/** LSCache Redis DB；null = 自动选择一个不同于论坛主 Redis/session 的库 */
const LSCACHE_REDIS_DATABASE = null;

/** Fragment Redis DB；用于 contentHtml/signature 等渲染片段。null = 自动选择一个不同于论坛主 Redis/session/LSCache 的库 */
const FRAGMENT_CACHE_REDIS_DATABASE = null;

/** Fragment purge endpoint token；空字符串表示禁用 /__swoole/fragment-cache/purge */
const FRAGMENT_CACHE_PURGE_TOKEN = '';

/** Tag JSON:API resource 进程内缓存上限 */
const TAG_RESOURCE_CACHE_LIMIT = 2048;

/** LSCache 功能开关（true = 启用游客缓存加速，false = 禁用） */
const LSCACHE_ENABLED =true;

/** 默认缓存 TTL（秒），Flarum 的 X-LiteSpeed-Cache-Control max-age 优先 */
const LSCACHE_DEFAULT_TTL = 604800; // 7 天

/** Flarum 根目录；worker 目录下看 Flarum 入口是 ../public/index.php */
const WORKER_BASE_DIR = __DIR__ . '/..';

/** Early Hints & CDN 配置区 */
const MANIFEST_PATH = WORKER_BASE_DIR . '/public/assets/rev-manifest.json';
const CDN_PREFIX = 'https://cdn.klezik-insi.de';
const ORIGIN_DOMAIN = 'klezik-insi.de';


/** 协程并发序列化开关（true = 启用 WaitGroup 并发，对 Admin 面板效果显著） */
const COROUTINE_SERIALIZER_ENABLED = true;

/** Document included 并发序列化开关；路由切换若出现前端 vnode 异常，可先单独关闭这个而保留 Collection 并发 */
const COROUTINE_DOCUMENT_INCLUDED_ENABLED = true;

/**
 * 日志级别：
 * 'info'  - 只打印启停信息和访问日志
 * 'debug' - 额外打印数据库请求中【未合并】(BYPASSED) 的 SQL 语句，用于分析优化空间
 */
const LOG_LEVEL = 'info';
