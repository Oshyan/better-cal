<?php
// The probe's report: <dir>/probe-<stamp>.md (a section per group, a table
// per group with a row per case) and <dir>/probe-<stamp>.json (every
// exchange, raw). Both are brought up to date after every case, so a run
// that stops leaves what it got. Everything goes through scrub() first: the account's
// address, the calendar id, a guest address, event links (Google's htmlLink
// encodes the calendar's address), display names and sync tokens never
// reach either file.
//
// Before that, every exchange passes through keep() as it is recorded (a
// probe case's step, an access probe row): an event the probe did not make
// is cut to its id and status, wherever it appears in an answer (a list, a
// delta, instances, a GET or a write's answer). What the probe made is
// decided by its own record, never by what an event holds: the ids its
// inserts and imports were answered with (keep() notes them itself) and any
// it names with made() (the access probe's state from earlier stages); a day
// of one of those series is the probe's too.
declare(strict_types=1);

namespace BetterCal\GoogleProbe;

final class Report
{
    public readonly string $mdPath;
    public readonly string $jsonPath;
    /** @var array<string,mixed> */
    public array $run = [];
    /** @var array<string,array{title:string, about:string, manual?:string, skipped?:string}> */
    private array $groups = [];
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    /** @var list<array> the markdown's rows */
    private array $rows = [];
    /** @var resource|null */
    private $json = null;
    private int $casesEnd = 0;
    private int $written = 0;
    /** @var array<string,string> secret => placeholder */
    private array $secrets = [];
    /** @var array<string,string> sync and page tokens => short labels */
    private array $tokens = [];
    /** @var array<string,true> ids of the events the probe made */
    private array $made = [];

    public function __construct(string $dir, ?\DateTimeImmutable $at = null)
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot make the report folder $dir");
        }
        $at ??= new \DateTimeImmutable('now', Api::localZone());
        // ISO 8601 basic time and offset, so the name has no colons.
        $stamp = $at->format('Y-m-d\THisO');
        $this->mdPath = rtrim($dir, '/') . "/probe-$stamp.md";
        $this->jsonPath = rtrim($dir, '/') . "/probe-$stamp.json";
    }

    /** A value that must never be written, and what stands in for it. */
    public function secret(string $value, string $placeholder): void
    {
        if (trim($value) !== '') {
            $this->secrets[$value] = $placeholder;
        }
    }

    /** An event the probe made (in an earlier run, for the access probe's stages). */
    public function made(string $id): void
    {
        if ($id !== '') {
            $this->made[$id] = true;
        }
    }

    /**
     * The exchange as it may be recorded: every event in its answer that the
     * probe did not make is cut to its id and status. A 2xx insert or import
     * adds the id it was answered with to what the probe made, first.
     */
    public function keep(array $exchange): array
    {
        $path = (string) ($exchange['path'] ?? '');
        $path = str_starts_with($path, '/calendars/{calendar}') ? substr($path, strlen('/calendars/{calendar}')) : $path;
        $answer = $exchange['response'] ?? null;
        if (!is_array($answer) || ($path !== '/events' && !str_starts_with($path, '/events/'))) {
            return $exchange;
        }
        $status = (int) ($exchange['status'] ?? 0);
        if (($exchange['method'] ?? '') === 'POST' && in_array($path, ['/events', '/events/import'], true) && $status >= 200 && $status < 300 && isset($answer['id'])) {
            $this->made((string) $answer['id']);
        }
        if (isset($answer['items']) && is_array($answer['items'])) {
            $answer['items'] = array_map(fn($e) => is_array($e) ? $this->ownOrPlaceholder($e) : $e, $answer['items']);
        } elseif (isset($answer['id'])) {
            $answer = $this->ownOrPlaceholder($answer);
        }
        $exchange['response'] = $answer;
        return $exchange;
    }

    private function ownOrPlaceholder(array $event): array
    {
        $id = (string) ($event['id'] ?? '');
        $series = (string) ($event['recurringEventId'] ?? '');
        $cut = strpos($id, '_');
        if (isset($this->made[$id]) || ($series !== '' && isset($this->made[$series])) || ($cut !== false && isset($this->made[substr($id, 0, $cut)]))) {
            return $event;
        }
        return array_intersect_key($event, ['id' => true, 'status' => true]);
    }

    public function group(string $id, string $title, string $about): void
    {
        $this->groups[$id] = ['title' => $title, 'about' => $about];
    }

    public function groupNote(string $id, string $kind, string $text): void
    {
        $this->groups[$id][$kind] = $text;
        $this->write();
    }

    public function add(CaseRun $case): void
    {
        $data = $this->scrub($case->toArray());
        $this->rows[] = array_intersect_key($data, array_flip(['group', 'id', 'title', 'sent', 'cameBack', 'finding', 'error']));
        // Appended once, never re-encoded: a case can carry thousands of instances.
        $this->openJson();
        fseek($this->json, $this->casesEnd);
        fwrite($this->json, ($this->written > 0 ? ",\n" : '') . json_encode($data, self::JSON));
        $this->casesEnd = (int) ftell($this->json);
        $this->written++;
        $this->write();
    }

    /** @return list<array> the case rows written so far, scrubbed */
    public function cases(): array
    {
        return $this->rows;
    }

    /**
     * The JSON is {"cases": [...], "run": {...}, "groups": {...}}, valid
     * after every write: the cases are appended in place and the run and
     * groups rewritten after them.
     */
    public function write(): void
    {
        $this->openJson();
        $run = $this->scrub($this->run);
        $groups = $this->scrub($this->groups);
        fseek($this->json, $this->casesEnd);
        fwrite($this->json, "\n],\n\"run\": " . json_encode($run, self::JSON) . ",\n\"groups\": " . json_encode($groups, self::JSON) . "\n}\n");
        ftruncate($this->json, (int) ftell($this->json));
        fflush($this->json);
        file_put_contents($this->mdPath, $this->markdown(['run' => $run, 'groups' => $groups, 'cases' => $this->rows]));
    }

    private function openJson(): void
    {
        if ($this->json !== null) {
            return;
        }
        $this->json = fopen($this->jsonPath, 'w+') ?: throw new \RuntimeException("cannot write {$this->jsonPath}");
        fwrite($this->json, "{\n\"cases\": [\n");
        $this->casesEnd = (int) ftell($this->json);
    }

    public function scrub(mixed $v, ?string $key = null): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $item) {
                $out[$k] = $this->scrub($item, is_string($k) ? $k : $key);
            }
            return $out;
        }
        if (!is_string($v)) {
            return $v;
        }
        if (in_array($key, ['htmlLink', 'hangoutLink'], true)) {
            return '{link}';
        }
        if ($key === 'displayName') {
            return '{name}';
        }
        if (in_array($key, ['syncToken', 'nextSyncToken', 'pageToken', 'nextPageToken'], true)) {
            return $this->tokens[$v] ??= ($key === 'syncToken' || $key === 'nextSyncToken' ? 'sync-' : 'page-') . (count($this->tokens) + 1);
        }
        foreach ($this->secrets as $secret => $placeholder) {
            $v = str_ireplace([$secret, rawurlencode($secret)], $placeholder, $v);
        }
        if ($key === 'email' && !in_array($v, $this->secrets, true) && !str_starts_with($v, '{')) {
            return '{another address}';
        }
        return $v;
    }

    private function markdown(array $data): string
    {
        $run = $data['run'];
        $md = "# Google Probe Report\n\n";
        $md .= 'Run ' . ($run['started'] ?? '') . (isset($run['finished']) ? ', finished ' . $run['finished'] : ', not finished (the JSON beside this file has every exchange so far)') . ". ";
        $md .= 'Calendar zone ' . ($run['calendarZone'] ?? 'unknown') . '; ' . ($run['requests'] ?? 0) . ' requests; groups ' . implode(', ', $run['groups'] ?? []) . ".\n\n";
        if (!empty($run['notes'])) {
            foreach ($run['notes'] as $note) {
                $md .= '- ' . self::cell($note) . "\n";
            }
            $md .= "\n";
        }
        if (isset($run['cleanup'])) {
            $md .= 'Cleanup: ' . self::cell((string) $run['cleanup']) . "\n\n";
        }
        $md .= "Each finding says only what the read-back showed. Addresses, the calendar id, event links and sync tokens are replaced by placeholders here and in the JSON, and an event the probe did not make is cut to its id and status. Findings go into [the model's Google section](../../docs/design/series-model.md#google-as-a-contract) by hand.\n\n";
        foreach ($data['groups'] as $gid => $g) {
            $md .= "## $gid. {$g['title']}\n\n{$g['about']}\n\n";
            if (isset($g['skipped'])) {
                $md .= "Not run: {$g['skipped']}\n\n";
            }
            if (isset($g['manual'])) {
                $md .= $g['manual'] . "\n\n";
            }
            $rows = array_values(array_filter($data['cases'], static fn(array $c) => (string) $c['group'] === (string) $gid));
            if ($rows === []) {
                continue;
            }
            $md .= "| Case | Sent | Came back | Finding |\n|---|---|---|---|\n";
            foreach ($rows as $c) {
                $md .= '| ' . self::cell($c['id'] . ' ' . $c['title']) . ' | ' . self::cell($c['sent']) . ' | ' . self::cell($c['cameBack']) . ' | ' . self::cell($c['finding']) . " |\n";
            }
            $md .= "\n";
        }
        return $md;
    }

    private static function cell(string $s): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', '', ' '], $s);
    }
}

/** One case as it ran: every exchange by phase, the read-backs, the finding. */
final class CaseRun
{
    /** @var list<array{phase:string, label:string, exchange:array}> */
    public array $steps = [];
    /** @var list<string> masters (and single events) this case created */
    public array $created = [];
    /** @var list<string> ids the writes touched, waited for in the delta */
    public array $touched = [];
    /** @var list<string> ids the setup touched, waited for before the write */
    public array $setupTouched = [];
    /** @var list<string> ids of the series read back (the case's own events) */
    public array $series = [];
    public array $before = [];
    public array $after = [];
    public array $delta = ['items' => [], 'tries' => 0];
    public array $secondDelta = ['items' => []];
    /** @var array<string,string> instance key => what the case made of it */
    public array $labels = [];
    /** @var list<string> */
    public array $notes = [];
    public int $settleTries = 0;
    public string $sent = '';
    public string $cameBack = '';
    public string $finding = '';
    public ?string $error = null;
    public readonly string $started;

    public function __construct(private readonly Report $report, public readonly string $group, public readonly string $id, public readonly string $title)
    {
        $this->started = Api::now();
    }

    /** Records the exchange as Report::keep() leaves it; returns it whole, for the case to read. */
    public function step(string $phase, string $label, array $exchange): array
    {
        $this->steps[] = ['phase' => $phase, 'label' => $label, 'exchange' => $this->report->keep($exchange)];
        return $exchange;
    }

    /** @return list<array> the write exchanges, in order */
    public function writes(): array
    {
        return array_values(array_map(static fn(array $s) => $s['exchange'], array_filter($this->steps, static fn(array $s) => $s['phase'] === 'write')));
    }

    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'id' => $this->id,
            'title' => $this->title,
            'started' => $this->started,
            'sent' => $this->sent,
            'cameBack' => $this->cameBack,
            'finding' => $this->finding,
            'error' => $this->error,
            // The read-backs and deltas are in the steps, raw; these are their tallies.
            'delta' => ['tries' => $this->delta['tries'] ?? 0, 'items' => count($this->delta['items'] ?? []), 'showedEveryWrite' => $this->delta['showedEveryWrite'] ?? true],
            'secondDelta' => ['items' => count($this->secondDelta['items'] ?? [])],
            'settleTries' => $this->settleTries,
            'labels' => $this->labels,
            'notes' => $this->notes,
            'steps' => $this->steps,
        ];
    }
}
