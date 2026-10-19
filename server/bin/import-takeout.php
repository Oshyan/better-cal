<?php

declare(strict_types=1);

// Bulk calendar import (docs/migration.md): Google Calendar's own export
// (Settings, Import & export, Export) or a Google Takeout export, as the zip
// it comes in or a folder of the .ics files unzipped from it. Each file
// becomes one local calendar, named from the file (or the calendar's own
// name), palette-colored, with every event and full recurrence structure.
// Rerun-safe: a calendar whose name already exists is skipped.
//
//   php server/bin/import-takeout.php /path/to/export.zip
//   php server/bin/import-takeout.php /path/to/folder-of-ics-files
//
// The app's Import does the same with a zip (Domain\CalendarImport); this is
// for exports too large to upload, or a server without a browser at hand.

require dirname(__DIR__) . '/src/bootstrap.php';

use BetterCal\Domain\CalendarImport;
use BetterCal\Domain\Calendars;
use BetterCal\Domain\Ics;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;

$source = $argv[1] ?? '';
if ($source === '' || (!is_dir($source) && !is_file($source))) {
    fwrite(STDERR, "Usage: php import-takeout.php /path/to/export.zip | /path/to/folder-of-ics-files\n");
    exit(1);
}

$cfg = config();
$db = new Db($cfg['db']);
$userId = (int) ($db->scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?? 0);
if ($userId === 0) {
    fwrite(STDERR, "No user found\n");
    exit(1);
}
$importer = new CalendarImport($db, new Calendars($db, new Undo($db), (string) $cfg['session_secret']));

// A zip: the same path as the app's Import.
if (is_file($source)) {
    try {
        $result = $importer->importZip($userId, $source);
    } catch (HttpError $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    foreach ($result['calendars'] as $c) {
        echo "imported: {$c['name']} — {$c['imported']} events\n";
        if ($c['skippedEvents'] !== []) echo Ics::skippedMessage($c['skippedEvents']) . "\n";
    }
    foreach ($result['skipped'] as $s) {
        echo "skip: {$s['file']} — {$s['reason']}\n";
    }
    echo 'done: ' . array_sum(array_column($result['calendars'], 'imported')) . ' events across ' . count($result['calendars']) . " calendars\n";
    exit(array_filter($result['skipped'], static fn(array $s): bool => !str_contains($s['reason'], 'already exists')) === [] ? 0 : 1);
}

// A folder of .ics files, bounded the same way a zip is.
$files = [];
$totalBytes = 0;
$maxFiles = Limits::get('TAKEOUT_FILES');
$maxTotalBytes = Limits::get('TAKEOUT_TOTAL_BYTES');
try {
    foreach (new DirectoryIterator($source) as $file) {
        if ($file->isDot() || strtolower($file->getExtension()) !== 'ics') {
            continue;
        }
        $files[] = $file->getPathname();
        $totalBytes += max(0, $file->getSize());
        if (count($files) > $maxFiles || $totalBytes > $maxTotalBytes) {
            fwrite(STDERR, 'Import refused: the folder exceeds the safe operation limit ('
                . number_format($maxFiles) . ' files or ' . number_format($maxTotalBytes) . " bytes).\n");
            exit(1);
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Import refused: the folder could not be inspected safely.\n");
    exit(1);
}
sort($files, SORT_STRING);
if ($files === []) {
    fwrite(STDERR, "No .ics files in $source\n");
    exit(1);
}

$taken = array_fill_keys(array_map(static fn(array $r): string => (string) $r['name'],
    $db->all('SELECT name FROM calendars WHERE user_id = ?', [$userId])), true);
$palette = ['#5b7fd4', '#4ca777', '#d4a03c', '#c96f8f', '#7a6fd4', '#4ba8b8', '#c9704e', '#8aa04e'];
$position = count($taken);
$totalImported = 0;
$calendarsMade = 0;
$failures = 0;
foreach ($files as $path) {
    $admitted = Ics::readAdmittedFile($path, Limits::get('IMPORT_BYTES'), Limits::importEventBudget());
    if ($admitted['problem'] !== null) {
        echo 'skip (safety limit): ' . basename($path) . " — {$admitted['problem']}\n";
        $failures++;
        continue;
    }
    $ics = (string) $admitted['content'];
    $name = CalendarImport::calendarName(basename($path), $ics);
    if (isset($taken[$name])) {
        echo "skip (exists): $name\n";
        continue;
    }
    try {
        $done = $importer->importIcs($userId, $ics, $name, $palette[$position % count($palette)]);
    } catch (HttpError $e) {
        echo "skip: $name — {$e->getMessage()}\n";
        $failures++;
        continue;
    }
    $taken[$name] = true;
    $position++;
    $calendarsMade++;
    $totalImported += $done['imported'];
    if ($done['skippedEvents'] !== []) { echo Ics::skippedMessage($done['skippedEvents']) . "\n"; $failures++; }
    echo "imported: $name — {$done['imported']} events\n";
}
echo "done: $totalImported events across $calendarsMade calendars\n";
exit($failures === 0 ? 0 : 1);
