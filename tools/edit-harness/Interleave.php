<?php
// The interleaving mode (README, Interleaving; docs/design/interleaving-invariants.md): a Google-backed series from
// the drawn space, then a random run of steps mixing writes here, changes made at Google by someone else, polls,
// failures injected into a write, Finish, Put back, undo and redo. After every step the case records what each side
// holds (the rows here and the stand-in) and checks the invariants of interleaving-invariants.md, named x-*. What a
// step changed is the harness's own record of the request it sent; what each side holds is read from the rows and
// the stand-in; nothing is taken from the product's answers or snapshots as an expectation.
declare(strict_types=1);

namespace BetterCal\EditHarness;

use BetterCal\Domain\Events;
use BetterCal\Domain\GoogleSeries;
use BetterCal\Domain\GoogleWriter;
use BetterCal\Domain\Recurrence;
use BetterCal\Domain\Undo;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;

final class Interleave
{
    /** The step kinds a case draws, by weight. */
    public const WEIGHTS = [
        'w-all' => 10, 'w-follow' => 3, 'w-delfollow' => 2, 'w-answer' => 2, 'w-this-g' => 6, 'w-this-l' => 8, 'w-this-m' => 4, 'w-del1' => 3, 'w-create' => 2,
        'r-rename' => 3, 'r-time' => 3, 'r-zone' => 2, 'r-length' => 2, 'r-rule' => 2, 'r-bound' => 3, 'r-skip+' => 2, 'r-skip-' => 1, 'r-exc+' => 3, 'r-exc-' => 1, 'r-gonly' => 3, 'r-delete' => 1,
        'p-delta' => 8, 'p-whole' => 3, 'p-410' => 2,
        'f-lost' => 5, 'f-before' => 3, 'f-fatal' => 3,
        'finish' => 5, 'putback' => 5,
        'u-entry' => 4, 'u-bare' => 5, 'redo' => 2,
    ];
    /** Fields only Google holds (beyond the digest; the A2 scope): the app never sends them, so its writes never change them. */
    public const GOOGLE_ONLY = ['colorId', 'attendees', 'transparency', 'visibility', 'reminders', 'guestsCanModify', 'guestsCanInviteOthers', 'extendedProperties'];
    /** What a write never sends (google-calendar.md, What Writes Send: "Not sent: attendees, reminders and color"). */
    public const NEVER_SENT = ['attendees', 'reminders', 'colorId'];
    /** The fields held here alone a step can send. */
    private const LOCAL = ['tags', 'people', 'reminders', 'icon', 'coords', 'attendance'];
    private const OTHER_ZONES = ['America/Phoenix', 'Europe/Berlin', 'Asia/Tokyo', 'America/New_York', 'Pacific/Auckland'];

    private array $records = [];
    private array $runs = [];
    private array $na = [];
    private array $spec = [];
    private int $firstId = 0;
    private int $step = 0;
    /** @var array<string,true> Google ids this case has seen, so a removed one reads as null */
    private array $gids = [];
    /** @var array<string,true> series (by Google id) changed at Google since the last poll */
    private array $dirty = [];
    /** @var array<int,array> the harness's model of each change half done at Google, by its pending id */
    private array $pendings = [];
    /** The half-done change a Finish or Put back acts on, or null. */
    private ?array $pending = null;
    /** The change a Finish or Put back that went through settled, for its x-alone. */
    private ?array $recovered = null;
    /** @var array<int,array> each step's own record: what it asked and its entries */
    private array $done = [];
    /** @var list<array> what each step touched (series, field, day), for staleness and Finish */
    private array $touches = [];

    public function __construct(
        private readonly Db $db,
        private readonly Events $events,
        private readonly Undo $undo,
        private readonly GoogleStand $stand,
        private readonly int $userId,
    ) {
    }

    // ---- drawing --------------------------------------------------------------

    /** A case: a series from the drawn space that a Google calendar can hold, then 1 to 3 setup steps and 3 to 8 steps. */
    public static function draw(int $seed, int $n): array
    {
        $r = new Rng($seed * 1_000_033 + $n * 7 + 3);
        $series = null;
        for ($i = 0; $i < 40 && $series === null; $i++) {
            $s = Space::series($r);
            if ($s['import'] || GoogleStand::unheld($s['rrule']) !== null) {
                continue;
            }
            $series = $s;
        }
        $series ??= ['family' => 'weekly_bare', 'rrule' => 'FREQ=WEEKLY', 'import' => false, 'bound' => 'count', 'boundAt' => 8, 'untilForm' => 'dayend',
            'allDay' => false, 'tzid' => 'America/Los_Angeles', 'start' => '2027-03-01 10:00', 'length' => 60];
        $steps = [];
        foreach (range(1, $r->int(1, 3)) as $_) {
            $steps[] = self::drawStep($r, $r->pick(['w-this-g', 'w-this-l', 'w-this-l', 'w-this-m', 'w-del1']));
        }
        foreach (range(1, $r->int(3, 8)) as $_) {
            $steps[] = self::drawStep($r, $r->weighted(self::WEIGHTS));
        }
        return ['seed' => $seed, 'n' => $n, 'series' => $series, 'steps' => $steps];
    }

    private static function drawStep(Rng $r, string $do): array
    {
        $step = ['do' => $do, 'pick' => $r->int(0, 999) / 1000];
        $what = match ($do) {
            'w-all' => $r->pick(['title', 'title', 'description', 'location', 'url', 'tags', 'time', 'length', 'count']),
            'w-follow' => $r->pick(['title', 'tags', 'description']),
            'w-this-g' => $r->pick(['title', 'description', 'location', 'time']),
            'w-this-l' => $r->pick(['tags', 'tags', 'people', 'reminders', 'icon', 'coords']),
            'w-this-m' => $r->pick(['title+tags', 'description+reminders', 'location+coords']),
            'r-gonly' => $r->pick(['colorId', 'attendees', 'transparency', 'visibility']),
            default => null,
        };
        if (in_array($do, ['f-lost', 'f-before', 'f-fatal'], true)) {
            $step['k'] = $r->int(1, 3);
            $step['write'] = $r->pick(['w-all', 'w-all', 'w-all', 'w-follow', 'w-delfollow']);
            $what = $step['write'] === 'w-delfollow' ? null : $r->pick(['title', 'title', 'tags', 'time', 'description']);
            if ($step['write'] === 'w-follow' && $what === 'time') {
                $what = 'title';
            }
        }
        if ($what !== null) {
            $step['what'] = $what;
        }
        return $step;
    }

    // ---- running a case ---------------------------------------------------------

    /** @return array{spec:array,records:list<array>,path:string,runs:array,unoracled:list<string>,product:array} */
    public function run(array $spec): array
    {
        $this->spec = $spec;
        $this->records = $this->runs = $this->na = $this->done = $this->touches = $this->dirty = $this->gids = [];
        $this->pending = $this->recovered = null;
        $this->pendings = [];
        $this->firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
        $this->step = 0;
        if ($this->createSeries($spec['series']) === null) {
            return $this->result('none');
        }
        $this->stand->poll();
        foreach ($spec['steps'] as $i => $step) {
            $this->step = $i + 1;
            $this->runStep($step);
            if (count($this->records) > 40) {
                break; // one cause rarely needs more than this to show
            }
        }
        return $this->result('steps');
    }

    private function result(string $path): array
    {
        return ['spec' => $this->spec, 'records' => $this->records, 'path' => $path, 'runs' => $this->runs, 'unoracled' => [], 'product' => $this->na === [] ? [] : ['na' => $this->na]];
    }

    private function createSeries(array $s): ?int
    {
        $in = ['calendarId' => $this->stand->calendarId, 'title' => 'Sample series', 'location' => 'Sample Hall', 'description' => 'Sample notes', 'allDay' => $s['allDay'], 'tzid' => $s['tzid'],
            'tagNames' => ['sample'], 'personNames' => ['Alex Example']];
        if ($s['allDay']) {
            $in['start'] = $s['start'];
            $in['end'] = Days::date(Days::ofDate($s['start']) + $s['length']);
        } else {
            $start = new \DateTimeImmutable($s['start'], Clock::zone($s['tzid']));
            $in['start'] = Clock::iso($start);
            $in['end'] = Clock::iso(Clock::plus($start, $s['length'] * 60));
        }
        $startUtc = $s['allDay'] ? $s['start'] . ' 00:00:00' : Clock::db(new \DateTimeImmutable($s['start'], Clock::zone($s['tzid'])));
        $rrule = $s['rrule'] . Driver::boundText($s, ['start_utc' => $startUtc, 'end_utc' => $startUtc, 'all_day' => $s['allDay'] ? 1 : 0, 'tzid' => $s['tzid'], 'rrule' => $s['rrule'], 'exdates_json' => null]);
        try {
            $id = (int) $this->events->create($this->userId, $in + ['rrule' => $rrule])['eventId'];
        } catch (HttpError $e) {
            $this->na['a series the product refuses to create on a Google calendar: ' . $e->errorCode] = 1;
            return null;
        }
        $this->spec['create'] = $in + ['rrule' => $rrule];
        $this->gids[(string) $this->db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id])] = true;
        return $id;
    }

    // ---- what each side holds ------------------------------------------------------

    /** The rows of the case on the Google calendar, live, keyed by id, with what hangs off them. */
    private function rows(): array
    {
        $rows = $this->db->all('SELECT * FROM events WHERE user_id = ? AND calendar_id = ? AND id >= ? AND deleted_at IS NULL ORDER BY id', [$this->userId, $this->stand->calendarId, $this->firstId]);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $r['tags'] = array_column($this->db->all('SELECT t.name FROM event_tags et JOIN tags t ON t.id = et.tag_id WHERE et.event_id = ? ORDER BY t.name', [$id]), 'name');
            $r['people'] = array_map('intval', array_column($this->db->all('SELECT person_id FROM event_people WHERE event_id = ? ORDER BY person_id', [$id]), 'person_id'));
            $r['links'] = array_map(static fn(array $l): string => ((int) $l['event_id'] === $id ? 'stop' : 'trip'), $this->db->all('SELECT event_id, container_id FROM event_links WHERE event_id = ? OR container_id = ?', [$id, $id]));
            sort($r['links']);
            $own = json_decode((string) ($r['override_fields'] ?? ''), true);
            $r['own'] = is_array($own) ? $own : [];
            sort($r['own']);
            $out[$id] = $r;
        }
        return $out;
    }

    private static function masters(array $rows): array
    {
        return array_filter($rows, static fn(array $r): bool => $r['rrule'] !== null && $r['recurrence_parent_id'] === null);
    }

    /** The comparable state here: every row's Google-held fields, its own list and what is held here alone, by id. */
    private function lstate(array $rows): array
    {
        $out = [];
        foreach ($rows as $id => $r) {
            $ex = Recurrence::decodeExdates($r['exdates_json'] ?? null);
            sort($ex);
            $out[$id] = [
                'uid' => $r['uid'], 'parent' => $r['recurrence_parent_id'], 'key' => $r['recurrence_instance_utc'], 'title' => $r['title'], 'description' => (string) $r['description'], 'location' => (string) $r['location'],
                'url' => (string) $r['url'], 'status' => $r['status'], 'start' => $r['start_utc'], 'end' => $r['end_utc'], 'allDay' => (int) $r['all_day'], 'tz' => $r['tzid'], 'rrule' => $r['rrule'], 'skips' => $ex,
                'own' => $r['own'], 'tags' => $r['tags'], 'people' => $r['people'], 'reminders' => $r['reminders_json'] ?? null, 'icon' => $r['icon'] ?? null, 'attendance' => $r['attendance'],
                'lat' => $r['location_lat'], 'lng' => $r['location_lng'], 'trip' => (int) ($r['is_container'] ?? 0), 'floating' => (int) ($r['floating'] ?? 0), 'links' => $r['links'],
            ];
        }
        return $out;
    }

    /** What the stand-in holds for each Google id the case has seen (GoogleStand::held plus the master's zone, notes, place, link and status), and every resource's Google-only fields. */
    private function gstate(): array
    {
        $digest = [];
        foreach (array_keys($this->gids) as $gid) {
            $m = $this->stand->fake->events[$gid] ?? null;
            if ($m === null || ($m['status'] ?? '') === 'cancelled') {
                $digest[$gid] = null;
                continue;
            }
            if (!empty($m['recurrence'])) {
                $h = $this->stand->held($gid);
                if ($h !== null) {
                    $h += ['zone' => isset($m['start']['date']) ? null : ($m['start']['timeZone'] ?? null), 'description' => (string) ($m['description'] ?? ''), 'location' => (string) ($m['location'] ?? ''),
                        'url' => (string) ($m['source']['url'] ?? ''), 'status' => (string) ($m['status'] ?? 'confirmed')];
                }
                $digest[$gid] = $h;
            } else {
                $digest[$gid] = ['single' => true, 'title' => (string) ($m['summary'] ?? ''), 'description' => (string) ($m['description'] ?? ''), 'location' => (string) ($m['location'] ?? ''), 'url' => (string) ($m['source']['url'] ?? '')];
            }
        }
        ksort($digest);
        $only = [];
        foreach ($this->stand->fake->events as $id => $e) {
            $series = explode('_', (string) $id, 2)[0];
            if (!isset($this->gids[$series]) && !isset($this->gids[(string) $id])) {
                continue;
            }
            $o = array_intersect_key($e, array_flip(self::GOOGLE_ONLY));
            if ($o !== []) {
                ksort($o);
                $only[$id] = $o;
            }
        }
        ksort($only);
        return ['digest' => $digest, 'only' => $only];
    }

    /** The rows here in gstate's digest shape, for every live master of the case. */
    private function ldigest(array $rows): array
    {
        $out = [];
        foreach (self::masters($rows) as $r) {
            if (empty($r['google_event_id'])) {
                continue;
            }
            $children = array_values(array_filter($rows, static fn(array $c): bool => (int) ($c['recurrence_parent_id'] ?? 0) === (int) $r['id']));
            $out[(string) $r['google_event_id']] = GoogleStand::stored($r, $children) + ['zone' => (int) $r['all_day'] === 1 ? null : $r['tzid'], 'description' => (string) $r['description'],
                'location' => (string) $r['location'], 'url' => (string) $r['url'], 'status' => (string) $r['status']];
        }
        return $out;
    }

    /**
     * What is held here alone (interleaving-invariants.md, What Equal Means), by the day it stands for: a master's
     * values under '*', a row's values that differ from its master's under its day in the series' zone. Each item is
     * a string naming its kind and value, mapped to the row that held it.
     *
     * @return array<string,array<string,int>> day => item => row id
     */
    private static function alone(array $rows): array
    {
        $items = static function (array $r): array {
            $it = [];
            foreach ($r['tags'] as $t) {
                $it[] = "tag:$t";
            }
            foreach ($r['people'] as $p) {
                $it[] = "person:$p";
            }
            if (($r['reminders_json'] ?? null) !== null) {
                $it[] = 'reminders:' . $r['reminders_json'];
            }
            if (($r['icon'] ?? null) !== null && $r['icon'] !== '') {
                $it[] = 'icon:' . $r['icon'];
            }
            if ($r['attendance'] !== 'none') {
                $it[] = 'attendance:' . $r['attendance'];
            }
            if ($r['location_lat'] !== null) {
                $it[] = 'coords:' . round((float) $r['location_lat'], 5) . ',' . round((float) $r['location_lng'], 5);
            }
            if ((int) ($r['is_container'] ?? 0) === 1) {
                $it[] = 'trip';
            }
            if ((int) ($r['floating'] ?? 0) === 1) {
                $it[] = 'floating';
            }
            foreach ($r['links'] as $l) {
                $it[] = "link:$l";
            }
            return $it;
        };
        $out = [];
        foreach ($rows as $id => $r) {
            if ($r['recurrence_parent_id'] === null && $r['rrule'] !== null) {
                foreach ($items($r) as $i) {
                    $out['*'][$i] = $id;
                }
                continue;
            }
            $master = $r['recurrence_parent_id'] !== null ? ($rows[(int) $r['recurrence_parent_id']] ?? null) : null;
            $base = $master === null ? [] : $items($master);
            $day = self::dayOf($r, $master);
            foreach (array_diff($items($r), $base) as $i) {
                $out[$day][$i] = $id;
            }
        }
        return $out;
    }

    /** A row's day: its key's date in its series' zone (its own start's date for an event of its own). */
    private static function dayOf(array $r, ?array $master): string
    {
        $key = (string) ($r['recurrence_instance_utc'] ?? $r['start_utc']);
        $allDay = (int) ($master['all_day'] ?? $r['all_day']) === 1;
        return $allDay ? substr($key, 0, 10) : Clock::local($key, (string) ($master['tzid'] ?? $r['tzid']))->format('Y-m-d');
    }

    /** A master's raw occurrences as stored now (its skips not applied), by date in its zone: date => key. */
    private static function days(array $master, int $limit = 60): array
    {
        $allDay = (int) $master['all_day'] === 1;
        $out = [];
        foreach (Truth::expand($master, $limit)['occ'] as $o) {
            $out[$allDay ? substr($o['key'], 0, 10) : Clock::local($o['key'], (string) $master['tzid'])->format('Y-m-d')] = $o['key'];
        }
        return $out;
    }

    private function snap(): array
    {
        $rows = $this->rows();
        foreach (self::masters($rows) as $r) {
            if (!empty($r['google_event_id'])) {
                $this->gids[(string) $r['google_event_id']] = true;
            }
        }
        return ['rows' => $rows, 'l' => $this->lstate($rows), 'g' => $this->gstate(), 'req' => count($this->stand->fake->requests),
            'mut' => (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM mutations WHERE user_id = ?', [$this->userId]), 'half' => GoogleSeries::halfDoneOf($this->db, $this->userId)];
    }

    /** The app's writes to the stand-in between two snapshots (the remote client's are marked and left out). */
    private function writesBetween(array $s0, array $s1): array
    {
        $out = [];
        foreach (array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']) as $q) {
            if ($q['method'] !== 'GET' && !in_array('X-Harness: remote', $q['headers'], true)) {
                $out[] = $q;
            }
        }
        return $out;
    }

    // ---- the steps ---------------------------------------------------------------------

    private function runStep(array $step): void
    {
        $do = $step['do'];
        $s0 = $this->snap();
        $knobs = $this->stand->knobs;
        $knobs->writes = $knobs->taken = 0;
        $knobs->fired = null;
        $out = ['ok' => true, 'error' => null, 'extra' => [], 'asked' => null, 'skip' => false];
        try {
            $out = match (true) {
                str_starts_with($do, 'w-') => $this->write($do, $step, $s0),
                str_starts_with($do, 'r-') => $this->remote($do, $step, $s0),
                str_starts_with($do, 'p-') => $this->poll($do),
                str_starts_with($do, 'f-') => $this->failing($do, $step, $s0),
                $do === 'finish', $do === 'putback' => $this->recover($do, $step, $s0),
                default => $this->undoStep($do, $step),
            } + $out;
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['error'] = $e instanceof HttpError ? $e->errorCode : get_class($e);
            $out['message'] = mb_substr($e->getMessage(), 0, 300);
            $out['extra'] = $e instanceof HttpError ? $e->extra : [];
            $out['thrown'] = !($e instanceof HttpError);
            $out['asked'] ??= $this->lastAsked;
        }
        $this->lastAsked = null;
        $this->spec['steps'][$this->step - 1]['ran'] = $out['skip'] ? 'skipped' : ($out['ok'] ? 'ok' : (string) $out['error']);
        if ($out['skip']) {
            $this->ran('skipped', $do);
            return;
        }
        $this->ran('step', $do . ($out['ok'] ? '' : ' refused'));
        $this->check($do, $out, $s0, $this->snap());
    }

    /** What the step about to fail asked (set before the product is called, so a refusal still knows its series). */
    private ?array $lastAsked = null;

    /** The series a step acts on: one of the case's live masters, by its pick. */
    private function seriesOf(array $rows, float $pick): ?array
    {
        $ms = array_values(self::masters($rows));
        return $ms === [] ? null : $ms[min(count($ms) - 1, (int) floor($pick * count($ms)))];
    }

    /** One of a master's first occurrences that is no skip, by the pick: [key, date]. */
    private function occurrenceOf(array $master, float $pick, int $from = 0): ?array
    {
        $skips = Recurrence::decodeExdates($master['exdates_json'] ?? null);
        $days = array_filter(self::days($master, 14), static fn(string $k): bool => !in_array($k, $skips, true));
        $days = array_slice($days, $from, null, true);
        if ($days === []) {
            return null;
        }
        $dates = array_keys($days);
        $date = $dates[min(count($dates) - 1, (int) floor($pick * count($dates)))];
        return [$days[$date], $date];
    }

    private static function instanceArg(array $master, string $key): string
    {
        return (int) $master['all_day'] === 1 ? substr($key, 0, 10) : Clock::iso(Clock::local($key, (string) $master['tzid']));
    }

    /** The field values a step sends, by its kind and its step number (so each value is new). @return array{0:array,1:array} */
    private function values(string $what, array $master): array
    {
        $n = $this->step;
        $in = [];
        $fields = [];
        foreach (explode('+', $what) as $w) {
            switch ($w) {
                case 'title':
                    $in['title'] = $fields['title'] = "Sample title $n";
                    break;
                case 'description':
                    $in['description'] = $fields['description'] = "Sample notes $n";
                    break;
                case 'location':
                    $in['location'] = $fields['location'] = "Sample room $n";
                    break;
                case 'url':
                    $in['url'] = $fields['url'] = "https://example.test/sample-$n";
                    break;
                case 'tags':
                    $in['tagNames'] = $fields['tags'] = ['sample', "own-$n"];
                    break;
                case 'people':
                    $in['personNames'] = ['Alex Example', 'Sam Sample'];
                    $fields['people'] = true;
                    break;
                case 'reminders':
                    $in['reminders'] = [['minutes' => 10 + $n]];
                    $fields['reminders'] = true;
                    break;
                case 'icon':
                    $in['icon'] = $fields['icon'] = 'star';
                    break;
                case 'coords':
                    // A picked place for the text the day already has: the coordinates alone (A1: held here alone).
                    $in['locationLat'] = 37.5 + $n / 100;
                    $in['locationLng'] = -122.25;
                    $fields['coords'] = true;
                    break;
            }
        }
        return [$in, $fields];
    }

    /** A write here: the harness's record of it ('asked') before the product is called. */
    private function write(string $do, array $step, array $s0): array
    {
        $rows = $s0['rows'];
        if ($do === 'w-create') {
            $day = Days::date(Days::ofDate('2027-02-01') + (int) floor($step['pick'] * 200));
            $this->lastAsked = ['kind' => 'create', 'gid' => null, 'fields' => [], 'days' => [$day]];
            $id = (int) $this->events->create($this->userId, ['calendarId' => $this->stand->calendarId, 'title' => "Sample event {$this->step}", 'start' => $day . 'T15:00:00+00:00', 'end' => $day . 'T16:00:00+00:00',
                'allDay' => false, 'tzid' => 'UTC', 'tagNames' => ['single']])['eventId'];
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked];
        }
        $master = $this->seriesOf($rows, $step['pick']);
        if ($master === null) {
            return ['skip' => true];
        }
        $gid = (string) $master['google_event_id'];
        $mid = (int) $master['id'];
        $what = (string) ($step['what'] ?? 'title');
        if (in_array($do, ['w-this-g', 'w-this-l', 'w-this-m', 'w-del1'], true)) {
            $occ = $this->occurrenceOf($master, fmod($step['pick'] * 7.0, 1.0));
            if ($occ === null) {
                return ['skip' => true];
            }
            [$key, $date] = $occ;
            $inst = self::instanceArg($master, $key);
            if ($do === 'w-del1') {
                $this->lastAsked = ['kind' => 'del1', 'gid' => $gid, 'key' => $key, 'fields' => [], 'days' => [$date]];
                $this->events->deleteEvent($this->userId, $mid, 'this', $inst);
                $this->events->throwDeferred();
                return ['asked' => $this->lastAsked];
            }
            if ($what === 'time') {
                if ((int) $master['all_day'] === 1) {
                    return ['skip' => true];
                }
                $z = Clock::zone((string) $master['tzid']);
                $start = Clock::parse($key);
                $len = Clock::parse((string) $master['end_utc'])->getTimestamp() - Clock::parse((string) $master['start_utc'])->getTimestamp();
                $shift = (int) Clock::local($key, (string) $master['tzid'])->format('G') >= 20 ? -3600 : 3600;
                $in = ['start' => Clock::iso(Clock::plus($start, $shift)->setTimezone($z)), 'end' => Clock::iso(Clock::plus($start, $shift + $len)->setTimezone($z)), 'tzid' => $master['tzid'], 'allDay' => false];
                $fields = ['time' => true];
            } else {
                [$in, $fields] = $this->values($what, $master);
            }
            $this->lastAsked = ['kind' => 'this', 'gid' => $gid, 'key' => $key, 'fields' => $fields, 'days' => [$date], 'local' => array_diff(array_keys($fields), self::LOCAL) === []];
            $this->events->patch($this->userId, $mid, $in + ['scope' => 'this', 'instanceStart' => $inst]);
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked];
        }
        // A change from the series' first day (all events), or a split from a later one.
        $first = $this->occurrenceOf($master, 0.0);
        if ($first === null) {
            return ['skip' => true];
        }
        $at = $do === 'w-all' ? $first : ($this->occurrenceOf($master, $step['pick'], 1) ?? $first);
        $inst = self::instanceArg($master, $at[0]);
        $days = $do === 'w-all' ? ['*'] : ['>=' . $at[1]];
        if ($do === 'w-delfollow') {
            $this->lastAsked = ['kind' => 'delfollow', 'gid' => $gid, 'fields' => [], 'days' => $days];
            $plan = $this->events->previewDelete($this->userId, $mid, 'following', $inst);
            $this->events->deleteEvent($this->userId, $mid, 'following', $inst, null, !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => self::selected($plan)]);
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked];
        }
        if ($do === 'w-answer') {
            $this->lastAsked = ['kind' => 'answer', 'gid' => $gid, 'fields' => ['attendance' => 'going'], 'days' => $days, 'local' => true];
            $plan = $this->events->previewAttendance($this->userId, $mid, 'going', 'following', $inst);
            $this->events->setAttendance($this->userId, $mid, 'going', 'following', $inst, !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => self::selected($plan)]);
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked];
        }
        $scope = $do === 'w-all' ? 'all' : 'following';
        $in = ['scope' => $scope, 'instanceStart' => $inst];
        $fields = [];
        if ($what === 'time' || $what === 'length') {
            if ((int) $master['all_day'] === 1 || $scope !== 'all') {
                return ['skip' => true];
            }
            $start = Clock::parse((string) $master['start_utc']);
            $len = Clock::parse((string) $master['end_utc'])->getTimestamp() - $start->getTimestamp();
            $shift = $what === 'length' ? 0 : ((int) Clock::local((string) $master['start_utc'], (string) $master['tzid'])->format('G') >= 20 ? -3600 : 3600);
            $len += $what === 'length' ? 1800 : 0;
            $z = Clock::zone((string) $master['tzid']);
            $in += ['start' => Clock::iso(Clock::plus($start, $shift)->setTimezone($z)), 'end' => Clock::iso(Clock::plus($start, $shift + $len)->setTimezone($z)), 'tzid' => $master['tzid'], 'allDay' => false];
            $fields[$what] = true;
        } elseif ($what === 'count') {
            $in['rrule'] = preg_replace('/;(COUNT|UNTIL)=[^;]*/', '', (string) $master['rrule']) . ';COUNT=' . (3 + (int) floor($step['pick'] * 8));
            $fields['count'] = true;
        } else {
            [$more, $fields] = $this->values($what, $master);
            $in += $more;
        }
        $this->lastAsked = ['kind' => $scope, 'gid' => $gid, 'fields' => $fields, 'days' => $days, 'from' => $at[1], 'local' => array_diff(array_keys($fields), self::LOCAL) === []];
        $plan = $this->events->previewPatch($this->userId, $mid, $in + ['plan' => ['choices' => []]]);
        $this->events->patch($this->userId, $mid, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => self::selected($plan)]]);
        $this->events->throwDeferred();
        return ['asked' => $this->lastAsked];
    }

    private static function selected(array $plan): array
    {
        $c = [];
        foreach ($plan['groups'] ?? [] as $g) {
            $c[$g['id']] = $g['selected'];
        }
        return $c;
    }

    /** A write with a failure injected at its k-th write to Google. */
    private function failing(string $do, array $step, array $s0): array
    {
        $knobs = $this->stand->knobs;
        $knobs->{['f-lost' => 'loseAt', 'f-before' => 'beforeAt', 'f-fatal' => 'fatalAt'][$do]} = (int) ($step['k'] ?? 1);
        try {
            return $this->write((string) $step['write'], ['do' => $step['write']] + $step, $s0);
        } finally {
            $knobs->loseAt = $knobs->beforeAt = $knobs->fatalAt = null;
        }
    }

    /** A change made at Google by someone else, through the stand-in's own API (no If-Match), marked as the remote client's. */
    private function remote(string $do, array $step, array $s0): array
    {
        $master = $this->seriesOf($s0['rows'], $step['pick']);
        if ($master === null) {
            return ['skip' => true];
        }
        $gid = (string) $master['google_event_id'];
        $g = $this->stand->fake->events[$gid] ?? null;
        if ($g === null || ($g['status'] ?? '') === 'cancelled') {
            return ['skip' => true];
        }
        $allDay = isset($g['start']['date']);
        $tz = (string) ($g['start']['timeZone'] ?? 'UTC');
        $n = $this->step;
        $occ = $this->occurrenceOf($master, fmod($step['pick'] * 13.0, 1.0));
        $instance = $occ === null ? null : GoogleWriter::instanceId($gid, $occ[0], $allDay, (string) $master['tzid']);
        $shift = static function (array $when, int $secs) use ($tz): array {
            $t = (new \DateTimeImmutable((string) $when['dateTime']))->setTimezone(new \DateTimeZone($tz))->modify(($secs >= 0 ? '+' : '') . $secs . ' seconds');
            return ['dateTime' => $t->format('Y-m-d\TH:i:sP'), 'timeZone' => $tz];
        };
        $rule = '';
        foreach ((array) ($g['recurrence'] ?? []) as $line) {
            if (str_starts_with((string) $line, 'RRULE:')) {
                $rule = substr((string) $line, 6);
            }
        }
        $bound = preg_match('/;((COUNT|UNTIL)=[^;]*)/', $rule, $m) === 1 ? ';' . $m[1] : '';
        $touch = static fn(string $field, ?string $key = null): array => ['field' => $field, 'key' => $key];
        $none = [null, null, []];
        switch ($do) {
            case 'r-rename':
                [$target, $body, $touched] = [$gid, ['summary' => "Sample remote title $n"], [$touch('title')]];
                break;
            case 'r-time':
                if ($allDay) {
                    return ['skip' => true];
                }
                $hour = (int) (new \DateTimeImmutable((string) $g['start']['dateTime']))->setTimezone(new \DateTimeZone($tz))->format('G');
                $s = $hour >= 20 ? -3600 : 3600;
                [$target, $body, $touched] = [$gid, ['start' => $shift($g['start'], $s), 'end' => $shift($g['end'], $s)], [$touch('time')]];
                break;
            case 'r-zone':
                if ($allDay) {
                    return ['skip' => true];
                }
                $zones = array_values(array_diff(self::OTHER_ZONES, [$tz]));
                $to = $zones[min(count($zones) - 1, (int) floor($step['pick'] * count($zones)))];
                $wall = static fn(array $when): array => ['dateTime' => (new \DateTimeImmutable((new \DateTimeImmutable((string) $when['dateTime']))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i:s'), new \DateTimeZone($to)))->format('Y-m-d\TH:i:sP'), 'timeZone' => $to];
                [$target, $body, $touched] = [$gid, ['start' => $wall($g['start']), 'end' => $wall($g['end'])], [$touch('time'), $touch('zone')]];
                break;
            case 'r-length':
                [$target, $body, $touched] = [$gid, ['end' => $allDay ? ['date' => (new \DateTimeImmutable($g['end']['date']))->modify('+1 day')->format('Y-m-d')] : $shift($g['end'], 1800)], [$touch('length')]];
                break;
            case 'r-rule':
                $new = str_contains($rule, 'FREQ=WEEKLY') && !str_contains($rule, 'BYDAY') && !str_contains($rule, 'INTERVAL') ? 'FREQ=DAILY;INTERVAL=2' : 'FREQ=WEEKLY';
                [$target, $body, $touched] = [$gid, ['recurrence' => ['RRULE:' . $new . $bound]], [$touch('rule')]];
                break;
            case 'r-bound':
                [$target, $body, $touched] = [$gid, ['recurrence' => ['RRULE:' . preg_replace('/;(COUNT|UNTIL)=[^;]*/', '', $rule) . ';COUNT=' . (1 + (int) floor($step['pick'] * 6))]], [$touch('rule')]];
                break;
            case 'r-skip+':
                [$target, $body, $touched] = $instance === null ? $none : [$instance, ['status' => 'cancelled'], [$touch('skip', $occ[0])]];
                break;
            case 'r-skip-':
                [$target, $body, $touched] = $none;
                foreach ($this->stand->fake->records($gid) as $key => $r) {
                    if (($r['status'] ?? '') === 'cancelled') {
                        [$target, $body, $touched] = [(string) $r['id'], ['status' => 'confirmed'], [$touch('skip', (string) $key)]];
                        break;
                    }
                }
                break;
            case 'r-exc+':
                [$target, $body, $touched] = $instance === null ? $none : [$instance, ['summary' => "Sample remote day $n", 'description' => "Sample remote notes $n"], [$touch('title', $occ[0]), $touch('description', $occ[0])]];
                break;
            case 'r-exc-':
                [$target, $body, $touched] = $none;
                foreach ($this->stand->fake->records($gid) as $key => $r) {
                    if (($r['status'] ?? '') !== 'cancelled') {
                        [$target, $body, $touched] = [(string) $r['id'], ['summary' => $g['summary'] ?? '', 'description' => $g['description'] ?? '', 'location' => $g['location'] ?? ''],
                            [$touch('title', (string) $key), $touch('description', (string) $key), $touch('location', (string) $key)]];
                        break;
                    }
                }
                break;
            case 'r-gonly':
                $target = ($step['pick'] > 0.5 && $instance !== null) ? $instance : $gid;
                $body = match ((string) ($step['what'] ?? 'colorId')) {
                    'attendees' => ['attendees' => [['email' => 'guest@example.test', 'responseStatus' => 'needsAction']]],
                    'transparency' => ['transparency' => 'transparent'],
                    'visibility' => ['visibility' => 'private'],
                    default => ['colorId' => (string) (1 + $n % 11)],
                };
                $touched = [];
                break;
            case 'r-delete':
                [$target, $body, $touched] = [$gid, null, [$touch('series')]];
                break;
            default:
                return ['skip' => true];
        }
        if ($target === null) {
            return ['skip' => true];
        }
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($target);
        $answer = ($this->stand->fake->transport())($do === 'r-delete' ? 'DELETE' : 'PATCH', $url, ['Authorization: Bearer sample-access', 'X-Harness: remote'], $body);
        if ($answer['status'] >= 300) {
            $this->na["$do refused by the stand-in: HTTP {$answer['status']}"] = 1;
            return ['skip' => true];
        }
        $this->dirty[$gid] = true;
        foreach ($touched as $t) {
            $this->touches[] = ['step' => $this->step, 'gid' => $gid, 'remote' => true, 'polled' => false] + $t;
        }
        foreach ($this->pendings as $id => $p) {
            $this->pendings[$id]['since'][$gid] = true;
        }
        return ['asked' => ['kind' => 'remote', 'gid' => $gid, 'fields' => [], 'days' => []]];
    }

    private function poll(string $do): array
    {
        if ($do === 'p-whole') {
            $this->db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$this->stand->calendarId]);
        } elseif ($do === 'p-410') {
            $this->db->run("UPDATE calendars SET google_sync_token = 'stale-token' WHERE id = ?", [$this->stand->calendarId]);
        }
        $this->stand->poll();
        return ['asked' => ['kind' => 'poll', 'gid' => null, 'fields' => [], 'days' => []]];
    }

    private function recover(string $do, array $step, array $s0): array
    {
        if ($s0['half'] === []) {
            return ['skip' => true];
        }
        $this->lastAsked = ['kind' => $do, 'gid' => null, 'fields' => [], 'days' => []];
        $id = (int) $s0['half'][min(count($s0['half']) - 1, (int) floor($step['pick'] * count($s0['half'])))]['id'];
        $this->pending = $this->pendings[$id] ?? null;
        $answer = $do === 'finish' ? $this->events->googleFinish($this->userId, $id) : $this->events->googlePutBack($this->userId, $id);
        $this->events->throwDeferred();
        return ['answer' => $answer, 'asked' => $this->lastAsked];
    }

    private static function undoes(?string $details): ?int
    {
        $d = json_decode((string) $details, true);
        return isset($d['google']['undoes']) ? (int) $d['google']['undoes'] : null;
    }

    private function undoStep(string $do, array $step): array
    {
        $entries = $this->db->all('SELECT id, source, details_json, undone FROM mutations WHERE user_id = ? ORDER BY id DESC', [$this->userId]);
        if ($do === 'u-bare') {
            $expect = null;
            foreach ($entries as $e) {
                if ((int) $e['undone'] === 0 && in_array($e['source'], ['web', 'quickadd', 'review', 'rsvp', 'dedup:owner'], true) && self::undoes($e['details_json']) === null) {
                    $expect = (int) $e['id'];
                    break;
                }
            }
            if ($expect === null) {
                return ['skip' => true];
            }
            $this->lastAsked = $this->askedOfEntry($expect) + ['entry' => $expect];
            $this->undo->undoLatest($this->userId);
            return ['entry' => $expect, 'asked' => $this->lastAsked];
        }
        $pool = array_values(array_filter($entries, static fn(array $e): bool => (int) $e['undone'] === 0
            && ($do === 'redo' ? self::undoes($e['details_json']) !== null : !in_array($e['source'], ['feed', 'google', 'system'], true))));
        if ($pool === []) {
            return ['skip' => true];
        }
        $id = (int) $pool[min(count($pool) - 1, (int) floor($step['pick'] * count($pool)))]['id'];
        $this->lastAsked = $this->askedOfEntry($id) + ['entry' => $id];
        $this->undo->undoById($this->userId, $id);
        return ['entry' => $id, 'asked' => $this->lastAsked];
    }

    /** What the step that made an entry asked, from the harness's own record (an undo's entry: its original's, as redo). */
    private function askedOfEntry(int $entry, int $depth = 0): array
    {
        foreach ($this->done as $i => $d) {
            if (in_array($entry, $d['entries'], true) && !in_array($d['do'], ['u-entry', 'u-bare', 'redo'], true)) {
                return ['kind' => 'undo', 'of' => $i] + $d['asked'];
            }
        }
        $of = self::undoes((string) $this->db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$entry]));
        if ($of !== null && $depth < 4) {
            return ['kind' => 'redo'] + $this->askedOfEntry($of, $depth + 1);
        }
        return ['kind' => 'undo', 'of' => null, 'gid' => null, 'fields' => [], 'days' => ['*']];
    }

    // ---- the checks -----------------------------------------------------------------------

    private function check(string $do, array $out, array $s0, array $s1): void
    {
        $asked = ($out['asked'] ?? null) ?? ['kind' => '?', 'gid' => null, 'fields' => [], 'days' => []];
        $writes = $this->writesBetween($s0, $s1);
        $entries = array_map('intval', array_column($this->db->all('SELECT id FROM mutations WHERE user_id = ? AND id > ? ORDER BY id', [$this->userId, $s0['mut']]), 'id'));
        $kind = substr($do, 0, 2);
        $failure = $kind === 'f-' ? $this->stand->knobs->fired : null;
        $isWrite = $kind === 'w-' || $kind === 'f-' || $kind === 'u-' || in_array($do, ['finish', 'putback', 'redo'], true);
        $gid = $asked['gid'] ?? null;
        $error = $out['ok'] ? null : (string) $out['error'];
        $context = ['step' => $this->step, 'do' => $do] + ($error === null ? [] : ['error' => $error, 'message' => $out['message'] ?? '']);
        // A change of this series left half done by an earlier step (checkFailure adds this step's own after).
        $onPending = $gid !== null && array_filter($this->pendings, static fn(array $p): bool => isset($p['gids'][$gid])) !== [];

        // A non-HTTP error from a step that injected none is the product failing to answer.
        if (!$out['ok'] && !empty($out['thrown']) && $failure === null) {
            $this->fail('x-honest', "step {$this->step} ($do): the product threw $error instead of answering", $context);
        }

        // x-sync-silent: a poll sends Google nothing.
        if ($kind === 'p-') {
            $this->ran('x-sync-silent', $do);
            $this->expect('x-sync-silent', $writes === [] && $s0['g'] == $s1['g'], "step {$this->step} ($do): a poll sends Google nothing", $context + ['writes' => self::brief($writes)]);
        }

        // A6: a write to a series whose change is half done is refused with the pending reason, writing nothing.
        if ($isWrite && $onPending && !in_array($do, ['finish', 'putback'], true) && $failure === null) {
            $this->ran('x-pending', 'write while half done');
            if ($out['ok']) {
                $this->fail('x-pending', "step {$this->step} ($do): a write to a series whose change is half done went through; the settled rule (A6) refuses it with the pending reason, pointing to Review", $context);
            } else {
                $this->expect('x-pending', self::same($s0['l'], $s1['l']) && $writes === [], "step {$this->step} ($do): a write refused while its series' change is half done writes nothing", $context + ['diff' => self::diff($s0['l'], $s1['l'])]);
            }
        }

        // x-local-silent: a change here alone sends Google nothing.
        if (!empty($asked['local']) && $out['ok'] && in_array($asked['kind'], ['this', 'all', 'undo', 'redo'], true)) {
            $this->ran('x-local-silent', $do);
            $this->expect('x-local-silent', $writes === [] && $s0['g'] == $s1['g'], "step {$this->step} ($do): a change of things held here alone sends Google nothing", $context + ['writes' => self::brief($writes)]);
        }

        // x-write-asked: the fields only Google holds never change through the app, and no body carries them.
        if ($isWrite) {
            $this->ran('x-write-asked', $do);
            $this->expect('x-write-asked', self::onlyKept($s0['g']['only'], $s1['g']['only']), "step {$this->step} ($do): a write here leaves every field only Google holds as it was (colorId, attendees, transparency, visibility)",
                $context + ['before' => array_slice($s0['g']['only'], 0, 4, true), 'after' => array_slice($s1['g']['only'], 0, 4, true)]);
            foreach ($writes as $q) {
                $sent = array_values(array_intersect(array_keys((array) $q['body']), self::NEVER_SENT));
                if ($sent !== []) {
                    $this->fail('x-write-asked', "step {$this->step} ($do): a write sent " . implode(', ', $sent) . ' to Google, which the app never sends', $context + ['path' => $q['path']]);
                    break;
                }
            }
            if ($out['ok'] && in_array($asked['kind'], ['all', 'this'], true) && $asked['fields'] !== [] && array_intersect(array_keys($asked['fields']), ['time', 'length', 'count']) === [] && !isset($this->dirty[(string) $gid])) {
                $this->checkOnlyAsked($asked, $s0, $s1, $context);
            }
        }

        // x-conflict: a Google write or undo sent while the series changed at Google unpolled is refused whole.
        $undoOfDelete = in_array($asked['kind'], ['undo', 'redo'], true) && ($this->done[$asked['of'] ?? 0]['asked']['kind'] ?? '') === 'del1';
        if ($isWrite && $gid !== null && isset($this->dirty[$gid]) && !$onPending && empty($asked['local']) && $failure === null && !$undoOfDelete && !in_array($asked['kind'], ['create', 'finish', 'putback'], true)) {
            $this->ran('x-conflict', $do);
            $this->expect('x-conflict', !$out['ok'] && $error === 'google_conflict', "step {$this->step} ($do): a change to a series changed at Google since the last poll is refused with google_conflict", $context);
            if (!$out['ok']) {
                $this->expect('x-conflict', self::same($s0['l'], $s1['l']) && $writes === [], "step {$this->step} ($do): the refusal writes nothing here or at Google", $context + ['diff' => self::diff($s0['l'], $s1['l']), 'writes' => self::brief($writes)]);
            }
        }

        // x-pending: what a failure leaves.
        if ($failure !== null) {
            $this->checkFailure($failure, $out, $s0, $s1, $asked, $context);
        } elseif ($kind === 'f-') {
            $this->ran('f-unfired', $do); // fewer writes than k: an ordinary write
        }

        $this->recovered = in_array($do, ['finish', 'putback'], true) && $out['ok'] ? $this->pending : null;
        if ($do === 'finish' || $do === 'putback') {
            $this->checkRecovery($do, $out, $s0, $s1, $context, $writes);
        }
        if (in_array($do, ['u-entry', 'u-bare', 'redo'], true)) {
            $this->checkUndo($do, $out, $s0, $s1, $asked, $context, $writes);
        }

        // x-honest: a refusal with no pending change leaves both sides as they were; a write that changed something leaves an entry.
        if ($isWrite && !$out['ok'] && $failure === null && !isset($out['extra']['pending']) && !in_array($do, ['finish', 'putback'], true)) {
            $this->ran('x-honest', 'refusal');
            $this->expect('x-honest', self::same($s0['l'], $s1['l']) && $s0['g']['digest'] == $s1['g']['digest'], "step {$this->step} ($do): a refusal ($error) changes nothing here or at Google", $context + ['diff' => self::diff($s0['l'], $s1['l'])]);
        }
        if ($out['ok'] && $kind === 'w-' && $s0['l'] != $s1['l']) {
            $this->ran('x-honest', 'entry');
            $this->expect('x-honest', $entries !== [], "step {$this->step} ($do): a write that changed something leaves an Activity entry", $context);
        }

        $this->checkAlone($do, $out, $s0, $s1, $asked, $entries, $context);
        $this->checkStructure($do, $s1, $context);

        // The harness's own bookkeeping: series changed at Google unpolled, entries, touches.
        if ($kind === 'p-' && $out['ok']) {
            $this->dirty = [];
            foreach ($this->touches as $i => $t) {
                $this->touches[$i]['polled'] = true;
            }
        }
        // A write that went through left the series' etag here current, so the next one is no conflict; so did a refusal
        // after which the rows hold what Google holds (the product read it back).
        if ($gid !== null && $isWrite && empty($asked['local']) && in_array($asked['kind'] ?? '', ['all', 'following', 'delfollow', 'answer'], true) && ($out['ok'] || ($error === 'google_conflict' && ($this->ldigest($s1['rows'])[$gid] ?? 1) == ($s1['g']['digest'][$gid] ?? 2)))) {
            unset($this->dirty[$gid]);
        }
        $this->done[$this->step] = ['do' => $do, 'asked' => $asked, 'entries' => $entries, 'ok' => $out['ok']];
        if ($out['ok'] && ($kind === 'w-' || $kind === 'f-')) {
            foreach (array_keys($asked['fields']) as $f) {
                $this->touches[] = ['step' => $this->step, 'gid' => $gid, 'remote' => false, 'field' => $f, 'key' => $asked['key'] ?? null, 'polled' => true];
            }
        }

        // x-agree: after a write that went through and after a poll, the rows are what Google holds; a poll then changes nothing.
        if (($kind === 'p-' || ($isWrite && $out['ok'])) && $this->dirty === [] && $s1['half'] === []) {
            $this->checkAgree($do, $s1, $context);
        }
    }

    private function checkOnlyAsked(array $asked, array $s0, array $s1, array $context): void
    {
        $gid = (string) $asked['gid'];
        $a = $s0['g']['digest'][$gid] ?? null;
        $b = $s1['g']['digest'][$gid] ?? null;
        if ($a === null || $b === null) {
            return;
        }
        $this->ran('x-write-asked', 'only asked');
        $changed = array_intersect_key(['title' => 1, 'description' => 1, 'location' => 1, 'url' => 1], $asked['fields']);
        $series = ['title', 'description', 'location', 'url', 'zone', 'rule', 'start', 'end', 'allDay', 'skips'];
        foreach ($series as $f) {
            if (($asked['kind'] === 'this' || !isset($changed[$f])) && ($a[$f] ?? null) !== ($b[$f] ?? null)) {
                $this->fail('x-write-asked', "step {$this->step}: a " . ($asked['kind'] === 'this' ? '"this event"' : 'whole-series') . ' change of ' . implode(', ', array_keys($asked['fields'])) . " changed the series' $f at Google",
                    $context + ['before' => $a[$f] ?? null, 'after' => $b[$f] ?? null]);
            }
        }
        if ($asked['kind'] === 'this') {
            $others = static fn(array $recs): array => array_diff_key($recs, [(string) $asked['key'] => 1]);
            if ($others($a['records']) != $others($b['records'])) {
                $this->fail('x-write-asked', "step {$this->step}: a \"this event\" edit changed another day's record at Google", $context + ['before' => $others($a['records']), 'after' => $others($b['records'])]);
            }
        }
    }

    private function checkFailure(string $failure, array $out, array $s0, array $s1, array $asked, array $context): void
    {
        $taken = $this->stand->knobs->taken;
        $context += ['failure' => $failure, 'taken' => $taken];
        if ($failure === 'before' && $taken === 0) {
            $this->ran('x-pending', 'nothing sent');
            $this->expect('x-pending', !$out['ok'], "step {$this->step}: a write whose first request never left fails", $context);
            $this->expect('x-pending', $s1['half'] === [] && self::same($s0['l'], $s1['l']) && $s0['g']['digest'] == $s1['g']['digest'],
                "step {$this->step}: a failure before anything was sent leaves no change half done, nothing here and nothing at Google", $context + ['half' => $s1['half'], 'diff' => self::diff($s0['l'], $s1['l'])]);
            return;
        }
        $this->ran('x-pending', 'half done');
        $this->expect('x-pending', !$out['ok'], "step {$this->step}: a write stopped after Google took part of it fails", $context);
        $old = array_column($s0['half'], 'id');
        $new = array_values(array_filter($s1['half'], static fn(array $h): bool => !in_array($h['id'], $old, true)));
        $this->expect('x-pending', count($new) === 1, "step {$this->step}: a write stopped after Google took part of it is listed in Review as half done, once", $context + ['half' => $s1['half']]);
        if (count($new) !== 1) {
            return;
        }
        $this->expect('x-pending', (int) $new[0]['stepsDone'] >= $taken, "step {$this->step}: its stepsDone covers every write the stand-in took, the one whose answer was lost included", $context + ['stepsDone' => $new[0]['stepsDone']]);
        if ($failure !== 'fatal') {
            $this->expect('x-pending', (int) ($out['extra']['pending'] ?? 0) === (int) $new[0]['id'], "step {$this->step}: the error names the half-done change (pending)", $context + ['extra' => $out['extra']]);
        }
        $m = ['id' => (int) $new[0]['id'], 'step' => $this->step, 'asked' => $asked, 's0' => $s0, 'gids' => [(string) $asked['gid'] => true], 'since' => []];
        // A split's insert is a series of the change too.
        foreach ($this->stand->fake->events as $id => $e) {
            if (!empty($e['recurrence']) && !str_contains((string) $id, '_') && !array_key_exists((string) $id, $s0['g']['digest'])) {
                $m['gids'][(string) $id] = true;
                $this->gids[(string) $id] = true;
            }
        }
        $this->pendings[$m['id']] = $m;
    }

    private function checkRecovery(string $do, array $out, array $s0, array $s1, array $context, array $writes): void
    {
        $p = $this->pending;
        $answer = $out['answer'] ?? [];
        $error = $out['ok'] ? null : (string) $out['error'];
        if ($p === null) {
            $this->na['a Review half-done item the harness did not see made'] = ($this->na['a Review half-done item the harness did not see made'] ?? 0) + 1;
            return;
        }
        $changedSince = array_keys(array_intersect_key($p['since'], $p['gids']));
        if ($do === 'putback') {
            $this->ran('x-putback', $changedSince === [] ? 'unchanged' : 'changed since');
            if ($changedSince !== []) {
                $this->expect('x-putback', $error === 'google_changed_since', "step {$this->step}: Put back after a change at Google since is refused with google_changed_since", $context + ['changedSince' => $changedSince]);
                $this->expect('x-putback', $writes === [] && self::same($s0['l'], $s1['l']) && in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: a refused Put back sends nothing, changes nothing here and leaves the change in Review",
                    $context + ['writes' => self::brief($writes), 'diff' => self::diff($s0['l'], $s1['l'])]);
                if ($error === 'google_changed_since') {
                    $left = (array) ($out['extra']['left'] ?? []);
                    $this->expect('x-putback', count($left) === count($changedSince), "step {$this->step}: the refusal names exactly the series changed at Google since", $context + ['left' => $left, 'changedSince' => $changedSince]);
                }
                if ($out['ok']) {
                    $this->settled($p);
                }
                return;
            }
            $this->expect('x-putback', $out['ok'] && ($answer['left'] ?? []) === [], "step {$this->step}: Put back with nothing changed at Google since goes through, leaving nothing", $context + ['answer' => $answer]);
            if ($out['ok']) {
                foreach (array_keys($p['gids']) as $g) {
                    $was = $p['s0']['g']['digest'][$g] ?? null;
                    $now = $s1['g']['digest'][$g] ?? null;
                    $this->expect('x-putback', $was == $now, "step {$this->step}: after Put back Google holds the series as it was before the change (an inserted one cancelled)", $context + ['gid' => $g, 'was' => $was, 'now' => $now]);
                }
                $was = self::ofSeries($p['s0']['l'], $p['gids'], $p['s0']['rows']);
                $now = self::ofSeries($s1['l'], $p['gids'], $s1['rows']);
                $this->expect('x-putback', self::same($was, $now), "step {$this->step}: after Put back the rows here are as before the change", $context + ['diff' => self::diff($was, $now)]);
                $this->expect('x-putback', !in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: Put back clears the change from Review", $context);
                $this->settled($p);
            }
            return;
        }
        $this->ran('x-finish', $changedSince === [] ? 'unchanged' : 'changed since');
        if (!$out['ok']) {
            $this->expect('x-finish', $error === 'google_finish_local' && in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: Finish fails only with google_finish_local, the change left in Review", $context);
            return;
        }
        $this->expect('x-finish', !in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: Finish clears the change from Review", $context);
        $differences = (array) ($answer['differences'] ?? []);
        $asked = $p['asked'];
        $gid = (string) $asked['gid'];
        $g = $s1['g']['digest'][$gid] ?? null;
        $master = null;
        foreach (self::masters($s1['rows']) as $r) {
            if ((string) $r['google_event_id'] === $gid) {
                $master = $r;
            }
        }
        if ($asked['kind'] === 'all' && $g !== null && $master !== null) {
            foreach (['title', 'description', 'location', 'url'] as $f) {
                if (isset($asked['fields'][$f])) {
                    $this->expect('x-finish', (($g[$f] ?? null) === $asked['fields'][$f] && (string) $master[$f] === $asked['fields'][$f]) || $differences !== [],
                        "step {$this->step}: after Finish the series holds the change's $f at Google and here, or the answer names why not", $context + ['want' => $asked['fields'][$f], 'google' => $g[$f] ?? null, 'here' => $master[$f]]);
                }
            }
            if (isset($asked['fields']['tags'])) {
                $want = $asked['fields']['tags'];
                sort($want);
                $this->expect('x-finish', $master['tags'] === $want, "step {$this->step}: after Finish the series has the change's tags (its part here, review R3)", $context + ['want' => $want, 'here' => $master['tags']]);
            }
        }
        // A4: a change at Google since, to a day or field the change didn't touch, is kept.
        foreach ($this->touches as $t) {
            if (empty($t['remote']) || $t['step'] < $p['step'] || $t['gid'] !== $gid || $g === null || $t['key'] === null || !in_array($t['field'], ['title', 'description'], true) || isset($asked['fields'][$t['field']])) {
                continue;
            }
            if (array_intersect(array_keys($asked['fields']), ['time', 'length', 'count']) !== []) {
                continue; // a change of the series' time drops every record at Google: Finish can't keep what Google dropped
            }
            $rec = $g['records'][$t['key']] ?? null;
            $this->expect('x-finish', $rec !== null && str_contains((string) $rec[$t['field'] === 'title' ? 0 : 5], 'remote'),
                "step {$this->step}: Finish keeps a change made at Google since to a day the change didn't touch (A4)", $context + ['key' => $t['key'], 'record' => $rec]);
        }
        $this->settled($p);
    }

    private function checkUndo(string $do, array $out, array $s0, array $s1, array $asked, array $context, array $writes): void
    {
        $entry = $out['entry'] ?? ($asked['entry'] ?? null);
        $error = $out['ok'] ? null : (string) $out['error'];
        if ($entry === null) {
            return;
        }
        $context['entry'] = $entry;
        $undone = (int) $this->db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]) === 1;
        if ($do === 'u-bare') {
            $this->ran('x-undo-bare', 'newest own');
            $this->expect('x-undo-bare', $out['ok'] ? $undone : self::same($s0['l'], $s1['l']), "step {$this->step}: the bare Undo takes the newest of the account's own changes not undone, passing over an undo's own entry", $context + ['undone' => $undone]);
        }
        $of = $asked['of'] ?? null;
        $orig = $of === null ? null : ($this->done[$of] ?? null);
        $fieldEdit = $orig !== null && in_array($orig['asked']['kind'], ['all', 'this'], true) && $orig['asked']['fields'] !== [] && array_diff(array_keys($orig['asked']['fields']), ['title', 'description', 'location', 'url']) === [];
        if (!$out['ok']) {
            $this->ran('x-undo-fields', 'refused');
            $this->expect('x-undo-fields', self::same($s0['l'], $s1['l']) && $writes === [], "step {$this->step} ($do): a refused undo ($error) writes nothing here or at Google", $context + ['diff' => self::diff($s0['l'], $s1['l'])]);
            if ($error === 'stale_undo' && $fieldEdit) {
                $any = array_filter($this->touches, static fn(array $t): bool => $t['step'] > $of && $t['gid'] === $orig['asked']['gid']);
                $this->expect('x-undo-fields', $any !== [], "step {$this->step} ($do): an undo of step $of was refused as stale though nothing changed that series since", $context);
            }
            return;
        }
        if (!$fieldEdit || ($asked['kind'] ?? '') === 'redo') {
            return;
        }
        // Staleness (decisions.md; A3): a later step not undone that changed the same field, a poll bringing a remote change of it included.
        $later = array_filter($this->touches, fn(array $t): bool => $t['step'] > $of && $t['gid'] === $orig['asked']['gid'] && isset($orig['asked']['fields'][$t['field']])
            && (empty($t['remote']) ? !$this->stepUndone((int) $t['step']) : $t['polled']) && ($orig['asked']['kind'] === 'all' || $t['key'] === null || $t['key'] === ($orig['asked']['key'] ?? null)));
        if ($later !== []) {
            $this->ran('x-undo-fields', 'stale');
            $this->fail('x-undo-fields', "step {$this->step} ($do): an undo of step $of went through though a later change (step " . implode(', ', array_unique(array_column($later, 'step'))) . ') changed the same field: stale_undo by the rule (A3)', $context);
            return;
        }
        $this->ran('x-undo-fields', 'fields back');
        $gid = (string) $orig['asked']['gid'];
        $was = $this->done[$of]['before'] ?? null;
        foreach ($orig['asked']['fields'] as $f => $v) {
            $now = null;
            foreach ($s1['rows'] as $r) {
                if ($orig['asked']['kind'] === 'all' && (string) $r['google_event_id'] === $gid && $r['recurrence_parent_id'] === null) {
                    $now = (string) $r[$f];
                }
                if ($orig['asked']['kind'] === 'this' && $r['recurrence_instance_utc'] === ($orig['asked']['key'] ?? null)) {
                    $now = (string) $r[$f];
                }
            }
            $this->expect('x-undo-fields', $now !== $v, "step {$this->step} ($do): undo takes back the $f step $of set", $context + ['field' => $f, 'sent' => $v, 'now' => $now]);
        }
    }

    private function settled(?array $p): void
    {
        if ($p !== null) {
            unset($this->pendings[$p['id']]);
        }
        $this->pending = null;
    }

    private function stepUndone(int $step): bool
    {
        foreach ($this->done[$step]['entries'] ?? [] as $e) {
            if ((int) $this->db->scalar('SELECT undone FROM mutations WHERE id = ?', [$e]) === 1) {
                return true;
            }
        }
        return false;
    }

    /** x-alone and x-dropped. */
    private function checkAlone(string $do, array $out, array $s0, array $s1, array $asked, array $entries, array $context): void
    {
        if (str_starts_with($do, 'f-') && array_filter($this->pendings, fn(array $p): bool => $p['step'] === $this->step) !== []) {
            // A change left half done holds what it took in its pending row until Finish or Put back (google-calendar.md:
            // "either way the edited days keep their tags, people, reminders and trip links"): judged after either.
            $this->ran('x-alone', 'deferred to recovery');
            return;
        }
        // Finish and Put back are judged against the rows before the change they settle.
        $base = in_array($do, ['finish', 'putback'], true) && $this->recovered !== null ? $this->recovered['s0']['rows'] : $s0['rows'];
        $a0 = self::alone($base);
        $s0 = ['rows' => $base] + $s0;
        $a1 = self::alone($s1['rows']);
        $this->ran('x-alone', $do);
        // What the step may remove on purpose: its own request's days and kinds, an undo's step's, a recovery's change's.
        // Put back reverts the change, so it may remove nothing the rows held before it; Finish completes it, so it may
        // remove what the change's own request reaches.
        $allow = match ($do) {
            'putback' => null,
            'finish' => $this->recovered['asked'] ?? null,
            default => $asked,
        };
        $named = $this->namedText($entries, $out);
        foreach ($a0 as $day => $items) {
            foreach ($items as $item => $rowId) {
                if (isset($a1[$day][$item]) || ($day === '*' && isset($a1['*'][$item])) || self::allowed($allow, $day, $item)) {
                    continue;
                }
                $row = $s0['rows'][$rowId] ?? null;
                $master = $row !== null && $row['recurrence_parent_id'] !== null ? ($s1['rows'][(int) $row['recurrence_parent_id']] ?? null) : null;
                $stillHasDay = false;
                if ($master !== null) {
                    $days = self::days($master, 200);
                    $stillHasDay = isset($days[$day]) && !in_array($days[$day], Recurrence::decodeExdates($master['exdates_json'] ?? null), true);
                }
                $detail = $context + ['day' => $day, 'item' => $item, 'row' => $rowId];
                if (str_starts_with($do, 'p-') && $stillHasDay) {
                    $this->fail('x-dropped', "step {$this->step} ($do): a day the series still has lost what it held here alone ($item on $day)", $detail);
                    continue;
                }
                if (!self::isNamed($named, $rowId, $day)) {
                    $byDate = $day !== '*' && str_contains($named, $day);
                    $this->fail('x-alone', "step {$this->step} ($do): $item on $day, held here alone, went " . ($byDate ? 'named by its date but not its row id (A5 names both)' : 'without an entry naming it'),
                        $detail + ['named' => mb_substr(trim($named), 0, 400)]);
                }
            }
        }
    }

    /** Whether a step's own request reaches this day and item (interleaving-invariants.md, x-alone). */
    private static function allowed(?array $asked, string $day, string $item): bool
    {
        if ($asked === null) {
            return false;
        }
        $k = $asked['kind'] ?? '';
        $kindOf = explode(':', $item, 2)[0];
        $field = ['tag' => 'tags', 'person' => 'people', 'reminders' => 'reminders', 'icon' => 'icon', 'coords' => 'coords', 'attendance' => 'attendance'][$kindOf] ?? null;
        foreach ((array) ($asked['days'] ?? []) as $d) {
            if (!($d === '*' || $d === $day || (str_starts_with($d, '>=') && ($day === '*' || $day >= substr($d, 2))))) {
                continue;
            }
            if (in_array($k, ['del1', 'delfollow', 'undo', 'redo', 'finish', 'putback', 'create'], true)) {
                return true;
            }
            if ($field !== null && isset($asked['fields'][$field])) {
                return true;
            }
            if ($kindOf === 'coords' && isset($asked['fields']['location'])) {
                return true; // A1: coordinates go with the place text
            }
        }
        return false;
    }

    private function namedText(array $entries, array $out): string
    {
        $text = '';
        if ($entries !== []) {
            [$in, $params] = Db::in($entries);
            foreach ($this->db->all("SELECT summary, details_json FROM mutations WHERE id IN $in", $params) as $e) {
                $text .= "\n" . $e['summary'] . "\n" . $e['details_json'];
            }
        }
        foreach ((array) (($out['answer'] ?? [])['differences'] ?? []) as $d) {
            $text .= "\n" . (is_string($d) ? $d : json_encode($d));
        }
        return $text;
    }

    /** A5: named is the row's id and its shown date in an entry's details, words in its summary, or a difference naming the day. */
    private static function isNamed(string $text, int $rowId, string $day): bool
    {
        if ($text === '' || $day === '*') {
            return $text !== '' && preg_match('/(?<![0-9])' . $rowId . '(?![0-9])/', $text) === 1;
        }
        $t = new \DateTimeImmutable($day);
        $dateNamed = str_contains($text, $day) || str_contains($text, $t->format('j M')) || str_contains($text, $t->format('M j'));
        return $dateNamed && preg_match('/(?<![0-9])' . $rowId . '(?![0-9])/', $text) === 1;
    }

    /** x-structure, x-own and x-follow, on every live series not changed at Google unpolled. */
    private function checkStructure(string $do, array $s1, array $context): void
    {
        if ($s1['half'] !== []) {
            return; // what Finish or Put back will settle
        }
        $rows = $s1['rows'];
        $this->ran('x-structure', $do);
        $local = ['tags', 'people', 'reminders', 'attendance', 'icon'];
        foreach ($rows as $id => $r) {
            if ($r['recurrence_parent_id'] === null) {
                continue;
            }
            $master = $rows[(int) $r['recurrence_parent_id']] ?? null;
            if ($master === null) {
                $this->fail('x-structure', "step {$this->step} ($do): an edited day's row outlives its series", $context + ['row' => $id]);
                continue;
            }
            if (isset($this->dirty[(string) $master['google_event_id']])) {
                continue; // what the next poll will settle
            }
            $onlyLocal = array_diff($r['own'], $local) === [];
            $key = (string) $r['recurrence_instance_utc'];
            if (!Truth::isOccurrence($master, $key)) {
                $this->fail($onlyLocal ? 'x-follow' : 'x-structure', "step {$this->step} ($do): an edited day's key is no occurrence of its series as stored" . ($onlyLocal ? ' (a row of things held here alone)' : ''),
                    $context + ['row' => $id, 'key' => $key, 'own' => $r['own'], 'rrule' => $master['rrule'], 'start' => $master['start_utc']]);
                continue;
            }
            $this->ran('x-own', $do);
            foreach (['title', 'description', 'location', 'url', 'status'] as $f) {
                $ownName = $f === 'url' ? 'url' : $f;
                if (!in_array($ownName, $r['own'], true) && (string) $r[$f] !== (string) $master[$f]) {
                    $this->fail($onlyLocal ? 'x-follow' : 'x-own', "step {$this->step} ($do): an edited day that doesn't own its $f has another than its series'", $context + ['row' => $id, 'own' => $r['own'], 'day' => (string) $r[$f], 'series' => (string) $master[$f]]);
                }
            }
            if (!in_array('time', $r['own'], true) && !in_array('date', $r['own'], true) && (int) $r['all_day'] === (int) $master['all_day'] && $r['start_utc'] !== $key) {
                $this->fail($onlyLocal ? 'x-follow' : 'x-own', "step {$this->step} ($do): an edited day that owns neither its date nor its time doesn't start at its key", $context + ['row' => $id, 'start' => $r['start_utc'], 'key' => $key]);
            }
        }
        foreach (self::masters($rows) as $m) {
            if (isset($this->dirty[(string) $m['google_event_id']])) {
                continue;
            }
            foreach (Recurrence::decodeExdates($m['exdates_json'] ?? null) as $skip) {
                if (!Truth::isOccurrence($m, $skip)) {
                    $this->fail('x-structure', "step {$this->step} ($do): a skipped day is no occurrence of its series", $context + ['skip' => $skip, 'rrule' => $m['rrule']]);
                }
            }
        }
    }

    /** x-agree: the rows equal what Google holds, and a delta poll then a whole read change nothing. */
    private function checkAgree(string $do, array $s1, array $context): void
    {
        $this->ran('x-agree', $do);
        $here = $this->ldigest($s1['rows']);
        foreach ($here as $gid => $l) {
            $g = $s1['g']['digest'][$gid] ?? null;
            if ($g === null || $g != $l) {
                $this->fail('x-agree', "step {$this->step} ($do): the rows here and Google disagree on a series", $context + ['gid' => $gid, 'google' => $g, 'here' => $l]);
            }
        }
        foreach ($s1['g']['digest'] as $gid => $g) {
            if ($g !== null && !isset($g['single']) && !isset($here[$gid])) {
                $this->fail('x-agree', "step {$this->step} ($do): Google holds a series of the case that has no row here", $context + ['gid' => $gid]);
            }
        }
        $before = $s1['l'];
        $this->stand->poll();
        $this->db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$this->stand->calendarId]);
        $this->stand->poll();
        $after = $this->lstate($this->rows());
        $this->expect('x-agree', self::same($before, $after), "step {$this->step} ($do): a delta poll and then a whole read change no row", $context + ['diff' => self::diff($before, $after)]);
    }

    // ---- helpers ----------------------------------------------------------------------

    /** The rows of the given series (by Google id), by row id. */
    private static function ofSeries(array $l, array $gids, array $rows): array
    {
        $masters = [];
        foreach ($rows as $id => $r) {
            if (isset($gids[(string) $r['google_event_id']]) && $r['recurrence_parent_id'] === null) {
                $masters[$id] = true;
            }
        }
        return array_filter($l, static fn(array $r, int $id): bool => isset($masters[$id]) || isset($masters[(int) $r['parent']]), ARRAY_FILTER_USE_BOTH);
    }

    private static function onlyKept(array $a, array $b): bool
    {
        foreach ($a as $id => $o) {
            if (isset($b[$id]) && $b[$id] != $o) {
                return false;
            }
        }
        foreach ($b as $id => $o) {
            // A record Google made for a day (a write touched it) starts as its series' instance, with what the series
            // holds of these fields; anything else is one the app added.
            if (!isset($a[$id]) && $o != ($a[explode('_', (string) $id, 2)[0]] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private static function same(array $a, array $b): bool
    {
        return $a == $b;
    }

    private static function diff(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $id) {
            if (($a[$id] ?? null) == ($b[$id] ?? null)) {
                continue;
            }
            if (!isset($a[$id]) || !isset($b[$id])) {
                $out[$id] = isset($a[$id]) ? 'removed' : 'added';
            } else {
                $d = [];
                foreach (array_unique(array_merge(array_keys($a[$id]), array_keys($b[$id]))) as $k) {
                    if (($a[$id][$k] ?? null) != ($b[$id][$k] ?? null)) {
                        $d[$k] = [$a[$id][$k] ?? null, $b[$id][$k] ?? null];
                    }
                }
                $out[$id] = $d;
            }
            if (count($out) >= 6) {
                break;
            }
        }
        return $out;
    }

    private static function brief(array $writes): array
    {
        return array_map(static fn(array $q): string => $q['method'] . ' ' . rawurldecode(basename((string) $q['path'])) . ' ' . mb_substr((string) json_encode($q['body']), 0, 120), array_slice($writes, 0, 4));
    }

    private function ran(string $check, string $role): void
    {
        $this->runs[$check][$role] = ($this->runs[$check][$role] ?? 0) + 1;
    }

    private function expect(string $check, bool $ok, string $what, array $detail = []): void
    {
        if (!$ok) {
            $this->fail($check, $what, $detail);
        }
    }

    private function fail(string $check, string $what, array $detail = []): void
    {
        $this->records[] = ['invariant' => $check, 'what' => $what, 'detail' => $detail];
    }
}
