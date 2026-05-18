<?php
declare(strict_types=1);

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface as PsrResponse;

// 工具函数
// ============================================================
function buildLSCacheKey(SwooleRequest $req): string
{
    $host = $req->header['host'] ?? 'localhost';
    $uri = $req->server['request_uri'] ?? '/';
    $dropQs = ['fbclid', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', '_ga', 'uptime_kuma_cachebuster'];
    $queryParams = $req->get ?? [];
    foreach ($dropQs as $drop) {
        unset($queryParams[$drop]);
    }
    ksort($queryParams);
    $qs = http_build_query($queryParams);
    $locale = $req->cookie['locale'] ?? 'default';
    $vary = $req->cookie['flarum_lscache_vary'] ?? 'none';
    $remember = $req->cookie['flarum_remember'] ?? 'guest';
    $signature = "host={$host}&uri={$uri}&qs={$qs}&locale={$locale}&vary={$vary}&remember={$remember}";
    return 'lscache:page:' . md5($signature);
}

function buildPsr7Request(SwooleRequest $req): ServerRequest
{
    $method = strtoupper($req->server['request_method'] ?? 'GET');
    $uri = $req->server['request_uri'] ?? '/';
    if (!empty($req->server['query_string'])) {
        $uri .= '?' . $req->server['query_string'];
    }
    $headers = $req->header ?? [];
    $host = $headers['host'] ?? 'localhost';
    $scheme = $headers['x-forwarded-proto'] ?? 'http';
    $psrUri = new Uri($scheme . '://' . $host . $uri);

    $cookies = $req->cookie ?? [];
    $queryParams = $req->get ?? [];

    $rawBody = $req->rawContent() ?: '';
    $stream = new Stream('php://temp', 'wb+');
    if ($rawBody !== '') {
        $stream->write($rawBody);
        $stream->rewind();
    }

    $psrHeaders = [];
    foreach ($headers as $name => $value) {
        $psrHeaders[$name] = [$value];
    }

    $serverParams = array_change_key_case($req->server ?? [], CASE_UPPER);
    $serverParams['HTTP_HOST'] = $host;
    $serverParams['REMOTE_ADDR'] = $req->server['remote_addr'] ?? '127.0.0.1';
    if (isset($headers['x-forwarded-for'])) {
        $serverParams['REMOTE_ADDR'] = trim(explode(',', $headers['x-forwarded-for'])[0]);
    }

    $uploadedFiles = buildUploadedFiles($req->files ?? []);

    return new ServerRequest(
        $serverParams,
        $uploadedFiles,
        $psrUri,
        $method,
        $stream,
        $psrHeaders,
        $cookies,
        $queryParams,
        $req->post ?? null
    );
}

function buildUploadedFiles(array $files): array
{
    $result = [];
    foreach ($files as $key => $value) {
        if (isset($value['tmp_name']) && is_string($value['tmp_name'])) {
            $result[$key] = new \Laminas\Diactoros\UploadedFile(
                $value['tmp_name'],
                $value['size'] ?? 0,
                $value['error'] ?? UPLOAD_ERR_OK,
                $value['name'] ?? null,
                $value['type'] ?? null
            );
        } elseif (isset($value['tmp_name']) && is_array($value['tmp_name'])) {
            $result[$key] = [];
            foreach ($value['tmp_name'] as $idx => $tmpPath) {
                $result[$key][$idx] = new \Laminas\Diactoros\UploadedFile(
                    $tmpPath,
                    $value['size'][$idx] ?? 0,
                    $value['error'][$idx] ?? UPLOAD_ERR_OK,
                    $value['name'][$idx] ?? null,
                    $value['type'][$idx] ?? null
                );
            }
        } else {
            $result[$key] = buildUploadedFiles($value);
        }
    }
    return $result;
}

/**
 * CDN URL 重写：原汁原味移植自 Node.js 代理的四段替换逻辑。
 *
 * Node.js 原始代码顺序：
 * 1. content.replace(/(['"])\/assets\//g, `$1CDN/assets/`)       // 引号内纯路径
 * 2. content.replace(/https?:\/\/ORIGIN\/assets\//g, CDN)          // 普通 URL
 * 3. content.replace(domainRegex with \/ support, callback)       // 转义斜杠 URL
 * 4. content.replace(assetPathPattern with \/ support, callback)  // 转义斜杠路径
 */
function applyCdnRewrite(string $content): string
{
    $cdn    = CDN_PREFIX;
    $domain = ORIGIN_DOMAIN;
    $debug  = defined('LOG_LEVEL') && LOG_LEVEL === 'debug';
    $hasAssets = str_contains($content, '/assets/') || str_contains($content, '\/assets\/');

    // if ($debug) {
    //     error_log('[CDN-Rewrite] Enter. len=' . strlen($content) . ' has_assets=' . ($hasAssets ? 'yes' : 'no'));
    // }

    if (!$hasAssets) {
        return $content;
    }

    // Step 1: 引号内纯路径 '/assets/' 或 "/assets/"
    $count1 = 0;
    $content = preg_replace("#(['\"])/assets/#", '$1' . $cdn . '/assets/', $content, -1, $count1);
    // if ($debug) error_log('[CDN-Rewrite] Step1 (quote+path): replacements=' . $count1);

    // Step 2: 完整 URL http(s)://ORIGIN_DOMAIN/assets/
    $count2 = 0;
    $content = preg_replace(
        '#https?://' . preg_quote($domain, '#') . '/assets/#',
        $cdn . '/assets/',
        $content, -1, $count2
    );
    // if ($debug) error_log('[CDN-Rewrite] Step2 (http://domain/assets/): replacements=' . $count2);

    // Step 3: 完整 URL 的 JSON 转义斜杠形式 http(s):\/\/ORIGIN\/assets\/
    $escapedDomain = preg_quote($domain, '#');
    $slashPat      = '(?:\\\\/|/)';
    $domainRegex   = '#https?' . $slashPat . '{2}' . $escapedDomain . $slashPat . 'assets' . $slashPat . '#';
    $count3 = 0;
    $content = preg_replace_callback($domainRegex, function (array $m) use ($cdn, &$count3): string {
        $count3++;
        if (str_contains($m[0], '\/')) {
            return str_replace('/', '\/', $cdn) . '\/assets\/';
        }
        return $cdn . '/assets/';
    }, $content);
    // if ($debug) error_log('[CDN-Rewrite] Step3 (escaped domain URL): replacements=' . $count3);

    // Step 4: 引号内纯路径的 JSON 转义斜杠形式 '\/assets\/'
    $assetPat = '#([\'"])' . $slashPat . 'assets' . $slashPat . '#';
    $count4 = 0;
    $content = preg_replace_callback($assetPat, function (array $m) use ($cdn, &$count4): string {
        $count4++;
        $quote = $m[1];
        if (str_contains($m[0], '\/')) {
            return $quote . str_replace('/', '\/', $cdn) . '\/assets\/';
        }
        return $quote . $cdn . '/assets/';
    }, $content);
    // if ($debug) error_log('[CDN-Rewrite] Step4 (escaped quote+path): replacements=' . $count4);

    return $content;
}

function emitResponse(PsrResponse $psrResponse, SwooleResponse $swooleRes): void
{
    $swooleRes->status($psrResponse->getStatusCode());
    foreach ($psrResponse->getHeaders() as $name => $values) {
        $lowerName = strtolower($name);
        if ($lowerName === 'set-cookie') {
            foreach ($values as $cookie) {
                $swooleRes->header('Set-Cookie', $cookie, false);
            }
        } else {
            $swooleRes->header($name, implode(', ', $values));
        }
    }
    $body = $psrResponse->getBody();
    $body->rewind();
    $rawBody = $body->getContents();

// Node.js 代理对所有响应体无差别地做正则替换，这里仅跳过已知二进制类型
    $ct = strtolower($psrResponse->getHeaderLine('Content-Type'));
    $isBinary = str_starts_with($ct, 'image/')
        || str_starts_with($ct, 'video/')
        || str_starts_with($ct, 'audio/')
        || str_starts_with($ct, 'font/')
        || $ct === 'application/octet-stream'
        || $ct === 'application/wasm';
    if (!$isBinary) {
        // if (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') {
        //     error_log('[CDN-Rewrite] emitResponse: ct=' . $ct . ', isBinary=no, applying rewrite');
        // }
        $rawBody = applyCdnRewrite($rawBody);
    } elseif (defined('LOG_LEVEL') && LOG_LEVEL === 'debug') {
        // error_log('[CDN-Rewrite] emitResponse: ct=' . $ct . ', isBinary=YES, skipping rewrite');
    }

    $swooleRes->end($rawBody);
}

function logRequest(SwooleRequest $req, int $statusCode, float $durationSec): void
{
    $workerId = $GLOBALS['flarum_worker_id'] ?? '?';
    $method = $req->server['request_method'] ?? 'GET';
    $uri = $req->server['request_uri'] ?? '/';
    $qs = $req->server['query_string'] ?? '';
    $fullUri = $qs ? "{$uri}?{$qs}" : $uri;
    $ip = $req->header['x-forwarded-for'] ?? $req->server['remote_addr'] ?? '-';
    $durationMs = number_format($durationSec * 1000, 2);
    $time = date('d/M/Y:H:i:s O');
    echo "[{$time}] [W#{$workerId}] {$ip} \"{$method} {$fullUri}\" {$statusCode} {$durationMs}ms\n";
}

$GLOBALS['early_hints_manifest'] = [];
$GLOBALS['early_hints_mtime'] = 0;

function getEarlyHintsAssets(string $url): array {
    $manifestPath = MANIFEST_PATH;
    if (file_exists($manifestPath)) {
        $mtime = filemtime($manifestPath);
        if ($mtime !== $GLOBALS['early_hints_mtime']) {
            $content = file_get_contents($manifestPath);
            $GLOBALS['early_hints_manifest'] = function_exists('swoole_fast_json_decode')
                ? (swoole_fast_json_decode($content, true) ?: [])
                : (json_decode($content, true) ?: []);
            $GLOBALS['early_hints_mtime'] = $mtime;
        }
    }

    $manifest = $GLOBALS['early_hints_manifest'];
    if (empty($manifest)) return [];

    $isAdmin = str_contains($url, 'admin');
    $assets = [];
    foreach ($manifest as $n => $h) {
        if ($h === 'empty') continue;
        if ($isAdmin ? str_contains($n, 'admin') : !str_contains($n, 'admin')) {
            $assets[] = [$n, $h];
        }
    }

    usort($assets, function($a, $b) {
        $aIsForum = str_contains($a[0], 'forum.js');
        $bIsForum = str_contains($b[0], 'forum.js');
        if ($aIsForum) return -1;
        if ($bIsForum) return 1;
        return 0;
    });

    return $assets;
}

