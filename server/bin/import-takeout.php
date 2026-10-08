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

use BetterCal\Domain\ActivityContext;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;

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
$files = [];
$sawIcs = false;
$existingNames = array_fill_keys(array_map(
    static fn(array $row): string => (string) $row['name'],
    $db->all('SELECT name FROM calendars WHERE user_id = ?', [$userId])
), true);
$totalBytes = 0;
$maxFiles = Limits::get('TAKEOUT_FILES');
$maxTotalBytes = Limits::get('TAKEOUT_TOTAL_BYTES');
try {
    foreach (new DirectoryIterator($dir) as $file) {
        if ($file->isDot() || strtolower($file->getExtension()) !== 'ics') {
            continue;
        }
        $sawIcs = true;
        $path = $file->getPathname();
        $name = takeoutCalendarName($path);
        // A resumed import should be bounded by the work still to do, not by
        // files whose calendars already committed on an earlier run.
        if (isset($existingNames[$name])) {
            echo "skip (exists): $name\n";
            continue;
        }
        $files[] = ['path' => $path, 'name' => $name];
        $totalBytes += max(0, $file->getSize());
        if (count($files) > $maxFiles || $totalBytes > $maxTotalBytes) {
            fwrite(STDERR, 'Takeout import refused: the selected directory exceeds the safe operation limit ('
                . number_format($maxFiles) . ' files or ' . number_format($maxTotalBytes) . " bytes).\n");
            exit(1);
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Takeout import refused: the selected directory could not be inspected safely.\n");
    exit(1);
}
usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
if ($files === []) {
    if (!$sawIcs) {
        fwrite(STDERR, "No .ics files in $dir\n");
        exit(1);
    }
    echo "No new .ics files to import\n";
    exit(0);
}

$calCount = (int) $db->scalar('SELECT COUNT(*) FROM calendars WHERE user_id = ?', [$userId]);
$totalImported = 0;
$failures = 0;
$admittedBytes = 0;
foreach ($files as $i => $file) {
    $path = $file['path'];
    $name = $file['name'];
    $exists = $db->scalar('SELECT id FROM calendars WHERE user_id = ? AND name = ?', [$userId, $name]);
    if ($exists !== null) {
        echo "skip (exists): $name\n";
        continue;
    }
    $admitted = Ics::readAdmittedFile($path, Limits::get('IMPORT_BYTES'), Limits::importEventBudget());
    if ($admitted['problem'] !== null) {
        echo "skip (safety limit): $name — {$admitted['problem']}\n";
        $failures++;
        continue;
    }
    $admittedBytes += $admitted['bytes'];
    if ($admittedBytes > $maxTotalBytes) {
        fwrite(STDERR, "Takeout import stopped: files changed after inspection and now exceed the safe total-byte limit.\n");
        $failures++;
        break;
    }
    $ics = (string) $admitted['content'];
    if (!str_contains($ics, 'BEGIN:VCALENDAR')) {
        echo "skip (not ics): $name\n";
        $failures++;
        continue;
    }
    try {
        $parsed = Ics::parse($ics, BetterCal\Domain\Settings::homeTzid($db, $userId));
    } catch (\Throwable $e) {
        echo "skip (parse error): $name — {$e->getMessage()}\n";
        $failures++;
        continue;
    }

    // Masters before overrides, same as the ICS import endpoint.
    usort($parsed, static fn(array $a, array $b): int =>
        ($a['recurrence_instance_utc'] === null ? 0 : 1) <=> ($b['recurrence_instance_utc'] === null ? 0 : 1));
    try {
        [$calendarId, $imported] = $db->tx(function () use ($db, $parsed, $userId, $name, $palette, $calCount, $i): array {
            $calendarId = $db->insert('calendars', [
                'user_id' => $userId,
                'name' => $name,
                'kind' => 'local',
                'color' => $palette[($calCount + $i) % count($palette)],
                'visible' => 1,
            ]);
            $imported = 0;
            $masterIdByUid = [];
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
                    'description' => \BetterCal\Domain\Sanitize::description($ev['description']),
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
            ActivityContext::with('import', static fn() => (new Undo($db))->record(
                $userId,
                'calendar',
                $calendarId,
                'create',
                null,
                null,
                "Imported calendar '$name' ($imported events)"
            ));
            return [$calendarId, $imported];
        });
    } catch (\Throwable $e) {
        echo "skip (write error): $name — {$e->getMessage()}\n";
        $failures++;
        continue;
    }
    $totalImported += $imported;
    echo "imported: $name — $imported events\n";
}
echo "done: $totalImported events across " . count($files) . " files\n";
exit($failures === 0 ? 0 : 1);

function takeoutCalendarName(string $path): string
{
    $name = pathinfo($path, PATHINFO_FILENAME);
    $name = preg_replace('/%40/', '@', $name) ?? $name;
    // GCal's settings Export names files "Calendar Name_calendarid@...ics";
    // the prefix is the clean human name. Takeout uses the bare address for
    // the primary calendar, so filename parsing wins over X-WR-CALNAME.
    if (preg_match('/^(.+)_[^_]*@[^_]*$/', $name, $parts) === 1 && trim($parts[1]) !== '') {
        $name = trim($parts[1]);
    }
    return preg_replace('/\.ical$/', '', $name) ?? $name;
}
