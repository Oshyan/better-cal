<?php

declare(strict_types=1);

// The shared HTTP cache is pruned in one place for the whole table: the
// daily activity_prune job calls PluginHost::pruneHttpCache, which deletes
// expired rows whoever wrote them (a plugin's fetch or an event page) and
// leaves unexpired ones, in batches and at most a fixed number a run.

use BetterCal\Infra\Db;
use BetterCal\Plugin\PluginHost;
use BetterCal\Support\Time;

return static function (Db $db): void {
    $at = static fn(string $offset): string => Time::toDb(Time::nowUtc()->modify($offset));
    $put = static function (string $url, string $expires) use ($db, $at): void {
        $db->run(
            'INSERT INTO http_cache (url_hash, url, status, body, fetched_at, expires_at) VALUES (?, ?, 200, ?, ?, ?)',
            [hash('sha256', $url), $url, '{}', $at('-2 days'), $expires]
        );
    };
    $mine = static fn(string $prefix): array => array_column(
        $db->all('SELECT url FROM http_cache WHERE url LIKE ? ORDER BY url', [$prefix . '%']), 'url');

    scenario('http cache: expired plugin and event page rows go, unexpired stay', function () use ($db, $at, $put, $mine): void {
        $put('https://tides.example.test/predictions?station=1&begin_date=20261101', $at('-1 day'));
        $put('https://tides.example.test/predictions?station=1&begin_date=20261109', $at('+1 hour'));
        $put('event-page:sample-old', $at('-1 minute'));
        $put('event-page:sample-fresh', $at('+10 minutes'));
        PluginHost::pruneHttpCache($db);
        checkEq('http cache: an expired plugin row goes and an unexpired one stays',
            ['https://tides.example.test/predictions?station=1&begin_date=20261109'], $mine('https://tides.example.test/'));
        checkEq('http cache: event page rows are pruned the same way', ['event-page:sample-fresh'], $mine('event-page:sample-'));
    });

    scenario('http cache: a run deletes in batches up to its cap, and the next run goes on', function () use ($db, $at, $mine): void {
        $cap = PluginHost::HTTP_CACHE_PRUNE_BATCH * PluginHost::HTTP_CACHE_PRUNE_BATCHES;
        $expired = $at('-3 days');
        foreach (array_chunk(range(1, $cap + 5), 1000) as $chunk) {
            $rows = [];
            $args = [];
            foreach ($chunk as $i) {
                $url = 'https://bulk.example.test/day/' . $i;
                $rows[] = "(?, ?, 200, '{}', ?, ?)";
                array_push($args, hash('sha256', $url), $url, $expired, $expired);
            }
            $db->run('INSERT INTO http_cache (url_hash, url, status, body, fetched_at, expires_at) VALUES ' . implode(', ', $rows), $args);
        }
        $db->run("INSERT INTO http_cache (url_hash, url, status, body, fetched_at, expires_at) VALUES (SHA2('bulk-fresh', 256), 'https://bulk.example.test/fresh', 200, '{}', ?, ?)",
            [$expired, $at('+1 day')]);
        $first = PluginHost::pruneHttpCache($db);
        checkEq('http cache: one run deletes no more than its cap', $cap, $first);
        check('http cache: what the cap left is still there for the next run', count($mine('https://bulk.example.test/day/')) >= 5);
        PluginHost::pruneHttpCache($db);
        checkEq('http cache: the next run finishes the backlog and keeps the unexpired row',
            ['https://bulk.example.test/fresh'], $mine('https://bulk.example.test/'));
    });
};
