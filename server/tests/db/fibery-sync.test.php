<?php

declare(strict_types=1);

// The Fibery plugin's sync against a stand-in for Fibery's /api/commands:
// each run first asks every chosen database for its entity count and newest
// modification date in one batched request. A run is that probe alone, or,
// when any database's answer moved (or a slot's settings or day changed, or
// a slot hasn't been read for 15 minutes), reads every slot, their pages
// sent together. A command
// Fibery refuses fails only its own slot. The stand-in answers each command
// on its own, {success, result}, as Fibery does; the workspace, databases
// and items are invented.

use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Plugin\PluginHost;

return static function (Db $db): void {
    $u = freshUser($db);
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/plugins/fibery/plugin.json'), true);
    $taskDb = 'Sample Space/Task';
    $projectDb = 'Sample Space/Project';
    $settings = [
        'workspace' => 'sample-team.fibery.io', 'token' => 'sample-token',
        'taskDatabase' => $taskDb, 'taskDateField' => 'Sample Space/Due',
        'projectDatabase' => $projectDb, 'projectDateField' => 'Sample Space/Due',
    ];
    $saveSettings = static function () use ($db, &$settings): void {
        $db->run("INSERT INTO plugins (id, version, enabled, settings_json) VALUES ('fibery', '1.0.0', 1, ?)
                  ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json)", [json_encode($settings)]);
    };
    $saveSettings();

    // The stand-in's data: entities by database, each {id, name, due, modified}.
    $entities = [$taskDb => [], $projectDb => []];
    $clock = 0;
    $put = static function (string $type, string $id, string $name) use (&$entities, &$clock): void {
        $clock++;
        $entities[$type][$id] = ['fibery/id' => $id, 'Sample Space/Name' => $name, 'Sample Space/Due' => '2031-03-10',
            'fibery/modification-date' => sprintf('2031-01-01T00:%02d:%02d.000Z', intdiv($clock, 60) % 60, $clock % 60)];
    };
    $put($taskDb, 't-01', 'Write the agenda');
    $put($taskDb, 't-02', 'Book the room');
    $put($projectDb, 'p-01', 'Spring fair');
    $refuse = null;       // a database whose item reads Fibery refuses
    $requests = [];       // each request's commands, in order

    $field = static fn(string $name, string $type, array $meta = []) => ['fibery/name' => $name, 'fibery/type' => $type, 'fibery/meta' => $meta];
    $type = static fn(string $name) => ['fibery/name' => $name, 'fibery/meta' => ['fibery/domain?' => true], 'fibery/fields' => [
        $field('fibery/id', 'fibery/uuid'),
        $field('fibery/public-id', 'fibery/text'),
        $field('fibery/modification-date', 'fibery/date-time'),
        $field('Sample Space/Name', 'fibery/text', ['ui/title?' => true]),
        $field('Sample Space/Due', 'fibery/date'),
    ]];
    $answer = static function (array $c) use (&$entities, &$refuse, $type, $taskDb, $projectDb): array {
        if ($c['command'] === 'fibery.schema/query') {
            return ['success' => true, 'result' => ['fibery/types' => [$type($taskDb), $type($projectDb)]]];
        }
        $q = $c['args']['query'];
        $rows = $entities[$q['q/from']] ?? null;
        if ($rows === null) {
            return ['success' => false, 'result' => ['name' => 'entity-query/unknown-type', 'message' => 'No such database']];
        }
        if ($q['q/select'] === ['q/count', ['fibery/id']]) {
            return ['success' => true, 'result' => count($rows)];
        }
        if (($q['q/order-by'][0][0] ?? null) === ['fibery/modification-date']) {
            usort($rows, static fn($a, $b) => strcmp($b['fibery/modification-date'], $a['fibery/modification-date']));
            return ['success' => true, 'result' => array_map(static fn($r) => ['fibery/modification-date' => $r['fibery/modification-date']], array_slice($rows, 0, 1))];
        }
        if ($q['q/from'] === $refuse) {
            return ['success' => false, 'result' => ['name' => 'entity-query/error', 'message' => 'Sample refusal']];
        }
        ksort($rows);
        $after = $c['args']['params']['$after'] ?? null;
        $out = [];
        foreach ($rows as $id => $r) {
            if ($after === null || strcmp((string) $id, (string) $after) > 0) {
                $out[] = ['fibery/id' => $r['fibery/id'], 'fibery/public-id' => '1', 'Sample Space/Name' => $r['Sample Space/Name'], 'Sample Space/Due' => $r['Sample Space/Due']];
            }
        }
        return ['success' => true, 'result' => array_slice($out, 0, (int) $q['q/limit'])];
    };
    $http = static function () use (&$requests, $answer): HttpClient {
        return new HttpClient(
            requestBudget: 100,
            allowedSchemes: ['https'],
            resolver: static fn(string $host): array => ['93.184.216.34'],
            transport: static function (string $method, string $url, array $headers, ?string $body) use (&$requests, $answer): array {
                $commands = json_decode((string) $body, true);
                $requests[] = $commands;
                return ['status' => 200, 'body' => json_encode(array_map($answer, $commands))];
            },
        );
    };
    $run = static function () use ($db, $u, $manifest, $http, &$requests): array {
        // A run leaves alone a row changed in the second it started (taken
        // as a move made here while it read), so each run starts in a new second.
        usleep((int) ((1 - fmod(microtime(true), 1)) * 1_000_000) + 1000);
        $requests = [];
        $plugin = require dirname(__DIR__, 2) . '/plugins/fibery/Plugin.php';
        $plugin->runJob(new PluginHost($db, 'fibery', $u, $http(), $manifest, []), 'sync');
        return $requests;
    };
    $host = static fn() => new PluginHost($db, 'fibery', $u, new HttpClient(), $manifest, []);
    $calendarOf = static fn(string $slot): int => (int) ($host()->kvGet('calendars', [])[$slot] ?? 0);
    $titles = static fn(string $slot): array => array_map(static fn($r) => (string) $r['title'], $db->all(
        'SELECT title FROM events WHERE calendar_id = ? AND deleted_at IS NULL ORDER BY title', [$calendarOf($slot)]));
    // The databases an item read (not a probe) asked for, request by request.
    $reads = static fn(array $reqs): array => array_values(array_filter(array_map(
        static fn(array $cmds): array => array_values(array_map(static fn($c) => $c['args']['query']['q/from'], array_filter($cmds,
            static fn($c) => $c['command'] === 'fibery.entity/query' && isset($c['args']['params'])))),
        $reqs
    )));
    $warnings = static fn(): array => array_map(static fn($r) => (string) $r['message'], $db->all("SELECT message FROM plugin_warnings WHERE plugin_id = 'fibery'"));

    scenario('fibery sync: two slots read in one batch', function () use ($run, $reads, $titles, $taskDb, $projectDb): void {
        $reqs = $run();
        checkEq('fibery sync: the first run asks for the schema, probes, then reads both slots in one request', 3, count($reqs));
        checkEq('fibery sync: both slots\' first pages travel in one batch', [[$taskDb, $projectDb]], $reads($reqs));
        checkEq('fibery sync: the probe is one request with a count and a newest date for each slot', 4, count($reqs[1]));
        checkEq('fibery sync: the task calendar has both tasks', ['Book the room', 'Write the agenda'], $titles('task'));
        checkEq('fibery sync: the project calendar has its project', ['Spring fair'], $titles('project'));
    });

    scenario('fibery sync: an unchanged probe skips the read', function () use ($run, $reads, $titles): void {
        $reqs = $run();
        checkEq('fibery sync: with nothing changed a run is the probe alone', 1, count($reqs));
        checkEq('fibery sync: and reads no items', [], $reads($reqs));
        checkEq('fibery sync: the calendar keeps its items', ['Book the room', 'Write the agenda'], $titles('task'));
    });

    scenario('fibery sync: a deletion changes the count and triggers the read', function () use ($run, $reads, $titles, &$entities, $taskDb, $projectDb): void {
        unset($entities[$taskDb]['t-02']); // the newest date is unchanged; only the count moved
        $reqs = $run();
        checkEq('fibery sync: a moved count reads every slot, not only the changed one', [[$taskDb, $projectDb]], $reads($reqs));
        checkEq('fibery sync: the deleted task leaves the calendar', ['Write the agenda'], $titles('task'));
    });

    scenario('fibery sync: an edit changes the newest date and triggers the read', function () use ($run, $reads, $titles, $put, $taskDb, $projectDb): void {
        $put($projectDb, 'p-01', 'Spring fair, moved');
        $reqs = $run();
        checkEq('fibery sync: an edit in one database reads every slot, so tasks naming a renamed project refresh', [[$taskDb, $projectDb]], $reads($reqs));
        checkEq('fibery sync: the edit arrives', ['Spring fair, moved'], $titles('project'));
    });

    scenario('fibery sync: the 15-minute safety read', function () use ($run, $reads, $host, $taskDb, $projectDb): void {
        $age = static function (int $seconds) use ($host): void {
            $h = $host();
            $known = $h->kvGet('synced', []);
            $known['task']['at'] = time() - $seconds;
            $h->kvSet('synced', $known);
        };
        $age(840);
        checkEq('fibery sync: 14 minutes after a read, an unchanged run reads nothing', [], $reads($run()));
        $age(901);
        checkEq('fibery sync: a slot not read for 15 minutes is read though unchanged, with every other slot', [[$taskDb, $projectDb]], $reads($run()));
        checkEq('fibery sync: and the next run skips it again', [], $reads($run()));
    });

    scenario('fibery sync: a settings change triggers the read', function () use ($run, $reads, &$settings, $saveSettings, $taskDb, $projectDb): void {
        $settings['hideFinished'] = false;
        $saveSettings();
        checkEq('fibery sync: changed settings read every slot', [[$taskDb, $projectDb]], $reads($run()));
    });

    scenario("fibery sync: one slot's refusal leaves the other's rows synced", function () use ($run, $reads, $titles, $put, $warnings, $manifest, &$refuse, $taskDb, $projectDb): void {
        $put($taskDb, 't-03', 'Print the flyers');
        $put($projectDb, 'p-01', 'Spring fair, moved again');
        $refuse = $projectDb;
        $reqs = $run();
        checkEq('fibery sync: both slots are asked for in one batch', [[$taskDb, $projectDb]], $reads($reqs));
        checkEq('fibery sync: the task slot syncs though the project read was refused', ['Print the flyers', 'Write the agenda'], $titles('task'));
        checkEq('fibery sync: the refused slot keeps what it last had', ['Spring fair, moved'], $titles('project'));
        checkEq('fibery sync: and says so', [$manifest['messages']['project-read-failed']], $warnings());
        $refuse = null;
        checkEq('fibery sync: the refused slot is read again on the next run, with the other', [[$taskDb, $projectDb]], $reads($run()));
        checkEq('fibery sync: and catches up', ['Spring fair, moved again'], $titles('project'));
        checkEq('fibery sync: its warning clears', [], $warnings());
    });

    scenario('fibery sync: a full first page goes on alone', function () use ($run, $reads, $put, $titles, $taskDb, $projectDb): void {
        for ($i = 100; $i < 1101; $i++) {
            $put($taskDb, 't-' . $i, 'Bulk item ' . $i);
        }
        $put($projectDb, 'p-02', 'Autumn fair');
        $reqs = $run();
        checkEq('fibery sync: first pages together, then the full slot alone', [[$taskDb, $projectDb], [$taskDb]], $reads($reqs));
        checkEq('fibery sync: every task arrives', 1003, count($titles('task')));
    });
};
