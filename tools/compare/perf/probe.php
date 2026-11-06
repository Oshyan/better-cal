<?php
// Loaded by bench.php in front of one request (auto_prepend_file), in a PHP
// process of its own: records what that request cost and writes it, as JSON,
// to the file BC_PERF_OUT names.
//
//   ms        wall time from here (before the app's entry file runs) to the
//             end of the last shutdown function
//   peak      memory_get_peak_usage(true), peakUsed the same without true
//   db        with BC_PERF_COUNT=1 only: the request's own statements and
//             rows, read from the server's per-connection counters (SHOW
//             SESSION STATUS) on every connection the app's Db opened
//
// Counting loads a patched copy of the checkout's Db class (BC_PERF_DB names
// its file): pdo() keeps each connection it opens alive to the end, so its
// counters can be read after the app is done. Nothing else changes; the
// timing runs leave it off.

declare(strict_types=1);

final class BcPerfProbe
{
    public static float $t0 = 0.0;
    /** @var array<int, array{pdo:\PDO, base:array<string,int>}> */
    public static array $conns = [];
    public const KEYS = ['Questions', 'Com_select', 'Com_insert', 'Com_update', 'Com_delete', 'Com_replace', 'Com_insert_select',
        'Com_begin', 'Com_commit', 'Com_rollback', 'Handler_write', 'Handler_update', 'Handler_delete', 'Rows_sent', 'Rows_read'];

    /** @return array<string,int> */
    public static function status(\PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SHOW SESSION STATUS')->fetchAll(\PDO::FETCH_NUM) as [$k, $v]) {
            if (in_array($k, self::KEYS, true)) {
                $out[$k] = (int) $v;
            }
        }
        return $out;
    }

    public static function seen(\PDO $pdo): void
    {
        $id = spl_object_id($pdo);
        if (!isset(self::$conns[$id])) {
            self::$conns[$id] = ['pdo' => $pdo, 'base' => self::status($pdo)];
        }
    }

    public static function finish(): void
    {
        $ms = (hrtime(true) - self::$t0) / 1e6;
        $out = ['ms' => $ms, 'peak' => memory_get_peak_usage(true), 'peakUsed' => memory_get_peak_usage(false), 'status' => http_response_code()];
        // 1.5's bounded walk cache, where the request loaded it: what it holds at the end.
        $calc = 'BetterCal\\Domain\\Series\\Calc';
        if (class_exists($calc, false) && method_exists($calc, 'cacheUsage')) {
            $out['walkCache'] = $calc::cacheUsage();
        }
        if (getenv('BC_PERF_COUNT') === '1') {
            $sum = array_fill_keys(self::KEYS, 0);
            foreach (self::$conns as $c) {
                $now = self::status($c['pdo']);
                foreach (self::KEYS as $k) {
                    $sum[$k] += ($now[$k] ?? 0) - ($c['base'][$k] ?? 0);
                }
                // Questions, measured: each SHOW STATUS reads a count that
                // includes itself, so the delta holds the last read and not
                // the first, and the connect's SET time_zone (before the
                // first) is left out: the two cancel, and the delta is the
                // app's own statements, its connect's SET included.
            }
            $sum['connections'] = count(self::$conns);
            $out['db'] = $sum;
        }
        file_put_contents((string) getenv('BC_PERF_OUT'), json_encode($out));
    }
}

BcPerfProbe::$t0 = hrtime(true);

if (getenv('BC_PERF_COUNT') === '1') {
    $src = (string) file_get_contents((string) getenv('BC_PERF_DB'));
    $patched = str_replace('    public function pdo(): \PDO' . "\n", '    public function pdo(): \PDO' . "\n" . '    { $p = $this->bcPerfInnerPdo(); \BcPerfProbe::seen($p); return $p; }' . "\n" . '    private function bcPerfInnerPdo(): \PDO' . "\n", $src, $n);
    if ($n !== 1) {
        fwrite(STDERR, "probe: the Db class has no pdo() to wrap\n");
        exit(70);
    }
    eval('?>' . $patched);
}

// Last of all: a shutdown function registered while the others run goes to the end of the queue.
register_shutdown_function(static function (): void {
    register_shutdown_function([BcPerfProbe::class, 'finish']);
});
