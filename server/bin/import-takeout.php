<?php

declare(strict_types=1);

// Google Takeout bulk import (docs/migration.md): given a directory of .ics
// files (the unzipped Takeout "Calendar" folder), create one local calendar
// per file — named from the filename, palette-colored — and import every
// event with full recurrence structure. Idempotent-ish: a calendar whose
// name already exists is skipped (rerun-safe).
//
//   php server/bin/import-takeout.php /path/to/Takeout/Calendar

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\Ics;
use BetterCal\Infra\Db;

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php import-takeout.php /path/to/dir-with-ics-files\n");
    exit(1);
}

$cfg = config();
$db = new Db($cfg['db']);
$userId = (int) ($db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
if ($userId === 0) {
    fwrite(STDERR, "No user found\n");
    exit(1);
}

$palette = ['#5b7fd4', '#4ca777', '#d4a03c', '#c96f8f', '#7a6fd4', '#4ba8b8', '#c9704e', '#8aa04e'];
$files = glob(rtrim($dir, '/') . '/*.ics') ?: [];
if ($files === []) {
    fwrite(STDERR, "No .ics files in $dir\n");
    exit(1);
}

$calCount = (int) $db->scalar('SELECT COUNT(*) FROM calendars WHERE user_id = ?', [$userId]);
$totalImported = 0;
foreach ($files as $i => $path) {
    $name = pathinfo($path, PATHINFO_FILENAME);
    $name = preg_replace('/%40/', '@', $name) ?? $name;
    // GCal's settings Export names files "Calendar Name_calendarid@...ics";
    // the prefix is the clean human name. (Takeout uses the bare address for
    // the primary calendar; X-WR-CALNAME mislabels Birthdays, so filename
    // parsing wins.)
    if (preg_match('/^(.+)_[^_]*@[^_]*$/', $name, $nm) === 1 && trim($nm[1]) !== '') {
        $name = trim($nm[1]);
    }
    $name = preg_replace('/\.ical$/', '', $name) ?? $name;
    $exists = $db->scalar('SELECT id FROM calendars WHERE user_id = ? AND name = ?', [$userId, $name]);
    if ($exists !== null) {
        echo "skip (exists): $name\n";
        continue;
    }
    $ics = file_get_contents($path);
    if ($ics === false || !str_contains($ics, 'BEGIN:VCALENDAR')) {
        echo "skip (not ics): $name\n";
        continue;
    }
    try {
        $parsed = Ics::parse($ics);
    } catch (\Throwable $e) {
        echo "skip (parse error): $name — {$e->getMessage()}\n";
        continue;
    }

    $calendarId = $db->insert('calendars', [
        'user_id' => $userId,
        'name' => $name,
        'kind' => 'local',
        'color' => $palette[($calCount + $i) % count($palette)],
        'visible' => 1,
    ]);

    // Masters before overrides, same as the ICS import endpoint.
    usort($parsed, static fn(array $a, array $b): int =>
        ($a['recurrence_instance_utc'] === null ? 0 : 1) <=> ($b['recurrence_instance_utc'] === null ? 0 : 1));
    $imported = 0;
    $masterIdByUid = [];
    $db->tx(function () use ($db, $parsed, $userId, $calendarId, &$imported, &$masterIdByUid): void {
        $seen = [];
        foreach ($parsed as $ev) {
            $key = $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $id = $db->insert('events', [
                'user_id' => $userId,
                'calendar_id' => $calendarId,
                'uid' => $ev['uid'],
                'title' => $ev['title'],
                'description' => $ev['description'],
                'location' => $ev['location'],
                'url' => $ev['url'],
                'start_utc' => $ev['start_utc'],
                'end_utc' => $ev['end_utc'],
                'all_day' => $ev['all_day'],
                'tzid' => $ev['tzid'],
                'rrule' => $ev['rrule'],
                'exdates_json' => $ev['exdates'] === [] ? null : json_encode($ev['exdates']),
                'reminders_json' => ($ev['reminders'] ?? []) === [] ? null : json_encode($ev['reminders']),
                'status' => $ev['status'],
                'recurrence_instance_utc' => $ev['recurrence_instance_utc'],
                'recurrence_parent_id' => $ev['recurrence_instance_utc'] !== null
                    ? ($masterIdByUid[(string) $ev['uid']] ?? null)
                    : null,
                'source' => 'local',
            ]);
            if ($ev['recurrence_instance_utc'] === null) {
                $masterIdByUid[(string) $ev['uid']] = $id;
            }
            $imported++;
        }
    });
    $totalImported += $imported;
    echo "imported: $name — $imported events\n";
}
echo "done: $totalImported events across " . count($files) . " files\n";
