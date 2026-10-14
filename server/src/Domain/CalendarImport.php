<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Support\Limits;

/**
 * Bringing calendar files in: one .ics as a new local calendar, or a zip of
 * them (Google Calendar's own export, or Takeout) as one calendar per file
 * (#136). Shared by the app's Import (POST /calendars/import) and
 * bin/import-takeout.php, so they can't drift.
 *
 * Every file goes through the same limits as a single import (size, events,
 * repeating-series exceptions); a zip is also bounded in how many files it
 * holds and how much it unpacks to, checked from the declared sizes before
 * anything is read and again while reading. Entries are read straight from
 * the archive, never written to disk. Each calendar commits on its own, so a
 * bad file is skipped and the rest still come in.
 */
final class CalendarImport
{
    private const PALETTE = ['#5b7fd4', '#4ca777', '#d4a03c', '#c96f8f', '#7a6fd4', '#4ba8b8', '#c9704e', '#8aa04e'];

    public function __construct(
        private readonly Db $db,
        private readonly Calendars $calendars,
    ) {
    }

    /**
     * One calendar file into a new local calendar.
     *
     * @return array{calendarId:int,imported:int}
     */
    public function importIcs(int $userId, string $ics, string $name, ?string $color = null): array
    {
        $maxBytes = Limits::get('IMPORT_BYTES');
        if (!str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw HttpError::badRequest('File is not an ICS calendar', 'invalid_ics');
        }
        // The configured cap, or fewer when this PHP process could not hold
        // that many parsed events: a clear refusal beats an out-of-memory crash.
        $maxEvents = Limits::importEventBudget();
        $problem = Ics::budgetProblem($ics, $maxBytes, $maxEvents);
        if ($problem !== null) {
            if (str_contains($problem, 'skipped occurrences')) {
                $how = 'Split unusually exception-heavy repeating series into smaller parts, or adjust BETTERCAL_LIMIT_EXDATE_VALUES_PER_EVENT and BETTERCAL_LIMIT_EXDATE_VALUES_PER_INPUT within their hard ceilings.';
            } else {
                $how = $maxEvents < Limits::get('IMPORT_EVENTS')
                    ? 'This server has memory for about ' . number_format($maxEvents) . ' events at once: split the file, or raise PHP memory_limit.'
                    : 'Split the file, or raise BETTERCAL_LIMIT_IMPORT_EVENTS on the server.';
            }
            throw new HttpError('import_too_large', $problem . '. ' . $how, 413);
        }
        try {
            $parsed = Ics::parse($ics, Settings::homeTzid($this->db, $userId));
        } catch (\Throwable $e) {
            throw HttpError::badRequest('Could not parse ICS: ' . $e->getMessage(), 'invalid_ics');
        }
        // Masters before their edited occurrences, so each override finds its parent.
        usort($parsed, static fn(array $a, array $b): int => ($a['recurrence_instance_utc'] === null ? 0 : 1) <=> ($b['recurrence_instance_utc'] === null ? 0 : 1));
        return $this->db->tx(function () use ($parsed, $userId, $name, $color): array {
            // The new calendar and every imported row become visible in one
            // commit. A concurrent move cannot discover/count a half-imported
            // calendar between two transactions.
            $calendar = $this->calendars->create($userId, ['name' => $name] + ($color !== null ? ['color' => $color] : []));
            $calendarId = (int) $calendar['id'];
            $imported = 0;
            $masterIdByUid = [];
            $seen = [];
            foreach ($parsed as $ev) {
                $key = $ev['uid'] . '|' . ($ev['recurrence_instance_utc'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $id = $this->db->insert('events', [
                    'user_id' => $userId,
                    'calendar_id' => $calendarId,
                    'created_via' => 'import',
                    'uid' => $ev['uid'],
                    'title' => $ev['title'],
                    'description' => Sanitize::description($ev['description']),
                    'location' => $ev['location'],
                    'url' => $ev['url'],
                    'start_utc' => $ev['start_utc'],
                    'end_utc' => $ev['end_utc'],
                    'all_day' => $ev['all_day'],
                    'tzid' => $ev['tzid'],
                    'rrule' => $ev['rrule'],
                    'exdates_json' => $ev['exdates'] === [] ? null : json_encode($ev['exdates']),
                    // VALARM reminders become explicit overrides; none = inherit defaults.
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
            return ['calendarId' => $calendarId, 'imported' => $imported];
        });
    }

    /**
     * Every .ics in a zip, one new calendar each. A calendar whose name is
     * already taken is left alone (so importing the same export twice adds
     * nothing), and a file that fails is listed with why; the others still
     * come in.
     *
     * @return array{calendars:list<array{name:string,calendarId:int,imported:int}>,skipped:list<array{file:string,reason:string}>}
     */
    public function importZip(int $userId, string $zipPath): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpError('zip_unavailable', 'This server cannot open zip files (the PHP zip extension is missing). Import the .ics files one at a time.', 400);
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw HttpError::badRequest('That file is not a zip archive this server can open', 'invalid_zip');
        }
        try {
            $entries = self::icsEntries($zip);
            $taken = array_fill_keys(array_map(static fn(array $r): string => (string) $r['name'],
                $this->db->all('SELECT name FROM calendars WHERE user_id = ?', [$userId])), true);
            $position = (int) $this->db->scalar('SELECT COUNT(*) FROM calendars WHERE user_id = ?', [$userId]);
            $calendars = [];
            $skipped = [];
            foreach ($entries as $entry) {
                $ics = self::readEntry($zip, $entry['index'], Limits::get('IMPORT_BYTES'));
                if ($ics === null) {
                    $skipped[] = ['file' => $entry['file'], 'reason' => 'over the import size limit, or unreadable'];
                    continue;
                }
                $name = self::calendarName($entry['file'], $ics);
                if (isset($taken[$name])) {
                    $skipped[] = ['file' => $entry['file'], 'reason' => "a calendar named \"$name\" already exists"];
                    continue;
                }
                try {
                    $done = $this->importIcs($userId, $ics, $name, self::PALETTE[$position % count(self::PALETTE)]);
                } catch (HttpError $e) {
                    $skipped[] = ['file' => $entry['file'], 'reason' => $e->getMessage()];
                    continue;
                }
                $taken[$name] = true;
                $position++;
                $calendars[] = ['name' => $name, 'calendarId' => $done['calendarId'], 'imported' => $done['imported']];
            }
        } finally {
            $zip->close();
        }
        if ($calendars === [] && $skipped === []) {
            throw HttpError::badRequest('There are no .ics calendar files in that zip', 'invalid_zip');
        }
        return ['calendars' => $calendars, 'skipped' => $skipped];
    }

    /**
     * The .ics entries of a zip, refusing the whole archive when it holds
     * more files or unpacks to more than one import may take (declared sizes;
     * readEntry enforces them again). Folders and other files are ignored:
     * Takeout adds an HTML index, macOS adds __MACOSX copies.
     *
     * @return list<array{index:int,file:string}>
     */
    public static function icsEntries(\ZipArchive $zip): array
    {
        $out = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $path = is_array($stat) ? (string) $stat['name'] : '';
            if (!str_ends_with(strtolower($path), '.ics') || str_starts_with($path, '__MACOSX/') || str_contains($path, '/._')) {
                continue;
            }
            $total += (int) ($stat['size'] ?? 0);
            if (count($out) >= Limits::get('TAKEOUT_FILES') || $total > Limits::get('TAKEOUT_TOTAL_BYTES')) {
                throw new HttpError('import_too_large', 'That zip holds more calendars than one import may take (over '
                    . number_format(Limits::get('TAKEOUT_FILES')) . ' files or ' . round(Limits::get('TAKEOUT_TOTAL_BYTES') / 1048576) . ' MiB unpacked).', 413);
            }
            $out[] = ['index' => $i, 'file' => basename($path)];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['file'], $b['file']));
        return $out;
    }

    /** One entry's text, or null when it's bigger than $maxBytes, whatever its header claimed. */
    public static function readEntry(\ZipArchive $zip, int $index, int $maxBytes): ?string
    {
        $stat = $zip->statIndex($index);
        if (!is_array($stat) || (int) $stat['size'] > $maxBytes) {
            return null;
        }
        $stream = $zip->getStream((string) $stat['name']);
        if ($stream === false) {
            return null;
        }
        $data = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 1 << 16);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > $maxBytes) {
                fclose($stream);
                return null;
            }
        }
        fclose($stream);
        return $data;
    }

    /**
     * A calendar's name from its file. Google Calendar's export names files
     * "Calendar Name_calendarid@group.calendar.google.com.ics": the part
     * before the id is the name. Takeout names the main calendar by its
     * address alone, and other exports by whatever; for those the calendar's
     * own X-WR-CALNAME wins when it has one, else the file name.
     */
    public static function calendarName(string $file, string $ics = ''): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        $name = str_replace('%40', '@', $name);
        $name = (string) preg_replace('/\.ical$/', '', $name);
        if (preg_match('/^(.+)_[^_]*@[^_]*$/', $name, $parts) === 1 && trim($parts[1]) !== '') {
            return mb_substr(trim($parts[1]), 0, 160);
        }
        if ($ics !== '' && preg_match('/^X-WR-CALNAME:(.+)$/mi', $ics, $m) === 1) {
            $own = trim(str_replace(['\\,', '\\;', '\\n', '\\\\'], [',', ';', ' ', '\\'], $m[1]));
            if ($own !== '') {
                return mb_substr($own, 0, 160);
            }
        }
        return mb_substr(trim($name) !== '' ? trim($name) : 'Imported', 0, 160);
    }
}
