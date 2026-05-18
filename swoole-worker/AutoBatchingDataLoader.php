<?php

class AutoBatchingMySqlConnection extends \Illuminate\Database\MySqlConnection
{
    public function __construct($pdo, $database = '', $tablePrefix = '', array $config = [])
    {
        parent::__construct($pdo, $database, $tablePrefix, $config);
    }

    public function nativeSelect($query, $bindings = [], $useReadPdo = true)
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx) $ctx['query_executed'] = true;
            
            if (isset($GLOBALS['coroutine_db_proxy'])) {
                $realConn = $GLOBALS['coroutine_db_proxy']->getRealConnection();
                if ($realConn instanceof \Illuminate\Database\Connection) {
                    $fn = \Closure::bind(
                        function($q, $b, $r) { return parent::select($q, $b, $r); },
                        $realConn,
                        \Illuminate\Database\MySqlConnection::class
                    );
                    return $fn($query, $bindings, $useReadPdo);
                }
            }
        }
        return parent::select($query, $bindings, $useReadPdo);
    }

    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $cid = \Swoole\Coroutine::getCid();
        if ($cid > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx) $ctx['query_executed'] = true;
        }
        
        // 极速拦截：如果此协程在前置 LSCache 判断时已经查过了当前用户的 Token，直接缓存命中返回！
        if ($cid > 0 && !empty($bindings)) {
            $ctx = \Swoole\Coroutine::getContext();
            if ($ctx && isset($ctx['prefetched_access_token'])) {
                if (preg_match('/^\s*select\s+\*\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(`?[a-zA-Z0-9_]+`?\.)?`?token`?\s*=\s*\?\s+limit\s+1\s*$/i', $query)) {
                    $tokenObj = $ctx['prefetched_access_token'];
                    if ($bindings[0] === $tokenObj->token) {
                        return [ $tokenObj ];
                    }
                }
            }
        }

        $isMatch = false;
        $reason = "No Regex Match";
        
        $meta = [
            'type' => 'normal'
        ];

        // 特殊指纹识别：拦截 EXISTS 子查询并转换为 IN 查询
        if (preg_match('/^\s*select\s+exists\s*\(\s*select\s+\*\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+.*?(?:`?[a-zA-Z0-9_]+`?\.)?(`?[a-zA-Z0-9_]+`?)\s*=\s*\?.*?\)\s+as\s+(`?[a-zA-Z0-9_]+`?)$/i', $query, $matches)) {
            $meta['type'] = 'exists';
            $meta['existsTable'] = $matches[1];
            $meta['existsColumn'] = $matches[2];
            $meta['existsAlias'] = $matches[3];
            $isMatch = true;
            if ($cid <= 0) {
                $reason = "Not in Coroutine (CID <= 0)";
                $isMatch = false;
            }
        } 
        // 聚合1: 单纯 count(*) -> 用于 FoF\ModeratorNotes, FoF\Drafts, IanM\FollowUsers
        else if (preg_match('/^\s*select\s+count\(\*\)\s+as\s+(`?[a-zA-Z0-9_]+`?)\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(?:`?[a-zA-Z0-9_]+`?\.)?(`?[a-zA-Z0-9_]+`?)\s*=\s*\?\s*$/i', $query, $matches)) {
            $meta['type'] = 'count';
            $meta['countAlias'] = $matches[1];
            $meta['countTable'] = $matches[2];
            $meta['countColumn'] = $matches[3];
            $isMatch = true;
            if ($cid <= 0) {
                $reason = "Not in Coroutine (CID <= 0)";
                $isMatch = false;
            }
        }
        // 聚合2: 带一列 group by 的 count(*) -> 用于 FoF\Reactions
        else if (preg_match('/^\s*select\s+(`?[a-zA-Z0-9_]+`?)\s*,\s*count\(\*\)\s+as\s+(`?[a-zA-Z0-9_]+`?)\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(?:`?[a-zA-Z0-9_]+`?\.)?(`?[a-zA-Z0-9_]+`?)\s*=\s*\?\s+group\s+by\s+`?[a-zA-Z0-9_]+`?\s*$/i', $query, $matches)) {
            $meta['type'] = 'group_count';
            $meta['groupColumn'] = $matches[1];
            $meta['countAlias'] = $matches[2];
            $meta['countTable'] = $matches[3];
            $meta['countColumn'] = $matches[4];
            $isMatch = true;
            if ($cid <= 0) {
                $reason = "Not in Coroutine (CID <= 0)";
                $isMatch = false;
            }
        }
        // 核心指纹识别：支持单表 WHERE 条件（原有逻辑）
        else if (preg_match('/^\s*select\s+(.+?)\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(.+?\?.*)$/i', $query, $matches)) {
            $selectClause = $matches[1];
            
            if (preg_match('/(exists|count|sum|avg|max|min)\s*\(/i', $selectClause)) {
                $reason = "Aggregation query ($selectClause) cannot be mapped safely";
            } else {
                $isMatch = true;
                if ($cid <= 0) {
                    $reason = "Not in Coroutine (CID <= 0)";
                    $isMatch = false;
                }
            }
        }
        // JOIN 查询支持：FROM 子句含有 JOIN，但 WHERE 里只有一个简单的 col = ? 条件
        // 典型例子：select tgroups.* from tgroups inner join tgroup_user on ... where tgroup_user.user_id = ?
        // rewriting 逻辑（= 改 IN）完全兼容 JOIN，只需要放开 FROM 正则即可！
        else if (!empty($bindings) && preg_match('/^\s*select\s+(.+?)\s+from\s+.+?\s+(?:inner|left|right|cross)?\s*join\s+.+?\s+where\s+(.+?\?.*)$/is', $query, $matches)) {
            $selectClause = $matches[1];
            if (preg_match('/(exists|count|sum|avg|max|min)\s*\(/i', $selectClause)) {
                $reason = "Aggregation query ($selectClause) cannot be mapped safely";
            } else {
                $isMatch = true;
                if ($cid <= 0) {
                    $reason = "Not in Coroutine (CID <= 0)";
                    $isMatch = false;
                }
            }
        }
        // 静态全表查询：没有任何参数的 select (例如 select * from treactions)
        else if (empty($bindings) && preg_match('/^\s*select\s+(.+?)\s+from\s+.+?(?:\s+where\s+.*)?$/is', $query, $matches)) {
            $selectClause = $matches[1];
            if (preg_match('/(exists|count|sum|avg|max|min)\s*\(/i', $selectClause)) {
                $reason = "Aggregation query ($selectClause) cannot be mapped safely";
            } else {
                $isMatch = true;
                if ($cid <= 0) {
                    $reason = "Not in Coroutine (CID <= 0)";
                    $isMatch = false;
                }
            }
        }

        if ($isMatch) {
            return AutoBatchingDataLoader::load($this, $query, $bindings, $useReadPdo, $meta);
        }

        if (!$isMatch && defined('LOG_LEVEL') && LOG_LEVEL === 'debug') {
            $shortQuery = substr($query, 0, 150) . (strlen($query) > 150 ? '...' : '');
            
            // 获取调用栈以查明幕后真凶（追踪两层，避免只显示底层封装类）
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
            $callers = [];
            foreach ($trace as $t) {
                // 跳过 Laravel 和 Swoole 代理的调用
                if (isset($t['class']) && (
                    strpos($t['class'], 'Illuminate\\Database') !== false ||
                    strpos($t['class'], 'AutoBatching') !== false ||
                    strpos($t['class'], 'CoroutineDbProxy') !== false ||
                    strpos($t['class'], 'Flarum\\Database\\AbstractModel') !== false
                )) {
                    continue;
                }
                $class = $t['class'] ?? '';
                $type = $t['type'] ?? '';
                $func = $t['function'] ?? '';
                if ($class) {
                    $callers[] = $class . $type . $func;
                    if (count($callers) >= 2) break;
                }
            }
            $callerInfo = implode(' <- ', $callers);
            if (empty($callerInfo)) $callerInfo = 'Unknown caller';
            
            echo "[AutoBatching] ❌ BYPASSED (CID:{$cid}) [$reason] by $callerInfo: $shortQuery\n";
        }

        return parent::select($query, $bindings, $useReadPdo);
    }
    
    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        $records = $this->select($query, $bindings, $useReadPdo);
        return count($records) > 0 ? reset($records) : null;
    }
}

class AutoBatchingDataLoader
{
    private static array $batches = [];

    private static function quoteIdent(string $identifier): string
    {
        $parts = array_map(
            fn($part) => '`' . str_replace('`', '', $part) . '`',
            explode('.', str_replace('`', '', $identifier))
        );

        return implode('.', $parts);
    }

    private static function extractBatchColumn(string $query, int $diffIndex): ?string
    {
        $parts = explode('?', $query);
        if (!isset($parts[$diffIndex])) {
            return null;
        }

        $left = $parts[$diffIndex];
        if (!preg_match('/(?:^|[\s(])((?:`?[a-zA-Z0-9_]+`?\.)?`?[a-zA-Z0-9_]+`?)\s*=\s*$/', $left, $matches)) {
            return null;
        }

        return str_replace('`', '', $matches[1]);
    }

    public static function load($conn, string $query, array $bindings, bool $useReadPdo, array $meta = [])
    {
        $cid = \Swoole\Coroutine::getCid();
        $requestId = 'global';
        if ($cid > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            $requestId = $ctx['request_id'] ?? (string)$cid;
        }
        $batchKey = md5($requestId . "\0" . $query);

        // 如果该批次还不存在，当前协程作为 Owner 负责兜底执行
        if (!isset(self::$batches[$batchKey])) {
            self::$batches[$batchKey] = [
                'owner'       => $cid,
                'conn'        => $conn,
                'query'       => $query,
                'useReadPdo'  => $useReadPdo,
                'pending'     => [],
                'results'     => [],
                'flushing'    => false,   // 关键标志：flush() 执行期间设为 true，阻止迟到者注册
                'meta'        => $meta,
            ];

            self::$batches[$batchKey]['pending'][$cid] = $bindings;

            // Owner 协程休眠 1 毫秒（非阻塞），给其他并发序列化协程让出 CPU 来注册自己
            \Swoole\Coroutine\System::sleep(0.001);

            // 醒来后，标记为 flushing，然后执行合并！
            self::$batches[$batchKey]['flushing'] = true;
            try {
                self::flush($batchKey);
            } finally {
                // 异常安全网：确保所有兄弟协程都能被唤醒，防止永久死锁
                if (isset(self::$batches[$batchKey])) {
                    foreach (self::$batches[$batchKey]['pending'] as $waitCid => $b) {
                        if ($waitCid !== $cid && \Swoole\Coroutine::exists($waitCid)) {
                            \Swoole\Coroutine::resume($waitCid);
                        }
                    }
                }
            }

            $result = self::$batches[$batchKey]['results'][$cid] ?? [];
            unset(self::$batches[$batchKey]);

            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;

        } else {
            // 如果该批次正在执行 flush()（I/O 等待期间），迟到者不能注册，
            // 否则会永久 yield（没人唤醒）并拿到空 results，导致 bindings 错误（HY093）。
            // 直接降级走原始 parent::select() 即可。
            if (!empty(self::$batches[$batchKey]['flushing'])) {
                return $conn->nativeSelect($query, $bindings, $useReadPdo);
            }

            // ⚠️ 死锁防护：如果这个 batch 的 Owner 是当前协程的"父协程"，
            // 说明父协程正在 WaitGroup::wait() 里等所有子协程 done()，
            // 而我们如果 yield() 等父协程来 flush() 唤醒我们，就会形成
            // "子等父唤醒" vs "父等子done()" 的经典循环死锁！
            // 解法：直接降级走独立查询，绝对不 yield。
            $ownerCid = self::$batches[$batchKey]['owner'];
            $myPcid   = \Swoole\Coroutine::getPcid();
            if ($ownerCid === $myPcid) {
                return $conn->nativeSelect($query, $bindings, $useReadPdo);
            }

            // 其他跟进来的协程，将自己注册后直接挂起
            self::$batches[$batchKey]['pending'][$cid] = $bindings;
            \Swoole\Coroutine::yield();

            // 被 Owner 唤醒后取走属于自己的数据
            $result = self::$batches[$batchKey]['results'][$cid] ?? [];
            unset(self::$batches[$batchKey]['results'][$cid]);

            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        }
    }

    private static function flush(string $batchKey)
    {
        if (empty(self::$batches[$batchKey]['pending'])) {
            return;
        }

        $batch = self::$batches[$batchKey];
        $pending = $batch['pending'];
        $conn = $batch['conn'];
        $query = $batch['query'];
        $useReadPdo = $batch['useReadPdo'];
        $ownerCid = $batch['owner'];
        $meta = $batch['meta'];
        $type = $meta['type'] ?? 'normal';

        // 清理 pending 标记，防止 finally 重复唤醒
        self::$batches[$batchKey]['pending'] = [];

        // 如果这个微秒窗口里只有 1 个协程，直接原样查，不要浪费算力改写 SQL
        if (count($pending) === 1) {
            try {
                self::$batches[$batchKey]['results'][$ownerCid] = $conn->nativeSelect(
                    $query,
                    $pending[$ownerCid] ?? [],
                    $useReadPdo
                );
            } catch (\Throwable $e) {
                self::$batches[$batchKey]['results'][$ownerCid] = $e;
            }
            return;
        }

        // --- 发生并发 N+1！启动智能合并 ---
        
        $uniqueValues = [];
        $firstBindings = null;
        $diffIndex = -1;

        foreach ($pending as $cid => $b) {
            if ($firstBindings === null) {
                $firstBindings = $b;
                continue;
            }
            if ($diffIndex === -1) {
                foreach ($b as $i => $val) {
                    if ($val !== $firstBindings[$i]) {
                        $diffIndex = $i;
                        break;
                    }
                }
            }
        }

        if ($diffIndex === -1) {
            // 这意味着在这个 1ms 窗口内，所有协程发出的查询及绑定参数【完全一模一样】
            // 比如都是静态查询（没有 `?`），或者是查同一个特定记录。
            // 那我们连 SQL 都不用改写，直接查一次，把结果共享给所有人即可！这就是天然的超强 Cache！
            try {
                $mergedResults = $conn->nativeSelect($query, $firstBindings ?? [], $useReadPdo);
                foreach ($pending as $cid => $b) {
                    self::$batches[$batchKey]['results'][$cid] = $mergedResults;
                }
            } catch (\Throwable $e) {
                foreach ($pending as $cid => $b) {
                    self::$batches[$batchKey]['results'][$cid] = $e;
                }
            }
            // ⚠️ 关键：必须唤醒所有 yield() 中的兄弟协程！
            // 之前这里直接 return，所有 yield 的协程永远无法被 resume，导致 WaitGroup 永久死锁！
            foreach ($pending as $cid => $b) {
                if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::resume($cid);
                }
            }
            return;
        }

        foreach ($pending as $cid => $b) {
            if (isset($b[$diffIndex]) && !in_array($b[$diffIndex], $uniqueValues, true)) {
                $uniqueValues[] = $b[$diffIndex];
            }
        }

        if ($type === 'exists') {
            // Rewrite EXISTS query
            // Original: select exists(select * from tpolls where tpolls.post_id = ?) as exists
            // Rewrite to: select post_id as __batch_key from tpolls where tpolls.post_id IN (?, ?, ?)
            $rewrittenQuery = preg_replace(
                '/^\s*select\s+exists\s*\(\s*select\s+\*\s+from\s+(`?[a-zA-Z0-9_]+`?)\s+where\s+(.+?)\)\s+as\s+(`?[a-zA-Z0-9_]+`?)$/i', 
                "select {$meta['existsColumn']} as __batch_key from {$meta['existsTable']} where $2", 
                $query
            );
        } else if ($type === 'count') {
            // Rewrite COUNT(*) query
            $rewrittenQuery = "select {$meta['countColumn']} as __batch_key, count(*) as {$meta['countAlias']} from {$meta['countTable']} where {$meta['countColumn']} = ? group by {$meta['countColumn']}";
        } else if ($type === 'group_count') {
            // Rewrite GROUP BY COUNT(*) query
            $rewrittenQuery = "select {$meta['countColumn']} as __batch_key, {$meta['groupColumn']}, count(*) as {$meta['countAlias']} from {$meta['countTable']} where {$meta['countColumn']} = ? group by {$meta['countColumn']}, {$meta['groupColumn']}";
        } else {
            $rewrittenQuery = preg_replace('/\s*limit\s+1\s*$/i', '', $query);
        }

        $batchColumn = null;
        if ($type === 'normal') {
            $batchColumn = self::extractBatchColumn($rewrittenQuery, $diffIndex);
            if ($batchColumn === null) {
                $fallbackToSequential = true;
            } elseif (!preg_match('/^\s*select\s+(.+?)\s+from\s+/is', $rewrittenQuery, $selectMatch)) {
                $fallbackToSequential = true;
            } else {
                $quotedBatchColumn = self::quoteIdent($batchColumn);
                $rewrittenQuery = preg_replace(
                    '/^\s*select\s+(.+?)\s+from\s+/is',
                    'select $1, ' . $quotedBatchColumn . ' as __batch_key from ',
                    $rewrittenQuery,
                    1
                );
            }
        }

        $parts = explode('?', $rewrittenQuery);
        $newQuery = "";
        $newBindings = [];
        $fallbackToSequential = $fallbackToSequential ?? false;

        for ($i = 0; $i < count($parts) - 1; $i++) {
            if ($i === $diffIndex) {
                $trimmedPart = rtrim($parts[$i]);
                if (!str_ends_with($trimmedPart, '=')) {
                    // 非等值条件（如 >、<、LIKE）不能转换成 IN (?,?)，必须降级单条执行
                    $fallbackToSequential = true;
                    break;
                }
                $placeholders = implode(',', array_fill(0, count($uniqueValues), '?'));
                $trimmedPart = rtrim($trimmedPart, '=');
                $newQuery .= $trimmedPart . " IN (" . $placeholders . ")";
                foreach ($uniqueValues as $val) {
                    $newBindings[] = $val;
                }
            } else {
                $newQuery .= $parts[$i] . "?";
                $newBindings[] = $firstBindings[$i] ?? null;
            }
        }
        
        if ($fallbackToSequential) {
            foreach ($pending as $cid => $bindings) {
                try {
                    self::$batches[$batchKey]['results'][$cid] = $conn->nativeSelect($query, $bindings, $useReadPdo);
                } catch (\Throwable $e) {
                    self::$batches[$batchKey]['results'][$cid] = $e;
                }
                if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::resume($cid);
                }
            }
            return;
        }

        $newQuery .= end($parts);

        try {
            $mergedResults = $conn->nativeSelect($newQuery, $newBindings, $useReadPdo);
        } catch (\Throwable $e) {
            foreach ($pending as $cid => $bindings) {
                self::$batches[$batchKey]['results'][$cid] = $e;
                if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                    \Swoole\Coroutine::resume($cid);
                }
            }
            return;
        }

        foreach ($pending as $cid => $bindings) {
            $diffValue = $bindings[$diffIndex];
            
            if ($type === 'exists') {
                $isFound = false;
                foreach ($mergedResults as $row) {
                    if (isset($row->__batch_key) && (string)$row->__batch_key === (string)$diffValue) {
                        $isFound = true;
                        break;
                    }
                }
                $aliasStr = str_replace('`', '', $meta['existsAlias']);
                self::$batches[$batchKey]['results'][$cid] = [ (object) [ $aliasStr => $isFound ? 1 : 0 ] ];
            } else if ($type === 'count') {
                $countAliasStr = str_replace('`', '', $meta['countAlias']);
                $countVal = 0;
                foreach ($mergedResults as $row) {
                    if (isset($row->__batch_key) && (string)$row->__batch_key === (string)$diffValue) {
                        $countVal = $row->{$countAliasStr};
                        break;
                    }
                }
                self::$batches[$batchKey]['results'][$cid] = [ (object) [ $countAliasStr => $countVal ] ];
            } else if ($type === 'group_count') {
                $matchedRows = [];
                foreach ($mergedResults as $row) {
                    if (isset($row->__batch_key) && (string)$row->__batch_key === (string)$diffValue) {
                        $matchedRows[] = $row;
                    }
                }
                self::$batches[$batchKey]['results'][$cid] = $matchedRows;
            } else {
                $matchedRows = [];
                foreach ($mergedResults as $row) {
                    if (isset($row->__batch_key) && (string)$row->__batch_key === (string)$diffValue) {
                        $matchedRows[] = $row;
                        unset($row->__batch_key);
                    }
                }
                self::$batches[$batchKey]['results'][$cid] = $matchedRows;
            }

            if ($cid !== $ownerCid && \Swoole\Coroutine::exists($cid)) {
                \Swoole\Coroutine::resume($cid);
            }
        }
    }

    public static function cleanup(): void
    {
        $cid = \Swoole\Coroutine::getCid();
        foreach (self::$batches as $batchKey => $batch) {
            if ($batch['owner'] === $cid || isset($batch['pending'][$cid])) {
                unset(self::$batches[$batchKey]['pending'][$cid]);
                if (empty(self::$batches[$batchKey]['pending'])) {
                    unset(self::$batches[$batchKey]);
                }
            }
        }
    }
}
