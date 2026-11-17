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
        'r-rename' => 3, 'r-time' => 3, 'r-zone' => 2, 'r-length' => 2, 'r-rule' => 2, 'r-bound' => 3, 'r-skip+' => 2, 'r-skip-' => 1, 'r-exc+' => 3, 'r-exc-' => 1, 'r-gonly' => 3, 'r-free' => 2, 'r-delete' => 1, 'r-etag' => 2,
        'p-delta' => 8, 'p-whole' => 3, 'p-410' => 2, 'p-empty' => 2, 'p-overlap' => 2, 'p-410-pending' => 2, 'p-budget' => 1, 'w-race' => 3,
        'f-lost' => 5, 'f-before' => 3, 'f-fatal' => 3,
        'finish' => 5, 'putback' => 5,
        'u-entry' => 4, 'u-bare' => 5, 'redo' => 2, 'u-redo' => 3,
    ];
    /** Drawn as more than one step: 'u-redo' is an undo of a chosen entry, then a redo of the newest undo entry (the one it just made). */
    public const COMPOSITE = ['u-redo'];
    /** The undo and redo kinds, tagged in coverage by what the entry's target was when they ran. */
    public const UNDO_KINDS = ['u-entry', 'u-bare', 'redo'];
    /** The tags: the entry's day had a row here, had none, or the entry was the series'. */
    public const UNDO_TAGS = ['row', 'no row', 'series'];
    /** The setup steps a case starts with (w-this-l twice: its weight). */
    public const SETUP = ['w-this-g', 'w-this-l', 'w-this-l', 'w-this-m', 'w-del1'];
    /** Fields only Google holds (beyond the digest; the A2 scope): the app never sends them, so its writes never change them. */
    /** Every check the oracle makes (docs/design/interleaving-invariants.md), for the oracle probes' account of which are validated. */
    public const CHECKS = ['x-day', 'x-alone', 'x-dropped', 'x-sync-silent', 'x-local-silent', 'x-write-asked', 'x-conditional', 'x-agree', 'x-follow', 'x-structure', 'x-identity', 'x-own', 'x-conflict', 'x-pending', 'x-finish', 'x-putback', 'x-undo-fields', 'x-undo-bare', 'x-honest', 'x-empty', 'x-overlap', 'x-budget', 'x-readback', 'x-choice'];
    /**
     * The fields the conflict choice compares (google-conflict-choice.md, the one page, (a)), by the names its answers use:
     * the six, and since 1.5.8 `showAs` (#303), compared like status, both free or both not.
     */
    public const CHOICE_FIELDS = ['title', 'description', 'location', 'url', 'status', 'showAs', 'time'];
    /** What a change here sets each field of an event in no series to (withSingle), so a change at Google can set the same value. */
    private const SINGLE_VALUES = ['title' => 'Sample single title', 'description' => 'Sample single notes', 'location' => 'Sample single room', 'url' => 'https://example.test/single-mine'];
    // Since 1.5.8 `transparency` is no longer among them: it is stored here as show_as, a field Google holds (decisions.md,
    // "Whether an event takes up time is one stored fact per event": "It is a field Google holds ... the body key
    // `transparency`").
    public const GOOGLE_ONLY = ['colorId', 'attendees', 'visibility', 'reminders', 'guestsCanModify', 'guestsCanInviteOthers', 'extendedProperties'];
    /**
     * The keys a request body may carry: the fields Google holds of an edited day (OwnFields' list less HELD_HERE: date,
     * time, length and all-day as start and end, title as summary, location, description, url as source, status, and
     * since 1.5.8 showAs as transparency) and the series' rule (recurrence), plus a resource's identity (google-calendar.md,
     * What Writes Send; decisions.md, the show_as entry).
     */
    public const WRITER_KEYS = ['summary', 'description', 'location', 'source', 'start', 'end', 'status', 'transparency', 'recurrence', 'id', 'iCalUID', 'recurringEventId', 'originalStartTime'];
    /** What a write never sends (google-calendar.md, What Writes Send: "Not sent: attendees, reminders and color"). */
    public const NEVER_SENT = ['attendees', 'reminders', 'colorId'];
    /** The fields held here alone a step can send. */
    private const LOCAL = ['tags', 'people', 'reminders', 'icon', 'coords', 'attendance'];
    /**
     * A day's content field and the own-list names that make it the day's own; a field none of them names follows its
     * series (series-model.md, Own Fields). Tags and people likewise: a day's row stores the series' copy as links, but they
     * are its own only when its own list says so, so an undo leaves a day that doesn't own them with the series' set as it
     * stands and puts an owned set back exactly.
     */
    private const OWN_OF = ['title' => ['title'], 'description' => ['description'], 'location' => ['location'], 'url' => ['url'], 'status' => ['status'], 'showAs' => ['showAs'],
        'tags' => ['tags'], 'people' => ['people'], 'reminders' => ['reminders'], 'icon' => ['icon'], 'attendance' => ['attendance'],
        'lat' => ['location', 'coords', 'lat'], 'lng' => ['location', 'coords', 'lat']];
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
    /** @var array<string,true> series whose etag alone changed at Google since the last poll (r-etag) */
    private array $etagOnly = [];
    /** The change a w-race step (or an undo step given "race") made at Google between the master write and its read-back: [gid, key or null for the master, title], or null. */
    private ?array $raced = null;
    /** The instance id a w-race step renamed a day of, for an undo step given "race": "day" (fixed cases) to rename it again. */
    private ?array $lastRace = null;
    /** The sentence Finish's answer opens its list of replaced days with, as the docs give it (google-calendar.md, "What Writes Send"). */
    private const REPLACED_LEAD = 'This change had stopped part way, and Google Calendar no longer held the event as it was left.';
    /** The race run during the step being checked, as $raced (its title there is x-readback's to judge), or null. */
    private ?array $racedNow = null;
    /** What a p-overlap step wrote here while the poll fetched: [row key, tag], or null. */
    private ?array $overlap = null;
    /** What a p-budget step held after its stopped read: the comparable state then. */
    private ?array $budgetMid = null;
    /** @var array<string,array{date:string,fields:array,kind:string}> what a step marked acted on (day shapes), by its mark */
    private array $marks = [];
    /** @var array<string,int> the case's events in no series (w-create), Google id => row id */
    private array $singles = [];
    /** The Google id of the event in no series a single shape acts on (the w-create step it marked), or null. */
    private ?string $single = null;
    /** @var array<string,array> the record the API last served for each event in no series (what the person was shown), by Google id */
    private array $served = [];
    /** The last w-single request and how it was answered (an Overwrite is the same request again), or null. */
    private ?array $lastSingle = null;
    /** The w-single request of the step being checked, for x-choice, or null. */
    private ?array $singleSent = null;
    /** What a w-create step made, for the new-event marking check: [row id, Google id, all-day], or null. */
    private ?array $made = null;
    /** The change to one day of a repeating event the step being checked sent (action, body, gid, master id, key, by its row), for x-choice's day reading, or null. */
    private ?array $daySent = null;
    /** Whether the case's account has the conflict choice on: the run's setting, or the fixed case's own `conflictChoice`. */
    private bool $choiceOn = true;

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
            $steps[] = self::drawStep($r, $r->pick(self::SETUP));
        }
        foreach (range(1, $r->int(3, 8)) as $_) {
            $do = $r->weighted(self::WEIGHTS);
            if ($do === 'u-redo') {
                // Every redo it makes has an entry to redo: the undo just before it records one on a Google calendar.
                $steps[] = self::drawStep($r, 'u-entry');
                $steps[] = ['do' => 'redo', 'pick' => 0.0];
                continue;
            }
            $steps[] = self::drawStep($r, $do);
        }
        $case = ['seed' => $seed, 'n' => $n, 'series' => $series, 'steps' => $steps];
        // A share of the cases, chosen from the seed and case number alone so every other case draws as before, takes a
        // change between timed and all-day (README, Interleaving: Flips).
        if (self::$flips > 0 && crc32("flip:$seed:$n") % 10000 < (int) round(self::$flips * 10000)) {
            $case = self::withFlip($case, new Rng(crc32("flip-draw:$seed:$n") % 1_000_003));
        } elseif (self::$dayShapes > 0 && crc32("dayshape:$seed:$n") % 10000 < (int) round(self::$dayShapes * 10000)) {
            // Another share, from the cases the flip share left, by seed and case number alone: a day's change undone or
            // redone across a change of the series' form or zone, the staleness of one, or a flip stopped before its
            // master write lands with a change at Google in between (README, Interleaving: Day shapes).
            $case = self::withDayShape($case, new Rng(crc32("dayshape-draw:$seed:$n") % 1_000_003));
        } elseif (self::$singleShapes > 0 && crc32("single:$seed:$n") % 10000 < (int) round(self::$singleShapes * 10000)) {
            // Another share, from the cases the two above left, by seed and case number alone: an event in no series
            // changed at Google and then here before a poll (README, Interleaving: The Conflict Choice).
            $case = self::withSingle($case, new Rng(crc32("single-draw:$seed:$n") % 1_000_003));
        }
        return $case;
    }

    /** The share of drawn cases given an event in no series changed at Google and here (withSingle); interleave-cli's --singles sets it, 0 turns it off. */
    public static float $singleShapes = 0.25;

    /**
     * A drawn case given an event in no series (google-conflict-choice.md, slice 1: "a change or delete of an event in no
     * series on a Google calendar"), changed at Google by another client and then here before a poll, in place of as many
     * of its own drawn steps (the last ones), so a case runs as many steps as before. The block, put among the case's
     * steps after its setup: the event made (w-create), most often a change at Google of one or two of the six compared
     * fields (r-single), in some cases with the change of the field this request sets to the very value it sets, sometimes
     * a delta poll between (the person still sending what they were shown before it, the page's case 5, or the record
     * served after it), then the change here (w-single: an edit of one or two fields, some with tags in the same request;
     * a delete; Planned or Maybe), then Overwrite (the same request again, as the 409 says to send it), which runs only
     * after a google_changed. Which fields overlap is drawn, never listed: x-choice works out each field's outcome from
     * the three states.
     */
    private static function withSingle(array $case, Rng $r): array
    {
        $some = static function (array $from, int $n) use ($r): array {
            $out = [];
            while (count($out) < $n) {
                $f = $r->pick($from);
                if (!in_array($f, $out, true)) {
                    $out[] = $f;
                }
            }
            return $out;
        };
        $how = $r->weighted(['edit' => 6, 'delete' => 2, 'maybe' => 2]);
        $remote = $r->chance(0.85) ? $some(self::CHOICE_FIELDS, $r->int(1, 2)) : [];
        $mine = $how === 'edit' ? $some(self::CHOICE_FIELDS, $r->int(1, 2)) : ($how === 'maybe' ? ['status'] : []);
        if ($how === 'edit' && $remote !== [] && array_intersect($mine, $remote) === [] && $r->chance(0.5)) {
            $mine[0] = $remote[0]; // the same field changed on both sides, half the time it would not be by chance
            $mine = array_values(array_unique($mine));
        }
        // A time changed here meets a time changed at Google half the time it would not by chance, so a request that sets
        // part of the time is drawn over Google's change of the same field (the review of 2026-11-14, finding 3).
        if ($how === 'edit' && $remote !== [] && in_array('time', $mine, true) && !in_array('time', $remote, true) && $r->chance(0.5)) {
            $remote[0] = 'time';
            $remote = array_values(array_unique($remote));
        }
        // How the time is set here, as a client carrying `over` sends it: whole (T2), in the forms of timeRequest (a drag, a
        // resize, a start moved, the editor's all-day switch, the editor's zone picker), `zone` the other zone a form names.
        $mineTime = in_array('time', $mine, true) ? ['form' => $r->weighted(['drag' => 4, 'resize' => 2, 'start' => 2, 'toggle' => 2, 'zone' => 2]),
            'days' => $r->pick([0, 0, 1, 2, -1]), 'secs' => $r->pick([3600, 0, 1800]), 'zone' => $r->pick(self::SINGLE_ZONES), 'tz' => $r->pick(['utc', 'shown']),
            // Typed clocks beside a timed time (api-contract.md, `typed`): a start clock half an hour before the start sent,
            // with no typed end, so the resolved time starts there and ends at the end sent. And an all-day time sent with
            // clocks on its boundaries, which the API reads as their dates (T4, narrowed).
            'typed' => $r->chance(0.12), 'clocks' => $r->chance(0.15) ? $r->pick(['T15:00:00+00:00', 'T23:30:00-08:00', 'T00:30:00+09:00']) : null] : null;
        $same = array_values(array_filter(array_intersect($remote, $mine), static fn(string $f): bool => $r->chance(0.35)));
        // How the time changes at Google: shifted whole, its start alone, its end alone, its kind (timed made all-day over
        // one to three days from the date it shows in its zone, all-day made timed at 18:00 to 19:00 in `zone`), or its zone
        // alone (the instants kept; an all-day event's kind changed instead). No moment (a timed end at its start) is made
        // at Google or by w-create: it is no event state (T4), and what the product stores of one is a separate, older
        // matter; a moment appears only among the refusal draws below.
        $remoteTime = in_array('time', $remote, true) && !in_array('time', $same, true)
            ? ['how' => $r->weighted(['shift' => 3, 'start' => 2, 'end' => 2, 'kind' => 4, 'zone' => 1]), 'days' => $r->int(1, 3), 'zone' => $r->pick(self::SINGLE_ZONES)] : null;
        // A poll between the change at Google and the change here, more often when Google changed the time's kind; after it
        // the request carries the record shown before it (a stale client, the page's case 5) or the one served after it.
        $poll = $remote !== [] && $r->chance(($remoteTime['how'] ?? '') === 'kind' ? 0.6 : 0.3);
        // The event made: timed for an hour at a wall clock in a zone (the clocks put its UTC date on either side of its
        // own in the zones away from UTC), or all-day over one to three days, sent with the zone too (which the server
        // ignores for an all-day event).
        $created = ['do' => 'w-create', 'pick' => $r->int(0, 999) / 1000, 'single' => true, 'zone' => $r->pick(self::SINGLE_ZONES), 'clock' => $r->pick(['07:00', '15:00', '19:00', '23:30'])];
        if ($r->chance(0.3)) {
            $created['allDay'] = $r->int(1, 3); // an all-day event of one to three days
        }
        // A small share of the blocks send what a client from before the rule sends with `over` (T2): the time not whole in
        // the body or in `over`; or a time whose end, resolved, is not after its start (T4, narrowed: the one validity
        // check): a moment or an end before the start in the body or in `over`, or typed clocks that resolve to one. The
        // refusal is the expectation (outdated).
        $outdated = null;
        if (in_array($how, ['edit', 'delete'], true) && $r->chance(0.12)) {
            $outdated = $r->pick($mineTime !== null ? ['pair', 'start', 'end', 'noTz', 'bodyMoment', 'bodyInvalid', 'typedMoment', 'overNoTz', 'overPair', 'overMoment', 'overInvalid'] : ($how === 'delete' ? ['overNoTz', 'overPair', 'overMoment', 'overInvalid'] : ['overNoTz', 'overPair']));
        }
        // `over`'s all-day boundaries sent with a clock in a small share (read as their dates, T4 narrowed).
        $overClock = $r->chance(0.15);
        $block = [$created];
        if ($remote !== []) {
            $block[] = ['do' => 'r-single', 'pick' => 0.0, 'fields' => $remote, 'same' => $same] + ($remoteTime === null ? [] : ['time' => $remoteTime]) + (in_array('time', $same, true) ? ['mineTime' => $mineTime] : []);
        }
        if ($poll) {
            $block[] = ['do' => 'p-delta', 'pick' => 0.0];
        }
        $shown = $poll && $r->chance(0.3) ? 'now' : 'earlier';
        $block[] = ['do' => 'w-single', 'pick' => 0.0, 'how' => $how, 'fields' => $mine, 'tags' => $how === 'edit' && $r->chance(0.3), 'shown' => $shown, 'mark' => 's']
            + ($mineTime === null ? [] : ['time' => $mineTime]) + ($outdated === null ? [] : ['outdated' => $outdated]) + ($overClock ? ['overClock' => true] : []);
        $block[] = ['do' => 'w-single', 'pick' => 0.0, 'how' => 'overwrite', 'mark' => 's'];
        // The undo of the edit (google-conflict-choice.md, Slice 3a), in most blocks that edit or answer Maybe: after a change
        // at Google not yet read to a field the edit set (case 5) or to another (case 2), or nothing (case 1), sometimes
        // read by a poll first (case 7). Drawn after every other draw of the block, so the block's own draws are as before.
        if ($how !== 'delete' && $r->chance(0.6)) {
            $plain = ['title', 'description', 'location', 'url', 'status', 'showAs'];
            $set = array_values(array_intersect($mine, $plain));
            $after = $r->weighted(['same' => 4, 'other' => 3, 'none' => 1]);
            $field = $after === 'same' && $set !== [] ? $r->pick($set) : $r->pick(array_values(array_diff($plain, $set)));
            if ($after !== 'none') {
                $block[] = ['do' => 'r-single', 'pick' => 0.0, 'fields' => [$field], 'same' => []];
                if ($r->chance(0.3)) {
                    $block[] = ['do' => 'p-delta', 'pick' => 0.0];
                }
            }
            $block[] = ['do' => 'u-entry', 'pick' => 0.0, 'entryOf' => 's'];
        }
        $steps = $case['steps'];
        $keep = max(1, count($steps) - count($block));
        $steps = array_slice($steps, 0, $keep);
        array_splice($steps, $r->int(1, count($steps)), 0, $block);
        $case['steps'] = $steps;
        $case['single'] = (isset($created['allDay']) ? 'all-day ' : "timed {$created['clock']} {$created['zone']} ") . $how
            . ($mineTime === null ? '' : " (time: {$mineTime['form']}" . (in_array($mineTime['form'], ['toggle', 'zone'], true) ? " {$mineTime['zone']}" : '') . ')')
            . (empty($mineTime['typed']) ? '' : ', typed') . (empty($mineTime['clocks']) ? '' : ', clocks') . ($overClock ? ', over clocks' : '')
            . ($outdated === null ? '' : ", outdated ($outdated)")
            . ($remote === [] ? ', nothing at Google' : ' over ' . implode('+', $remote) . ($remoteTime === null ? '' : " (time: {$remoteTime['how']}" . (in_array($remoteTime['how'], ['kind', 'zone'], true) ? " {$remoteTime['zone']}" : '') . ')'))
            . ($poll ? ', polled between, ' . ($shown === 'now' ? 'shown after the poll' : 'shown before the poll (stale)') : '');
        return $case;
    }

    /** The share of drawn cases given a flip between timed and all-day (withFlip); interleave-cli's --flips sets it, 0 turns it off. */
    public static float $flips = 0.2;

    /** The share of drawn cases given a day shape (withDayShape); interleave-cli's --day-shapes sets it, 0 turns it off. */
    public static float $dayShapes = 0.15;

    /** The clocks the day shapes draw: west of UTC both ways, east of it, 10:00 Los Angeles, and 00:00 UTC as the control. */
    private const DAY_CLOCKS = [['America/New_York', '19:00'], ['Asia/Tokyo', '07:00'], ['America/Los_Angeles', '10:00'], ['UTC', '00:00']];

    /** The zone a zone change moves each clock's series to, keeping its wall clock: across the date line, so a day's old key shows on another date in the other zone (UTC to Berlin is the control). */
    private const OTHER_ZONE = ['America/New_York' => 'Asia/Tokyo', 'Asia/Tokyo' => 'America/New_York', 'America/Los_Angeles' => 'Asia/Tokyo', 'UTC' => 'Europe/Berlin'];

    /**
     * A drawn case given a day shape (rule 1, the undo fault and 1A's rule for a stopped form change): its series and
     * steps replaced, from $r alone.
     *
     * - tag, notes, useSeries, delete: a day with no row and no skip when the series' form or zone changes, by a "this
     *   event" change given and undone (a tag, notes), notes given then "use series value", or a delete undone; then the
     *   change (a flip or a zone change, made here, or made at the stand-in and read by a poll); then the redo (for
     *   useSeries, the undo of "use series value"), judged by x-day on the day's own date.
     * - stale: notes given and undone, the change, other notes given to the same day, then the first redone: refused as
     *   stale with nothing written or sent (x-day).
     * - stopped: a day given its own notes, a flip whose master write meets the network down (journaled, Google still in
     *   the old form), a change made at the stand-in in the old form (a day renamed, deleted, given another time, or the
     *   edited day's notes changed), then Finish or Put back, judged by x-day.
     */
    private static function withDayShape(array $case, Rng $r): array
    {
        [$tz, $clock] = $r->pick(self::DAY_CLOCKS);
        [$family, $rrule] = $r->pick([['daily', 'FREQ=DAILY'], ['weekly_bare', 'FREQ=WEEKLY'], ['weekly_list', 'FREQ=WEEKLY;BYDAY=MO,TU']]);
        $shape = $r->weighted(['tag' => 3, 'notes' => 3, 'useSeries' => 2, 'delete' => 3, 'stale' => 3, 'stopped' => 4, 'tagged' => 3, 'taggedStale' => 2, 'skipUndo' => 2]);
        // A delete's own skip is carried by a change made here; one made at the stand-in drops it, so that shape is drawn here only.
        $change = $shape === 'stopped' ? 'flipHere' : ($shape === 'skipUndo' ? $r->weighted(['flipHere' => 3, 'zoneHere' => 2]) : $r->weighted(['flipHere' => 3, 'zoneHere' => 2, 'flipThere' => 2, 'zoneThere' => 2]));
        $zone = str_starts_with($change, 'zone');
        $allDay = !$zone && $r->chance(0.5);
        $monday = $r->pick(['2027-01-04', '2027-01-18']);
        $case['series'] = ['family' => $family, 'rrule' => $rrule, 'import' => false, 'bound' => 'count', 'boundAt' => 8, 'untilForm' => 'dayend',
            'allDay' => $allDay, 'tzid' => $allDay ? 'UTC' : $tz, 'start' => $allDay ? $monday : $monday . ' ' . $clock, 'length' => $allDay ? 1 : 60,
            'flipZone' => $tz, 'flipClock' => (int) substr($clock, 0, 2) * 3600 + (int) substr($clock, 3, 2) * 60, 'otherZone' => self::OTHER_ZONE[$tz]];
        $pick = $r->int(150, 850) / 1000;
        $changeSteps = match ($change) {
            'flipHere' => [['do' => 'w-all', 'pick' => 0.0, 'what' => 'flip']],
            'zoneHere' => [['do' => 'w-all', 'pick' => 0.0, 'what' => 'zone']],
            'flipThere' => [['do' => 'r-flip', 'pick' => 0.0], ['do' => 'p-delta', 'pick' => 0.0]],
            'zoneThere' => [['do' => 'r-zone', 'pick' => 0.0], ['do' => 'p-delta', 'pick' => 0.0]],
        };
        // What the journal holds of the marked day when the series is re-placed, set by the shape and never by the answer
        // (decisions.md, "An undo acts on a day only where the journal says exactly where it is"): `none`, a day with no
        // row and no skip then, whose undo or redo is refused before anything is written or sent; `row`, the entry's own
        // row still there, carried with the series; `skip`, a delete's own skip, carried by a change made here.
        $judge = static fn(string $expect, string $anchor = 'none'): array => ['mark' => 'd', 'expect' => $expect, 'anchor' => $anchor];
        $case['steps'] = match ($shape) {
            // A day holding a tag (a row) when its notes are given and undone: the redo's own row is carried by the change.
            'tagged' => array_merge([['do' => 'w-this-l', 'pick' => $pick, 'what' => 'tags', 'mark' => 't'], ['do' => 'w-this-g', 'pick' => 0.0, 'what' => 'description', 'onMark' => 't', 'mark' => 'd'], ['do' => 'u-bare', 'pick' => 0.0]],
                $changeSteps, [['do' => 'redo', 'pick' => 0.0, 'judge' => $judge('description', 'row')]]),
            // The same, with other notes given to that day after the change: the day is placed, so staleness answers.
            'taggedStale' => array_merge([['do' => 'w-this-l', 'pick' => $pick, 'what' => 'tags', 'mark' => 't'], ['do' => 'w-this-g', 'pick' => 0.0, 'what' => 'description', 'onMark' => 't', 'mark' => 'd'], ['do' => 'u-bare', 'pick' => 0.0]],
                $changeSteps, [['do' => 'w-this-g', 'pick' => 0.0, 'what' => 'description', 'onMark' => 'd'], ['do' => 'redo', 'pick' => 0.0, 'judge' => $judge('stale', 'row')]]),
            // A day deleted, the change made here (its skip carried), then the delete undone: the day is a day again on its own date.
            'skipUndo' => array_merge([['do' => 'w-del1', 'pick' => $pick, 'mark' => 'd']], $changeSteps, [['do' => 'u-entry', 'pick' => 0.0, 'entryOf' => 'd', 'judge' => $judge('day', 'skip')]]),
            'tag' => array_merge([['do' => 'w-this-l', 'pick' => $pick, 'what' => 'tags', 'mark' => 'd'], ['do' => 'u-bare', 'pick' => 0.0]], $changeSteps,
                [['do' => 'redo', 'pick' => 0.0, 'judge' => $judge('tags')]]),
            'notes' => array_merge([['do' => 'w-this-g', 'pick' => $pick, 'what' => 'description', 'mark' => 'd'], ['do' => 'u-bare', 'pick' => 0.0]], $changeSteps,
                [['do' => 'redo', 'pick' => 0.0, 'judge' => $judge('description')]]),
            'useSeries' => array_merge([['do' => 'w-this-g', 'pick' => $pick, 'what' => 'description', 'mark' => 'd'], ['do' => 'w-useseries', 'pick' => 0.0, 'what' => 'description', 'onMark' => 'd', 'mark' => 'u']],
                $changeSteps, [['do' => 'u-entry', 'pick' => 0.0, 'entryOf' => 'u', 'judge' => $judge('description')]]),
            'delete' => array_merge([['do' => 'w-del1', 'pick' => $pick, 'mark' => 'd'], ['do' => 'u-bare', 'pick' => 0.0]], $changeSteps,
                [['do' => 'redo', 'pick' => 0.0, 'judge' => $judge('skip')]]),
            'stale' => array_merge([['do' => 'w-this-g', 'pick' => $pick, 'what' => 'description', 'mark' => 'd'], ['do' => 'u-bare', 'pick' => 0.0]], $changeSteps,
                [['do' => 'w-this-g', 'pick' => 0.0, 'what' => 'description', 'onMark' => 'd'], ['do' => 'redo', 'pick' => 0.0, 'judge' => $judge('stale')]]),
            'stopped' => [['do' => 'w-this-g', 'pick' => $pick, 'what' => 'description', 'mark' => 'e'], ['do' => 'f-net', 'pick' => 0.0, 'k' => 1, 'write' => 'w-all', 'what' => 'flip'],
                ['do' => $r->pick(['r-day-rename', 'r-day-delete', 'r-day-time', 'r-day-notes']), 'pick' => $r->int(0, 999) / 1000, 'mark' => 'g'],
                ['do' => $r->pick(['finish', 'putback']), 'pick' => 0.0, 'judge' => ['mark' => 'g', 'expect' => 'stopped']]],
        };
        $case['dayShape'] = $shape . ' ' . $change;
        return $case;
    }

    /**
     * A drawn case given a whole-series change between timed and all-day (the rule 1 audit's list for this mode): its
     * series becomes one at 00:00 UTC at UTC, east or west of it (Delta::MIDNIGHT_UTC), or at 10:00 Los Angeles where
     * no key coincides, timed or all-day (made timed at that clock), and one of three flips goes in among its steps:
     * the flip alone; the flip stopped right after its master write (refused at its second write), then Finish or Put
     * back; or the flip stopped before its master write's answer came back (taken, answer lost), a change made at the
     * stand-in, then Finish or Put back. The stand-in's stops, remote changes and polls of the case go on around it.
     */
    private static function withFlip(array $case, Rng $r): array
    {
        [$tz, $clock] = $r->pick(array_merge(Delta::MIDNIGHT_UTC, [['America/Los_Angeles', '10:00', 'winter']]));
        $season = $tz === 'America/Los_Angeles' && $clock === '10:00' ? 'winter' : null;
        foreach (Delta::MIDNIGHT_UTC as $m) {
            if ($m[0] === $tz && $m[1] === $clock) {
                $season = $m[2];
            }
        }
        $monday = $r->pick($season === 'summer' ? ['2027-07-05', '2027-07-19'] : ['2027-01-04', '2027-01-18']);
        [$family, $rrule] = $r->pick([['weekly_bare', 'FREQ=WEEKLY'], ['daily', 'FREQ=DAILY'], ['weekly_list', 'FREQ=WEEKLY;BYDAY=MO,TU']]);
        $allDay = $r->chance(0.4);
        $case['series'] = ['family' => $family, 'rrule' => $rrule, 'import' => false, 'bound' => 'count', 'boundAt' => 8, 'untilForm' => 'dayend',
            'allDay' => $allDay, 'tzid' => $allDay ? 'UTC' : $tz, 'start' => $allDay ? $monday : $monday . ' ' . $clock, 'length' => $allDay ? 1 : 60,
            'flipZone' => $tz, 'flipClock' => (int) substr($clock, 0, 2) * 3600 + (int) substr($clock, 3, 2) * 60];
        $flip = ['pick' => 0.0, 'what' => 'flip'];
        $recover = ['do' => $r->pick(['finish', 'putback']), 'pick' => 0.0];
        $insert = match ($r->weighted(['plain' => 4, 'stopAfter' => 3, 'stopBefore' => 3])) {
            'plain' => [['do' => 'w-all'] + $flip],
            'stopAfter' => [['do' => 'f-before', 'k' => 2, 'write' => 'w-all'] + $flip, $recover],
            'stopBefore' => [['do' => 'f-lost', 'k' => 1, 'write' => 'w-all'] + $flip, ['do' => $r->pick(['r-rename', 'r-exc+', 'r-skip+']), 'pick' => $r->int(0, 999) / 1000], $recover],
        };
        $at = $r->int(1, count($case['steps']));
        array_splice($case['steps'], $at, 0, $insert);
        $case['flip'] = true;
        return $case;
    }

    private static function drawStep(Rng $r, string $do): array
    {
        $step = ['do' => $do, 'pick' => $r->int(0, 999) / 1000];
        $what = match ($do) {
            'w-all' => $r->pick(['title', 'title', 'description', 'location', 'url', 'tags', 'time', 'length', 'count', 'showAs']),
            'w-race' => 'title',
            'w-follow' => $r->pick(['title', 'tags', 'description', 'showAs']),
            // Since slice 2 of the conflict choice also a link, Planned or Maybe (`status`, sent as the relationship) and "Use
            // series value" on an edited day (`useSeries`): every field the comparison reads, and every kind of change to a day.
            'w-this-g' => $r->pick(['title', 'description', 'location', 'time', 'showAs', 'url', 'status', 'useSeries']),
            'w-this-l' => $r->pick(['tags', 'tags', 'people', 'reminders', 'icon', 'coords']),
            'w-this-m' => $r->pick(['title+tags', 'description+reminders', 'location+coords']),
            // Since 1.5.8 transparency is a shown field (r-free); guestsCanModify stands in its place among the fields only Google holds.
            'r-gonly' => $r->pick(['colorId', 'attendees', 'guestsCanModify', 'visibility']),
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
        // A change to one day (google-conflict-choice.md, Slice 2), from the pick alone so no draw after it moves: `onRemote`,
        // the day a change at Google not yet read touched, when there is one (else the day the pick names), so a change at
        // Google to this day's own record is reached as often as one to another day; `byRow`, an edited day sent to its own
        // row as the app sends it (else to the repeating event with the day's key). And a day's record changed at Google
        // (r-exc+) has its time moved too (`moved`) in a third of the draws. A fixed case names each or has none of them.
        $mil = (int) round($step['pick'] * 1000);
        if (in_array($do, ['w-this-g', 'w-this-m', 'w-del1'], true)) {
            $step['onRemote'] = $mil % 2 === 0;
            $step['byRow'] = intdiv($mil, 2) % 2 === 0;
        }
        if ($do === 'r-exc+') {
            $step['moved'] = $mil % 3 === 0;
        }
        return $step;
    }

    // ---- running a case ---------------------------------------------------------

    /** @return array{spec:array,records:list<array>,path:string,runs:array,unoracled:list<string>,product:array} */
    public function run(array $spec): array
    {
        $this->spec = $spec;
        $this->records = $this->runs = $this->na = $this->done = $this->touches = $this->dirty = $this->gids = $this->marks = [];
        $this->pending = $this->recovered = null;
        $this->pendings = [];
        $this->entryStates = [];
        $this->etagOnly = [];
        $this->singles = $this->served = [];
        $this->single = $this->lastSingle = $this->singleSent = $this->made = $this->daySent = null;
        // A fixed case may name the setting for its account (`conflictChoice`), so a case written for the 1.5.6 refusal
        // keeps showing it whatever the run's setting.
        $this->choiceOn = (bool) ($spec['conflictChoice'] ?? Product::$conflictChoice);
        if (array_key_exists('conflictChoice', $spec)) {
            \conflictChoice($this->db, $this->userId, $this->choiceOn);
        }
        $this->firstId = (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) + 1 FROM events');
        $this->step = 0;
        $req = count($this->stand->fake->requests);
        $made = $this->createSeries($spec['series']);
        if ($made === null) {
            return $this->result('none');
        }
        // The case's series is a new event made here too: judged as w-create's is, before any step (step 0).
        $rows = $this->rows();
        $gid = (string) ($rows[$made]['google_event_id'] ?? '');
        $this->checkNewMarking('create', $rows[$made] ?? null, $gid === '' ? null : GoogleStand::showAsOf($this->stand->fake->events[$gid] ?? []),
            array_slice($this->stand->fake->requests, $req), !empty($spec['series']['allDay']), ['step' => 0, 'do' => 'create']);
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
                'url' => (string) $r['url'], 'status' => $r['status'], 'showAs' => (string) ($r['show_as'] ?? ''), 'start' => $r['start_utc'], 'end' => $r['end_utc'], 'allDay' => (int) $r['all_day'], 'tz' => $r['tzid'], 'rrule' => $r['rrule'], 'skips' => $ex,
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
                        'url' => (string) ($m['source']['url'] ?? ''), 'status' => (string) ($m['status'] ?? 'confirmed'), 'showAs' => GoogleStand::showAsOf($m)];
                }
                $digest[$gid] = $h;
            } else {
                // An event in no series: the six fields the conflict choice compares, by the harness's own equalities.
                $digest[$gid] = ['single' => true] + self::googleSix($m);
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
            // Google's own reminders on a resource once it has been all-day (useDefault false, replay of 2026-11-13, F)
            // are Google's doing, not a write's here.
            if (($o['reminders'] ?? null) === ['useDefault' => false]) {
                unset($o['reminders']);
            }
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
                'location' => (string) $r['location'], 'url' => (string) $r['url'], 'status' => (string) $r['status'], 'showAs' => (string) ($r['show_as'] ?? '')];
        }
        // The case's events in no series, in gstate's shape for them.
        foreach ($rows as $r) {
            if ($r['rrule'] === null && $r['recurrence_parent_id'] === null && isset($this->singles[(string) $r['google_event_id']])) {
                $out[(string) $r['google_event_id']] = ['single' => true] + self::rowSix($r);
            }
        }
        return $out;
    }

    // ---- the six fields of an event in no series, by the harness's own equalities ----------------------------------
    // google-conflict-choice.md, the one page, (a): title trimmed, "(No title)" equal to empty; notes, null equal to
    // empty (the harness's notes are plain text, which the sanitizer leaves alone); place trimmed; link, null equal to
    // empty (the harness's links are plain https addresses); status, tentative or not; showAs (1.5.8), free or not; time, start, end and all-day
    // together, the zone not compared. Each read three ways: a row here, a resource at the stand-in, a record as served.

    private static function titleOf(?string $t): string
    {
        $t = trim((string) $t);
        return $t === '(No title)' ? '' : $t;
    }

    private static function instantOf(string $v): int
    {
        return (new \DateTimeImmutable($v, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /** @return array{title:string,description:string,location:string,url:string,status:bool,time:array} */
    private static function rowSix(array $r): array
    {
        return ['title' => self::titleOf($r['title']), 'description' => trim((string) $r['description']), 'location' => trim((string) $r['location']), 'url' => trim((string) $r['url']),
            'status' => (string) $r['status'] === 'tentative', 'showAs' => (string) ($r['show_as'] ?? '') === 'free', 'time' => [self::instantOf((string) $r['start_utc']), self::instantOf((string) $r['end_utc']), (int) $r['all_day'] === 1]];
    }

    private static function googleSix(array $m): array
    {
        $at = static fn(array $w): int => self::instantOf((string) ($w['date'] ?? $w['dateTime'] ?? '1970-01-01'));
        return ['title' => self::titleOf($m['summary'] ?? ''), 'description' => trim((string) ($m['description'] ?? '')), 'location' => trim((string) ($m['location'] ?? '')),
            'url' => trim((string) ($m['source']['url'] ?? '')), 'status' => ($m['status'] ?? '') === 'tentative', 'showAs' => GoogleStand::showAsOf($m) === 'free', 'time' => [$at((array) ($m['start'] ?? [])), $at((array) ($m['end'] ?? [])), isset($m['start']['date'])]];
    }

    /** One field's value in the form the API serves it (a served record's, an over's, a 409's was, now or yours), compared as rowSix compares. */
    private static function servedValue(string $field, mixed $v): mixed
    {
        return match ($field) {
            'title' => self::titleOf(is_string($v) ? $v : null),
            'status' => $v === 'tentative',
            'showAs' => $v === 'free',
            'time' => is_array($v) ? self::timeOf($v) : null,
            default => trim(is_string($v) ? $v : ''),
        };
    }

    // ---- a time as one value (the rule of 2026-11-14, T1 to T5) ----------------------------------------------------
    // T1: a time is one value, start, end, all-day and zone together; on the wire, the four keys `start`, `end`, `allDay`
    // and `tzid`, in a request body and in `over` alike. The clock-time flag (`floating`) is a field of its own, not part
    // of that value: the four keys are the whole time, and the harness makes no floating event in the single block.

    /** The four keys of a whole time (T1). */
    private const TIME_KEYS = ['start', 'end', 'allDay', 'tzid'];

    /** The zones the single block draws: UTC, two several hours west of it, two east, one of those half an hour off the hour. */
    private const SINGLE_ZONES = ['UTC', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo', 'Asia/Kolkata'];

    /** Whether a time sent (a request's keys or an `over`'s) is whole: all four keys, none null (T2). */
    private static function isWhole(array $t): bool
    {
        foreach (self::TIME_KEYS as $k) {
            if (!isset($t[$k])) {
                return false;
            }
        }
        return true;
    }

    /**
     * A time's comparable value [start, end, all-day] (google-conflict-choice.md, the field table (a): "all three equal;
     * the zone is not compared", and the comparison table: "the same start and end instants and the same all-day
     * setting"). An all-day time is its dates, each read as the date it names whatever offset or zone comes with it
     * (api-contract.md, Time Zones and All-Day Events: all-day events "are dates, stored as UTC midnights with `tzid`
     * `UTC` whatever a write sends"); a timed one its instants. So a zone changed alone, the instants kept, is no change
     * of the time.
     *
     * @return array{0:int,1:int,2:bool}
     */
    private static function timeOf(array $t): array
    {
        $allDay = (bool) ($t['allDay'] ?? false);
        $read = static fn(string $v): int => $allDay ? self::instantOf(substr($v, 0, 10)) : self::instantOf($v);
        return [$read((string) ($t['start'] ?? '')), $read((string) ($t['end'] ?? '')), $allDay];
    }

    /**
     * Why a whole time is no event state, or null when it is one. T4, narrowed 2026-11-15: the one validity check, on the
     * resolved time, is that its end is after its start. Its values are read as the API reads a time, an all-day boundary
     * as its date with any clock on it ignored (api-contract.md, Time Zones and All-Day Events), so a clock on an all-day
     * boundary is no fault, and it is given resolved (allDayRead), where an all-day end on or before its start already
     * reads as the day after the start (#302): an all-day time is never refused for its length.
     */
    private static function invalidState(array $t): ?string
    {
        [$s, $e] = self::timeOf(self::allDayRead($t));
        return $e <= $s ? ($e === $s ? 'a timed moment, its end at its start' : 'timed end before its start') : null;
    }

    /** An instant written in a zone's own offset, as the API and Google serve a timed value. */
    private static function inZone(int $at, string $zone): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i:sP');
    }

    /** The instant of a wall clock (`HH:MM`) on a date in a zone. */
    private static function wall(string $date, string $clock, string $zone): int
    {
        return (new \DateTimeImmutable("$date $clock", new \DateTimeZone($zone)))->getTimestamp();
    }

    /** The date an instant falls on in a zone. */
    private static function localDate(int $at, string $zone): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
    }

    /**
     * A change of the time of an event in no series as a client that carries `over` sends it (T2: "if the request changes
     * the time, it sends the time whole"): the four keys, from the record the person was shown. `$t`: form, days and secs
     * (a drag's move; a timed resize or start moves by secs, or half an hour), zone (another zone the form names) and tz
     * (the zone sent with an event made all-day: UTC, or the shown one, which the server ignores for an all-day time,
     * api-contract.md: "with `tzid` `UTC` whatever a write sends"). Forms: a drag (both ends moved, the shown kind and
     * zone), a resize (the end moved), a start moved (the shown end kept: the editor's start field), the editor's all-day
     * switch (a timed event made all-day over the dates it shows in its zone; an all-day one made timed at 15:00 to 16:00
     * on its first date in `zone`), and the editor's zone picker (the same wall clocks in `zone`, so the instants move;
     * an all-day event has none and is dragged instead). A timed value is written in its zone's offset, as served.
     */
    private static function timeRequest(array $shown, array $t): array
    {
        $form = (string) ($t['form'] ?? 'drag');
        $days = (int) ($t['days'] ?? 0);
        $secs = (int) ($t['secs'] ?? 3600);
        $tz = (string) ($shown['tzid'] ?? 'UTC');
        $zone = (string) ($t['zone'] ?? 'UTC');
        $date = static fn(int $at): string => gmdate('Y-m-d', $at);
        if (!empty($shown['allDay'])) {
            [$s, $e] = self::timeOf($shown);
            $k = $days !== 0 ? $days : 1;
            if ($form === 'toggle') {
                $d = $date($s);
                return ['start' => self::inZone(self::wall($d, '15:00', $zone), $zone), 'end' => self::inZone(self::wall($d, '16:00', $zone), $zone), 'allDay' => false, 'tzid' => $zone];
            }
            [$s, $e] = match ($form) {
                'resize' => [$s, $e + 86400],
                'start' => [$s - 86400, $e],
                default => [$s + $k * 86400, $e + $k * 86400],
            };
            return ['start' => $date($s), 'end' => $date($e), 'allDay' => true, 'tzid' => $tz];
        }
        [$s, $e] = self::timeOf($shown);
        $by = $days * 86400 + $secs;
        $by = $by !== 0 ? $by : 3600;
        $edge = $secs !== 0 ? $secs : 1800;
        if ($form === 'toggle') {
            $first = self::localDate($s, $tz);
            $last = self::localDate($e - 1, $tz);
            return ['start' => $first, 'end' => Days::date(Days::ofDate($last) + 1), 'allDay' => true, 'tzid' => ($t['tz'] ?? 'utc') === 'shown' ? $tz : 'UTC'];
        }
        if ($form === 'zone') {
            // Never the shown zone itself: a zone sent with the same instants is not drawn (none of the zones share an offset).
            $to = $zone !== $tz ? $zone : self::SINGLE_ZONES[(array_search($tz, self::SINGLE_ZONES, true) + 1) % count(self::SINGLE_ZONES)];
            $clock = static fn(int $at): string => (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($tz))->format('H:i');
            $ns = self::wall(self::localDate($s, $tz), $clock($s), $to);
            $ne = self::wall(self::localDate($e, $tz), $clock($e), $to);
            return ['start' => self::inZone($ns, $to), 'end' => self::inZone($ne, $to), 'allDay' => false, 'tzid' => $to];
        }
        [$s, $e] = match ($form) {
            'resize' => [$s, $e + $edge],
            'start' => [$s - $edge, $e],
            default => [$s + $by, $e + $by],
        };
        return ['start' => self::inZone($s, $tz), 'end' => self::inZone($e, $tz), 'allDay' => false, 'tzid' => $tz];
    }

    /**
     * A request the rule refuses (T2, T4), made from the whole one the app would send: the time left partial in the body
     * (`pair`: start and end, the 1.5.7 client's drag; `start` or `end` alone; `noTz`: three keys of four), or in `over`
     * (`overNoTz`: start, end and allDay, the 1.5.7 client's `over`; `overPair`: start and end), or `over` whole but no
     * real event state (T4): `overMoment`, its end at its start; `overInvalid`, its end before its start (an hour); or the
     * body whole but no state: `bodyMoment`, its end at its start, `bodyInvalid`, its end before its start. For an all-day
     * time each of these four is a valid whole time, not a refusal: the API reads its end as the day after its start
     * (allDayRead, #302), which x-choice then judges as any change; or `typedMoment`, a timed body whole with `typed` naming
     * only a start clock at the end sent, so the resolved time starts and ends there (an all-day body takes
     * `bodyMoment`). `over` names the time from `$shown` where the request did not.
     */
    private static function outdated(array $body, string $how, array $shown): array
    {
        $only = static fn(array $a, array $keys): array => array_diff_key($a, array_flip(array_diff(self::TIME_KEYS, $keys)));
        if (str_starts_with($how, 'over')) {
            $body['over'] += array_intersect_key($shown, array_flip(self::TIME_KEYS));
            $o = $body['over'];
            $allDay = !empty($o['allDay']);
            $body['over'] = match (true) {
                $how === 'overNoTz' => $only($o, ['start', 'end', 'allDay']),
                $how === 'overPair' => $only($o, ['start', 'end']),
                default => ['end' => self::endAgainstStart($o, $how === 'overMoment')] + $o,
            };
            return $body;
        }
        if ($how === 'typedMoment' && empty($body['allDay'])) {
            return ['typed' => ['start' => self::clockIn(self::instantOf((string) $body['end']), (string) $body['tzid']), 'tz' => (string) $body['tzid']]] + $body;
        }
        if (in_array($how, ['bodyMoment', 'bodyInvalid', 'typedMoment'], true)) {
            return ['end' => self::endAgainstStart($body, $how !== 'bodyInvalid')] + $body;
        }
        $keep = match ($how) { 'pair' => ['start', 'end'], 'start' => ['start'], 'end' => ['end'], default => ['start', 'end', 'allDay'] };
        return $only($body, $keep);
    }

    /** The wall clock (`YYYY-MM-DDTHH:MM`) an instant shows in a zone, as `typed` sends one. */
    private static function clockIn(int $at, string $zone): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d\TH:i');
    }

    /**
     * The time a body sets, resolved (T4: "the time as it stands after every transformation between the request's entry
     * and the write (typed clocks included)"). From api-contract.md, `typed`: with a typed start and no typed end, the
     * start is the instant of the typed clock in `typed.tz` (else the request's `tzid`), an instant sent beside it that is
     * not its clock's is ignored, and the end not typed is the end sent. The harness's typed clocks never fall in a gap
     * or an overlap, so the one wall-clock rule is the plain reading. Without `typed`, the time sent. Then the all-day
     * reading every time takes (allDayRead).
     */
    private static function resolved(array $body): array
    {
        $t = array_intersect_key($body, array_flip(self::TIME_KEYS));
        $typed = $body['typed'] ?? null;
        if (is_array($typed) && isset($typed['start']) && empty($t['allDay'])) {
            $zone = (string) ($typed['tz'] ?? $t['tzid']);
            $t['start'] = self::inZone((new \DateTimeImmutable(str_replace('T', ' ', (string) $typed['start']), new \DateTimeZone($zone)))->getTimestamp(), (string) $t['tzid']);
        }
        return self::allDayRead($t);
    }

    /**
     * An all-day time as the API reads it (T4, narrowed: values are read as the API reads any time; issue #302): its
     * boundaries are their dates (api-contract.md, Time Zones and All-Day Events: "only its date is read"), and an end on
     * or before the start is read as one day long, the end the day after the start (the API's reading as the owner states
     * it for #302 and the outside read of 2026-11-15, R3, found it; the contract's sentence names the dates, not this, so
     * the harness takes it from that statement and the docs now say it). A timed time is as sent.
     */
    private static function allDayRead(array $t): array
    {
        if (empty($t['allDay']) || !isset($t['start'], $t['end'])) {
            return $t;
        }
        $s = self::instantOf(substr((string) $t['start'], 0, 10));
        $e = self::instantOf(substr((string) $t['end'], 0, 10));
        return ['start' => gmdate('Y-m-d', $s), 'end' => gmdate('Y-m-d', $e > $s ? $e : $s + 86400)] + $t;
    }

    /** An end for a whole time at its start (`$at`), or before it by an hour or, all-day, a day: no event state (T4). */
    private static function endAgainstStart(array $t, bool $at): string
    {
        if (!empty($t['allDay'])) {
            return gmdate('Y-m-d', self::instantOf(substr((string) $t['start'], 0, 10)) - ($at ? 0 : 86400));
        }
        return self::inZone(self::instantOf((string) $t['start']) - ($at ? 0 : 3600), (string) $t['tzid']);
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
        return ['rows' => $rows, 'l' => $this->lstate($rows), 'g' => $this->gstate(), 'req' => count($this->stand->fake->requests), 'here' => $this->here(),
            'differs' => (int) $this->db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND kind = 'google_differs'", [$this->userId]),
            'mut' => (int) $this->db->scalar('SELECT COALESCE(MAX(id), 0) FROM mutations WHERE user_id = ?', [$this->userId]), 'half' => GoogleSeries::halfDoneOf($this->db, $this->userId)];
    }

    /** What a refusal must leave as it was beyond the comparable rows: every row's Google etag and the calendar's sync token. */
    private function here(): array
    {
        $etags = [];
        foreach ($this->db->all('SELECT id, google_etag FROM events WHERE user_id = ? AND calendar_id = ? AND id >= ? AND deleted_at IS NULL ORDER BY id', [$this->userId, $this->stand->calendarId, $this->firstId]) as $r) {
            $etags[(int) $r['id']] = $r['google_etag'];
        }
        return ['l' => $this->lstate($this->rows()), 'etags' => $etags, 'token' => $this->db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$this->stand->calendarId])];
    }

    /** Nothing written here: the rows as compared, each row's etag and the calendar's sync token all as they were. */
    private static function sameHere(array $s0, array $s1): bool
    {
        return $s0['here'] == $s1['here'];
    }

    /**
     * The rows a step that applied nothing of its own leaves: the rows before it, plus the write the harness itself
     * scheduled during the step, exactly as scheduled. Only p-overlap schedules one (a tag given to a day while the
     * poll fetches, $this->overlap): that day's row, the one it had or a new one under the day's key, holds the
     * scheduled tags and owns them, and every other field of it is as before, or for a new row its series' at that
     * occurrence (its start the key, its length the series', per RFC 5545 3.8.5.3). Every other row is as before.
     * Built from the harness's own record of the write, never from the product; a write the product refused, or none
     * scheduled, leaves the rows before the step. A new row is taken from $l1 only by its identity (its series and
     * key); its content is all expected.
     */
    private function beforeAndScheduled(array $l0, array $l1): array
    {
        if ($this->overlap === null || $this->overlap[3]->refused !== null) {
            return $l0;
        }
        [$key, $tag, , , , $mid] = $this->overlap;
        $tags = ['sample', $tag];
        sort($tags);
        $ofDay = static fn(array $r): bool => (int) $r['parent'] === $mid && (string) $r['key'] === (string) $key;
        foreach ($l0 as $id => $r) {
            if ($ofDay($r)) {
                $own = array_values(array_unique(array_merge($r['own'], ['tags'])));
                sort($own);
                $l0[$id] = ['tags' => $tags, 'own' => $own] + $r;
                return $l0;
            }
        }
        $master = $l0[$mid] ?? null;
        if ($master === null) {
            return $l0;
        }
        foreach (array_diff_key($l1, $l0) as $id => $r) {
            if ($ofDay($r)) {
                $length = Clock::parse((string) $master['end'])->getTimestamp() - Clock::parse((string) $master['start'])->getTimestamp();
                $l0[$id] = ['parent' => $r['parent'], 'key' => $r['key'], 'rrule' => null, 'skips' => [], 'own' => ['tags'], 'tags' => $tags,
                    'start' => (string) $key, 'end' => Clock::db(Clock::plus(Clock::parse((string) $key), $length))] + $master;
                break;
            }
        }
        return $l0;
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

    /**
     * The app's writes the stand-in took between two snapshots: those it answered 2xx (a lost answer was taken). A
     * request it refused changed nothing there, so "writes nothing at Google" is judged by these and by what the
     * stand-in holds, never by every request sent; what a request carries (If-Match, its keys) and "sends nothing"
     * still read every request (writesBetween).
     */
    private function takenBetween(array $s0, array $s1): array
    {
        return array_values(array_filter($this->writesBetween($s0, $s1), static fn(array $q): bool => (int) ($q['status'] ?? 200) < 300));
    }

    // ---- the steps ---------------------------------------------------------------------

    private function runStep(array $step): void
    {
        $do = $step['do'];
        $this->daySent = null;
        $s0 = $this->snap();
        $knobs = $this->stand->knobs;
        $knobs->writes = $knobs->taken = 0;
        $knobs->fired = null;
        $knobs->listed = null;
        $knobs->down = false;
        $out = ['ok' => true, 'error' => null, 'extra' => [], 'asked' => null, 'skip' => false];
        try {
            $out = match (true) {
                str_starts_with($do, 'w-') => $this->write($do, $step, $s0),
                str_starts_with($do, 'r-') => $this->remote($do, $step, $s0),
                str_starts_with($do, 'p-') => $this->poll($do, $step, $s0),
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
            $out['status'] = $e instanceof HttpError ? $e->status : null;
            $out['asked'] ??= $this->lastAsked;
        }
        $this->lastAsked = null;
        $this->spec['steps'][$this->step - 1]['ran'] = $out['skip'] ? 'skipped' : ($out['ok'] ? 'ok' : (string) $out['error']);
        if (in_array($do, self::UNDO_KINDS, true) && !$out['skip']) {
            // What the entry's target was as the undo or redo ran: its day's row here or none, or the whole series.
            $this->spec['steps'][$this->step - 1]['target'] = $this->undoTarget(($out['asked'] ?? null) ?? [], $s0['rows']);
        }
        if ($out['skip']) {
            $this->ran('skipped', $do);
            return;
        }
        $this->ran('step', $do . ($out['ok'] ? '' : ' refused'));
        if ($do === 'w-all' && ($step['what'] ?? '') === 'flip' && !$out['ok'] && ($out['error'] ?? '') === 'google_hidden_days') {
            $this->flipRefused = true;
        }
        $s1 = $this->snap();
        if ($this->probe !== null && $this->probeStep === $this->step) {
            // An oracle probe (OracleProbes.php): damage injected into what the step's checks read, which a check that
            // keeps its contract must then fail on.
            [$out, $s0, $s1] = $this->probe->call($this, $do, $out, $s0, $s1);
        }
        $this->check($do, $out, $s0, $s1);
        if (isset($step['mark']) && $out['ok'] && isset($out['asked']['days'][0]) && !isset($this->marks[$step['mark']])) {
            $this->marks[$step['mark']] = ['date' => (string) $out['asked']['days'][0], 'fields' => $out['asked']['fields'] ?? [], 'kind' => $do, 'entry' => (int) $s1['mut']];
        }
        if (isset($step['judge'])) {
            $this->judgeDay($do, $step['judge'], $out, $s0, $s1);
        }
    }

    /** @var array<int,array{before:array,after:array,step:int}> each Activity entry's rows by identity before and after the step that made it */
    private array $entryStates = [];
    /**
     * The hidden-days rule (decisions.md, "A switch between timed and all-day that Google would hide days with is
     * refused"; interleaving-invariants.md, x-day, x-finish, x-undo-fields): whether a switch asked here was refused for
     * hidden days, so nothing was re-placed; the switch each series was last asked to make here, as a row, for the
     * harness's own reading at Finish; and the dates (as the days show, Y-m-d) a switch made at Google by another client
     * hid there, Google's own fault (issue 266), about which nothing is asserted.
     */
    private bool $flipRefused = false;
    /** @var array<string,array> */
    private array $flipTarget = [];
    /** @var array<string,true> */
    private array $remoteHidden = [];
    /** The day a w-race step's remote change touched, kept out of that step's entry (someone else's change, not the request's). */
    private ?string $racedIdent = null;
    /** An oracle probe's damage, applied after the step numbered probeStep (OracleProbes.php); null in every ordinary run. */
    public ?\Closure $probe = null;
    public ?int $probeStep = null;

    /** A snapshot's comparable states read again from its rows, after a probe changed them. */
    private function reshape(array $s): array
    {
        $s['l'] = $this->lstate($s['rows']);
        $s['here']['l'] = $s['l'];
        return $s;
    }

    /** What the step about to fail asked (set before the product is called, so a refusal still knows its series). */
    private ?array $lastAsked = null;

    /** An undo or redo's target as it ran: 'row' (its day had a row here), 'no row', or 'series' (a whole-series entry or unknown). */
    private function undoTarget(array $asked, array $rows): string
    {
        $key = $asked['key'] ?? null;
        $gid = $asked['gid'] ?? null;
        if ($key === null || $gid === null) {
            return 'series';
        }
        foreach (self::masters($rows) as $m) {
            if ((string) $m['google_event_id'] !== (string) $gid) {
                continue;
            }
            foreach ($rows as $r) {
                if ((int) ($r['recurrence_parent_id'] ?? 0) === (int) $m['id'] && $r['recurrence_instance_utc'] === $key) {
                    return 'row';
                }
            }
        }
        return 'no row';
    }

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

    /** The day of a master that the latest change at Google not yet read touched by its key, while the rows here show it: [key, date], or null. */
    private function remoteDay(array $master): ?array
    {
        $gid = (string) $master['google_event_id'];
        $skips = Recurrence::decodeExdates($master['exdates_json'] ?? null);
        $dates = array_flip(self::days($master, 200));
        foreach (array_reverse($this->touches) as $t) {
            if (!empty($t['remote']) && empty($t['polled']) && $t['gid'] === $gid && ($t['key'] ?? null) !== null) {
                $k = (string) $t['key'];
                return isset($dates[$k]) && !in_array($k, $skips, true) ? [$k, $dates[$k]] : null;
            }
        }
        return null;
    }

    /** An edited day of a master that owns a field the comparison reads, and that field, by the pick: [[key, date], field], or null. */
    private function ownedDay(array $master, float $pick): ?array
    {
        $dates = array_flip(self::days($master, 200));
        $found = [];
        foreach ($this->rows() as $r) {
            if ((int) ($r['recurrence_parent_id'] ?? 0) !== (int) $master['id'] || !isset($dates[(string) $r['recurrence_instance_utc']])) {
                continue;
            }
            foreach (array_intersect(['title', 'description', 'location', 'url', 'status', 'showAs'], $r['own']) as $f) {
                $found[] = [[(string) $r['recurrence_instance_utc'], $dates[(string) $r['recurrence_instance_utc']]], $f];
            }
        }
        return $found === [] ? null : $found[min(count($found) - 1, (int) floor($pick * count($found)))];
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
                case 'showAs':
                    // Free or busy (1.5.8, #303): the other of the series' marking, so the value is new for the series and
                    // for a day that follows it.
                    $in['showAs'] = $fields['showAs'] = (string) ($master['show_as'] ?? 'busy') === 'free' ? 'busy' : 'free';
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
        if ($do === 'w-race') {
            // A day renamed at Google between the series write's master write and its read-back; with "race": "master"
            // (fixed cases), the series' own title instead, under a write of `what` (title when drawn).
            $master = $this->seriesOf($rows, $step['pick']);
            $occ = $master === null ? null : $this->occurrenceOf($master, fmod($step['pick'] * 11.0, 1.0), 1);
            if ($master === null || $occ === null || empty($master['google_event_id'])) {
                return ['skip' => true];
            }
            $gid = (string) $master['google_event_id'];
            $onMaster = ($step['race'] ?? 'day') === 'master';
            $instance = $onMaster ? $gid : GoogleWriter::instanceId($gid, $occ[0], (int) $master['all_day'] === 1);
            $title = $onMaster ? "Sample remote series {$this->step}" : "Sample remote day {$this->step}";
            $this->raced = [$gid, $onMaster ? null : $occ[0], $title];
            $this->lastRace = $onMaster ? null : [$gid, $occ[0], $instance];
            $this->racedIdent = $onMaster ? 'series:' . $gid . '#title' : 'day:' . $gid . ':' . $occ[0];
            $this->armRace($instance, $title);
            try {
                return $this->write('w-all', $step + ['what' => 'title'], $s0);
            } finally {
                $this->stand->knobs->race = null;
            }
        }
        if ($do === 'w-create') {
            $day = Days::date(Days::ofDate('2027-02-01') + (int) floor($step['pick'] * 200));
            $this->lastAsked = ['kind' => 'create', 'gid' => null, 'fields' => [], 'days' => [$day]];
            // Timed for an hour from `clock` (15:00 by default) in `zone` (UTC by default), or all-day over `allDay` days
            // with the zone sent too (the single shape's all-day share).
            $days = (int) ($step['allDay'] ?? 0);
            $zone = (string) ($step['zone'] ?? 'UTC');
            $at = self::wall($day, (string) ($step['clock'] ?? '15:00'), $zone);
            $when = $days > 0 ? ['start' => $day, 'end' => Days::date(Days::ofDate($day) + $days), 'allDay' => true] : ['start' => self::inZone($at, $zone), 'end' => self::inZone($at + 3600, $zone), 'allDay' => false];
            try {
                $id = (int) $this->events->create($this->userId, ['calendarId' => $this->stand->calendarId, 'title' => "Sample event {$this->step}"] + $when + [
                    'tzid' => $zone, 'tagNames' => ['single'], 'location' => 'Sample single hall', 'description' => 'Sample single event notes', 'url' => 'https://example.test/single-event'])['eventId'];
                $this->events->throwDeferred();
            } catch (HttpError $e) {
                // Named in the summary, so a block that never got its event is seen rather than skipped in silence.
                $why = 'w-create of an event in no series refused' . ($days > 0 ? ' (all-day)' : '') . ": {$e->errorCode}";
                $this->na[$why] = ($this->na[$why] ?? 0) + 1;
                throw $e;
            }
            // The event in no series is the case's from here on: what each side holds of it is recorded (gstate, ldigest),
            // and the record the API serves for it now is what the person was shown (withSingle's change sends it as `over`).
            $gid = (string) $this->db->scalar('SELECT google_event_id FROM events WHERE id = ?', [$id]);
            $this->made = [$id, $gid, $days > 0];
            if ($gid !== '') {
                $this->gids[$gid] = true;
                $this->singles[$gid] = $id;
                $this->served[$gid] = $this->serve($id);
                if (!empty($step['single'])) {
                    $this->single = $gid;
                }
            }
            return ['asked' => $this->lastAsked];
        }
        if ($do === 'w-single') {
            return $this->writeSingle($step, $s0);
        }
        $master = $this->seriesOf($rows, $step['pick']);
        if ($master === null) {
            return ['skip' => true];
        }
        $gid = (string) $master['google_event_id'];
        $mid = (int) $master['id'];
        $what = (string) ($step['what'] ?? 'title');
        if ($do === 'w-useseries' || in_array($do, ['w-this-g', 'w-this-l', 'w-this-m', 'w-del1'], true)) {
            $occ = isset($step['onMark']) ? $this->markedOccurrence($master, (string) $step['onMark']) : $this->occurrenceOf($master, fmod($step['pick'] * 7.0, 1.0));
            if (!isset($step['onMark']) && !empty($step['onRemote'])) {
                $occ = $this->remoteDay($master) ?? $occ;
            }
            $useSeries = $do === 'w-useseries' ? $what : null;
            if ($do === 'w-this-g' && $what === 'useSeries') {
                // "Use series value" on an edited day that owns a field the comparison reads: the day and the field by the pick.
                [$occ, $useSeries] = $this->ownedDay($master, $step['pick']) ?? [null, null];
            }
            if ($occ === null) {
                return ['skip' => true];
            }
            [$key, $date] = $occ;
            $inst = self::instanceArg($master, $key);
            // An edited day is sent to its own row in the share `byRow` names (the app's request for one), else to the
            // repeating event with the day's key; an ordinary day always to the repeating event.
            $row = null;
            foreach ($rows as $rid => $r) {
                if ((int) ($r['recurrence_parent_id'] ?? 0) === $mid && (string) $r['recurrence_instance_utc'] === (string) $key) {
                    $row = $rid;
                }
            }
            $byRow = !empty($step['byRow']) && $row !== null && $do !== 'w-this-l';
            $target = $byRow ? $row : $mid;
            $scope = $byRow ? [] : ['scope' => 'this', 'instanceStart' => $inst];
            if ($useSeries !== null) {
                $this->lastAsked = ['kind' => 'this', 'gid' => $gid, 'key' => $key, 'fields' => [$useSeries => true], 'days' => [$date], 'local' => in_array($useSeries, self::LOCAL, true)];
                $this->daySent = ['action' => 'useSeries', 'body' => ['useSeries' => [$useSeries]], 'gid' => $gid, 'mid' => $mid, 'key' => $key, 'byRow' => $byRow];
                $this->events->patch($this->userId, $target, $scope + ['useSeries' => [$useSeries]]);
                $this->events->throwDeferred();
                return ['asked' => $this->lastAsked, 'answer' => $this->events->keptAnswer($this->userId)];
            }
            if ($do === 'w-del1') {
                $this->lastAsked = ['kind' => 'del1', 'gid' => $gid, 'key' => $key, 'fields' => [], 'days' => [$date]];
                $this->daySent = ['action' => 'delete', 'body' => [], 'gid' => $gid, 'mid' => $mid, 'key' => $key, 'byRow' => $byRow];
                $this->events->deleteEvent($this->userId, $target, $byRow ? null : 'this', $byRow ? null : $inst);
                $this->events->throwDeferred();
                return ['asked' => $this->lastAsked];
            }
            if ($what === 'status') {
                // Planned or Maybe on the day: the other of what the day shows, as the relationship endpoint takes it.
                $shown = (string) ($row !== null ? $rows[$row]['status'] : $master['status']);
                $rel = $shown === 'tentative' ? 'planned' : 'maybe';
                $this->lastAsked = ['kind' => 'this', 'gid' => $gid, 'key' => $key, 'fields' => ['status' => true], 'days' => [$date], 'local' => false];
                $this->daySent = ['action' => 'relationship', 'body' => ['relationship' => $rel], 'gid' => $gid, 'mid' => $mid, 'key' => $key, 'byRow' => $byRow];
                $this->events->setRelationship($this->userId, $target, $rel, $byRow ? null : 'this', $byRow ? null : $inst);
                $this->events->throwDeferred();
                return ['asked' => $this->lastAsked, 'answer' => $this->events->keptAnswer($this->userId)];
            }
            if ($what === 'time') {
                $len = Clock::parse((string) $master['end_utc'])->getTimestamp() - Clock::parse((string) $master['start_utc'])->getTimestamp();
                if ((int) $master['all_day'] === 1) {
                    // An all-day day dragged to the next date, its length kept.
                    $d = Days::ofDate(substr((string) $key, 0, 10)) + 1;
                    $in = ['start' => Days::date($d), 'end' => Days::date($d + intdiv($len, 86400)), 'tzid' => 'UTC', 'allDay' => true];
                } else {
                    $z = Clock::zone((string) $master['tzid']);
                    $start = Clock::parse($key);
                    $shift = (int) Clock::local($key, (string) $master['tzid'])->format('G') >= 20 ? -3600 : 3600;
                    $in = ['start' => Clock::iso(Clock::plus($start, $shift)->setTimezone($z)), 'end' => Clock::iso(Clock::plus($start, $shift + $len)->setTimezone($z)), 'tzid' => $master['tzid'], 'allDay' => false];
                }
                $fields = ['time' => true];
            } else {
                [$in, $fields] = $this->values($what, $master);
            }
            $this->lastAsked = ['kind' => 'this', 'gid' => $gid, 'key' => $key, 'fields' => $fields, 'days' => [$date], 'local' => array_diff(array_keys($fields), self::LOCAL) === []];
            $this->daySent = ['action' => 'patch', 'body' => $in, 'gid' => $gid, 'mid' => $mid, 'key' => $key, 'byRow' => $byRow];
            $this->events->patch($this->userId, $byRow ? $target : $mid, $in + ($do === 'w-this-l' ? ['scope' => 'this', 'instanceStart' => $inst] : $scope));
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked, 'answer' => $this->lastAsked['local'] ? [] : $this->events->keptAnswer($this->userId)];
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
            $this->lastAsked = ['kind' => 'delfollow', 'gid' => $gid, 'fields' => [], 'days' => $days, 'whole' => $at[0] === $first[0]];
            $plan = $this->events->previewDelete($this->userId, $mid, 'following', $inst);
            $this->events->deleteEvent($this->userId, $mid, 'following', $inst, null, !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => self::selected($plan)]);
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked];
        }
        if ($do === 'w-answer') {
            $this->lastAsked = ['kind' => 'answer', 'gid' => $gid, 'fields' => ['attendance' => 'going'], 'days' => $days, 'local' => false];
            $plan = $this->events->previewAttendance($this->userId, $mid, 'going', 'following', $inst);
            // An answer from a later day is a split at Google (the old series' end changes there); from the first day it is
            // the whole series' answer, held here alone.
            $this->lastAsked['local'] = !empty($plan['whole']);
            $this->events->setAttendance($this->userId, $mid, 'going', 'following', $inst, !empty($plan['whole']) ? null : ['hash' => $plan['hash'], 'choices' => self::selected($plan)]);
            $this->events->throwDeferred();
            return ['asked' => $this->lastAsked];
        }
        $scope = $do === 'w-all' ? 'all' : 'following';
        $in = ['scope' => $scope, 'instanceStart' => $inst];
        $fields = [];
        if ($what === 'zone') {
            if ($scope !== 'all' || (int) $master['all_day'] === 1) {
                return ['skip' => true];
            }
            $to = (string) ($this->spec['series']['otherZone'] ?? 'Europe/Berlin');
            $local = Clock::local((string) $at[0], (string) $master['tzid']);
            $len = Clock::parse((string) $master['end_utc'])->getTimestamp() - Clock::parse((string) $master['start_utc'])->getTimestamp();
            $t = new \DateTimeImmutable($local->format('Y-m-d H:i:s'), Clock::zone($to));
            $in += ['start' => Clock::iso($t), 'end' => Clock::iso(Clock::plus($t, $len)), 'allDay' => false, 'tzid' => $to];
            $fields = ['time' => true, 'zone' => true];
        } elseif ($what === 'flip') {
            // Between timed and all-day for all events, from the first day, on its calendar date: an all-day series made
            // timed at the case's clock and zone, a timed one made all-day. A time-class change for the checks.
            if ($scope !== 'all') {
                return ['skip' => true];
            }
            $date = (string) $at[1];
            if ((int) $master['all_day'] === 1) {
                $z = (string) ($this->spec['series']['flipZone'] ?? 'UTC');
                $t = Clock::at(Days::ofDate($date), (int) ($this->spec['series']['flipClock'] ?? 0), $z);
                $in += ['start' => Clock::iso($t), 'end' => Clock::iso(Clock::plus($t, 3600)), 'allDay' => false, 'tzid' => $z];
            } else {
                $in += ['start' => $date, 'end' => Days::date(Days::ofDate($date) + 1), 'allDay' => true];
            }
            $fields = ['time' => true, 'allDay' => true];
            $this->flipTarget[(string) $master['google_event_id']] = (int) $master['all_day'] === 1
                ? ['start_utc' => Clock::db($t), 'end_utc' => Clock::db(Clock::plus($t, 3600)), 'all_day' => 0, 'tzid' => $z, 'rrule' => (string) $master['rrule']]
                : ['start_utc' => $date . ' 00:00:00', 'end_utc' => Days::date(Days::ofDate($date) + 1) . ' 00:00:00', 'all_day' => 1, 'tzid' => 'UTC', 'rrule' => (string) $master['rrule']];
        } elseif ($what === 'time' || $what === 'length') {
            if ((int) $master['all_day'] === 1 || $scope !== 'all') {
                return ['skip' => true];
            }
            $start = Clock::parse((string) $at[0]);
            $len = Clock::parse((string) $master['end_utc'])->getTimestamp() - Clock::parse((string) $master['start_utc'])->getTimestamp();
            $shift = $what === 'length' ? 0 : ((int) Clock::local((string) $at[0], (string) $master['tzid'])->format('G') >= 20 ? -3600 : 3600);
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
        $this->lastAsked = ['kind' => $scope, 'gid' => $gid, 'fields' => $fields, 'days' => $days, 'from' => $at[1], 'local' => false];
        $plan = $this->events->previewPatch($this->userId, $mid, $in + ['plan' => ['choices' => []]]);
        // Local only when every Google-held field of every series stays as it is: a change for all events of fields held
        // here alone. "This and following" from a later day is a split at Google whatever its fields.
        $this->lastAsked['local'] = ($plan['scope'] ?? $scope) === 'all' && array_diff(array_keys($fields), self::LOCAL) === [];
        $this->events->patch($this->userId, $mid, $in + ['plan' => ['hash' => $plan['hash'], 'choices' => self::selected($plan)]]);
        $this->events->throwDeferred();
        return ['asked' => $this->lastAsked];
    }

    /** The record the API serves for an event (GET /events/:id/occurrence), as the app shows it. */
    private function serve(int $id): array
    {
        return $this->events->serializeSingle($this->events->get($this->userId, $id));
    }

    /**
     * A change here to the case's event in no series, sent as the app sends it (google-conflict-choice.md, (b) and (c)):
     * `over` holds the fields the change sets as the person was shown them (the record served when the event was made,
     * or, with `shown: now`, the one served now), untouched, the time whole (T2); an edit sets one or two fields (the time
     * whole, timeRequest), in some requests with tags; a delete sends every field in `over`; Planned or Maybe the other
     * of the two from what was shown. `outdated` makes it a request the rule refuses (outdated()). `overwrite` is the last
     * request again, as the 409 says: each asked field's `over` set to its `now` and its value to its `yours` (only after
     * a google_changed; skipped otherwise).
     */
    private function writeSingle(array $step, array $s0): array
    {
        $gid = $this->single;
        $id = $gid === null ? null : ($this->singles[$gid] ?? null);
        $how = (string) ($step['how'] ?? 'edit');
        if ($id === null || !isset($s0['rows'][$id])) {
            if ($how === 'overwrite') {
                $this->lastSingle = null;
            }
            return ['skip' => true];
        }
        if ($how === 'overwrite') {
            $last = $this->lastSingle;
            $this->lastSingle = null;
            if ($last === null || ($last['error'] ?? null) !== 'google_changed') {
                return ['skip' => true];
            }
            $body = $last['body'];
            foreach ((array) ($last['extra']['fields'] ?? []) as $f) {
                // The time as one value (T1): `over`'s four keys from `now` and the body's from `yours`. A key the 409 leaves
                // out (a time not served whole, which x-choice fails on its own) keeps what the request had, so Overwrite
                // is not refused for the 409's fault.
                if (($f['field'] ?? '') === 'time') {
                    foreach (self::TIME_KEYS as $k) {
                        if (is_array($f['now'] ?? null) && array_key_exists($k, $f['now'])) {
                            $body['over'][$k] = $f['now'][$k];
                        }
                        if (is_array($f['yours'] ?? null) && array_key_exists($k, $f['yours'])) {
                            $body[$k] = $f['yours'][$k];
                        }
                    }
                    continue;
                }
                $k = (string) ($f['field'] ?? '');
                $body['over'][$k] = $f['now'] ?? null;
                if (($f['yours'] ?? null) !== null) {
                    $body[$k] = $f['yours'];
                }
            }
            return $this->sendSingle($last['action'], $body, $id, $gid, $s0, $last);
        }
        $shown = ($step['shown'] ?? 'earlier') === 'now' || !isset($this->served[$gid]) ? $this->serve($id) : $this->served[$gid];
        // A delete names every field as shown, the time whole (T2: "if `over` names the time, it names it whole").
        $all = ['title', 'description', 'location', 'url', 'status', 'showAs', ...self::TIME_KEYS];
        $outdated = isset($step['outdated']) ? (string) $step['outdated'] : null;
        // `over`'s all-day boundaries with a clock on each, read as their dates (T4 narrowed).
        $clockOver = static function (array $body) use ($step): array {
            if (!empty($step['overClock']) && !empty($body['over']['allDay']) && isset($body['over']['start'], $body['over']['end'])) {
                foreach (['start', 'end'] as $k) {
                    $body['over'][$k] = substr((string) $body['over'][$k], 0, 10) . 'T15:00:00+00:00';
                }
            }
            return $body;
        };
        if ($how === 'delete') {
            $body = $clockOver(['over' => array_intersect_key($shown, array_flip($all))]);
            return $this->sendSingle('delete', $outdated === null ? $body : self::outdated($body, $outdated, $shown), $id, $gid, $s0, null);
        }
        $other = (string) ($shown['status'] ?? '') === 'tentative' ? 'confirmed' : 'tentative';
        if ($how === 'maybe') {
            return $this->sendSingle('relationship', ['relationship' => $other === 'tentative' ? 'maybe' : 'planned', 'over' => ['status' => $shown['status'] ?? null]], $id, $gid, $s0, null);
        }
        $body = ['over' => []];
        foreach ((array) ($step['fields'] ?? []) as $f) {
            if ($f === 'time') {
                $t = (array) ($step['time'] ?? []);
                $time = self::timeRequest($shown, $t);
                if (!empty($t['clocks']) && $time['allDay']) {
                    // An all-day time sent with clocks on its boundaries: the same dates (T4 narrowed).
                    $time['start'] = $time['start'] . $t['clocks'];
                    $time['end'] = $time['end'] . $t['clocks'];
                }
                if (!empty($t['typed']) && !$time['allDay'] && $outdated === null) {
                    // A typed start half an hour before the start sent, no typed end: resolved, that start and the end sent.
                    $time['typed'] = ['start' => self::clockIn(self::instantOf((string) $time['start']) - 1800, (string) $time['tzid']), 'tz' => (string) $time['tzid']];
                }
                $body = $time + $body;
                $body['over'] += array_intersect_key($shown, array_flip(self::TIME_KEYS));
            } elseif ($f === 'status') {
                $body['status'] = $other;
                $body['over']['status'] = $shown['status'] ?? null;
            } elseif ($f === 'showAs') {
                // The other of the two from what was shown (1.5.8, #303).
                $body['showAs'] = (string) ($shown['showAs'] ?? 'busy') === 'free' ? 'busy' : 'free';
                $body['over']['showAs'] = $shown['showAs'] ?? null;
            } else {
                $body[$f] = self::SINGLE_VALUES[$f];
                $body['over'][$f] = $shown[$f] ?? null;
            }
        }
        if (!empty($step['tags'])) {
            $body['tagNames'] = ['single', "single-own-{$this->step}"];
        }
        $body = $clockOver($body);
        return $this->sendSingle('patch', $outdated === null ? $body : self::outdated($body, $outdated, $shown), $id, $gid, $s0, null);
    }

    /** Sends one w-single request; the harness's record of it is made before the product is called. */
    private function sendSingle(string $action, array $body, int $id, string $gid, array $s0, ?array $overwriting): array
    {
        $fields = [];
        foreach (['title', 'description', 'location', 'url', 'status', 'showAs'] as $f) {
            if (array_key_exists($f, $body)) {
                $fields[$f] = true;
            }
        }
        if (array_intersect(self::TIME_KEYS, array_keys($body)) !== []) {
            $fields['time'] = true;
        }
        if ($action === 'relationship') {
            $fields['status'] = true;
        }
        if (isset($body['tagNames'])) {
            $fields['tags'] = $body['tagNames'];
        }
        $this->lastAsked = ['kind' => $action === 'delete' ? 'single-del' : 'single', 'gid' => $gid, 'fields' => $action === 'delete' ? [] : $fields,
            'days' => [self::dayOf($s0['rows'][$id], null)], 'local' => false];
        $this->singleSent = ['action' => $action, 'body' => $body, 'id' => $id, 'gid' => $gid, 'overwriting' => $overwriting];
        $this->lastSingle = ['action' => $action, 'body' => $body, 'error' => null, 'extra' => []];
        try {
            match ($action) {
                // Through the request's own entry, as PATCH /events/:id takes it (EventsController: the body read on the
                // event's clock by fromViewer, where a time in parts is refused, then patch), not below it.
                'patch' => $this->events->patch($this->userId, $id, method_exists($this->events, 'fromViewer') ? $this->events->fromViewer($this->userId, $id, $body) : $body),
                'delete' => $this->events->deleteEvent($this->userId, $id, null, null, null, null, $body['over']),
                'relationship' => $this->events->setRelationship($this->userId, $id, (string) $body['relationship'], null, null, null, null, $body['over']),
            };
            $this->events->throwDeferred();
        } catch (HttpError $e) {
            $this->lastSingle['error'] = $e->errorCode;
            $this->lastSingle['extra'] = $e->extra;
            throw $e;
        }
        $answer = $action === 'delete' ? [] : $this->events->keptAnswer($this->userId);
        if ($action !== 'delete' && $this->db->scalar('SELECT id FROM events WHERE id = ? AND deleted_at IS NULL', [$id]) !== null) {
            $this->served[$gid] = $this->serve($id);
        }
        return ['asked' => $this->lastAsked, 'answer' => $answer];
    }

    private static function selected(array $plan): array
    {
        $c = [];
        foreach ($plan['groups'] ?? [] as $g) {
            $c[$g['id']] = $g['selected'];
        }
        return $c;
    }

    /** A marked day of a master as the series stands now: [key, date], by the date the mark recorded; null when it has none. */
    private function markedOccurrence(array $master, string $mark): ?array
    {
        $date = $this->marks[$mark]['date'] ?? null;
        if ($date === null) {
            return null;
        }
        $key = self::days($master, 60)[$date] ?? null;
        return $key === null ? null : [$key, $date];
    }

    /**
     * x-day (day shapes): the step acted on the marked day, by its calendar date, and on no neighbouring day. A redo or
     * undo of a day's tags or notes puts them on the row of that date and on no row of the day before or after; of a
     * delete, skips that date and neither neighbour; a redo over notes given since is refused as stale with nothing
     * written or sent. The one accepted refusal (mayRefuse: a delete's redo or undo after a change of form or zone a poll
     * brought) is a refusal with nothing written or sent. After a flip stopped before its master write and a change made
     * at Google meanwhile in the old form: Finish completes, the plan's edited day keeps its own notes on its own date,
     * the day changed at Google holds the plan's result there (nothing of the change made at Google, on it or on a
     * neighbour), and the change is named once by its date; Put back is refused with nothing written or sent.
     */
    private function judgeDay(string $do, array $judge, array $out, array $s0, array $s1): void
    {
        $mark = $this->marks[$judge['mark']] ?? null;
        $context = ['step' => $this->step, 'do' => $do, 'expect' => $judge['expect'], 'mark' => $mark];
        if ($mark === null) {
            $this->ran('x-day', 'no mark');
            return;
        }
        $ms = array_values(self::masters($s1['rows']));
        $master = $ms[0] ?? null;
        if ($master === null) {
            $this->fail('x-day', "step {$this->step} ($do): the series is gone", $context);
            return;
        }
        $date = $mark['date'];
        if (isset($this->remoteHidden[$date])) {
            // A day Google hid when another client switched the series' form at such a time: Google's own fault, filed
            // (issue 266) and not built for, so nothing is asserted about it.
            $this->ran('x-day', 'hidden by a switch at Google');
            return;
        }
        $near = [Days::date(Days::ofDate($date) - 1), Days::date(Days::ofDate($date) + 1)];
        $mid = (int) $master['id'];
        $dayRows = static function (string $d) use ($s1, $master, $mid): array {
            return array_values(array_filter($s1['rows'], static fn(array $r): bool => (int) ($r['recurrence_parent_id'] ?? 0) === $mid && self::dayOf($r, $master) === $d));
        };
        $skipped = static function (string $d) use ($master): bool {
            foreach (Recurrence::decodeExdates($master['exdates_json'] ?? null) as $k) {
                $at = (int) $master['all_day'] === 1 ? substr($k, 0, 10) : Clock::local($k, (string) $master['tzid'])->format('Y-m-d');
                if ($at === $d) {
                    return true;
                }
            }
            return false;
        };
        $wrote = array_values(array_filter($this->writesBetween($s0, $s1), static fn(array $q): bool => !in_array('X-Harness: remote', (array) ($q['headers'] ?? []), true)));
        $quiet = self::sameHere($s0, $s1) && $wrote === [];
        // A switch refused for hidden days re-placed nothing: the day is judged as if no switch had been asked.
        $anchor = (string) ($judge['anchor'] ?? 'none');
        if ($anchor === 'none' && $this->flipRefused) {
            $this->ran('x-day', 'switch refused, nothing re-placed');
            $anchor = 'row';
        }
        if ($judge['expect'] !== 'stopped' && $anchor === 'none') {
            // The gate, before staleness and whatever was changed since: a day with no row and no skip when its series was
            // re-placed has no recorded place, and is never placed by its key or its date.
            $this->ran('x-day', 'unplaced');
            $this->expect('x-day', !$out['ok'] && $out['error'] === 'not_undoable' && $quiet,
                "step {$this->step} ($do): the undo or redo of a change to a day that had no row and no skip when its series was re-placed is refused (not_undoable), with nothing written here or sent",
                $context + ['out' => $out['error'] ?? 'went through', 'message' => $out['message'] ?? null, 'sent' => count($wrote)]);
            return;
        }
        if ($judge['expect'] === 'stale') {
            // The question when the redo's entry left the day a row (the shape's own anchor: a day kept by its tag); a redo of an
            // undo that removed the row has no base and keeps the stale check (Slice 3a, the unlikely list).
            if ($this->choiceOn && ($judge['anchor'] ?? 'none') === 'row') {
                // With the conflict choice on, newer notes on the day are a field the undo of an edit compares (Slice 3a,
                // case 6 and (d)): the redo asks, nothing sent and nothing redone; x-choice judges the question.
                $this->ran('x-day', 'asked over newer notes');
                $this->expect('x-day', !$out['ok'] && $out['error'] === 'google_changed' && $wrote === [],
                    "step {$this->step} ($do): a redo over notes given to the same day since asks (google_changed), with nothing sent", $context + ['out' => $out['error'], 'sent' => count($wrote)]);
                return;
            }
            $this->ran('x-day', 'stale');
            $this->expect('x-day', !$out['ok'] && $out['error'] === 'stale_undo' && $quiet,
                "step {$this->step} ($do): a redo over notes given to the same day since is refused as stale, with nothing written here or sent", $context + ['out' => $out['error'], 'sent' => count($wrote)]);
            return;
        }
        if ($judge['expect'] === 'stopped' && $do === 'putback') {
            // Put back after a change made at Google since: refused (google_changed_since), Finish offered, nothing sent.
            $this->ran('x-day', 'stopped put back');
            $this->expect('x-day', !$out['ok'] && $quiet, "step {$this->step} (putback): Put back after a change at Google since is refused, with nothing written here or sent",
                $context + ['out' => $out['error'], 'sent' => count($wrote)]);
            return;
        }
        if (!$out['ok'] && $do === 'finish' && $out['error'] === 'google_hidden_days' && $this->finishHides()) {
            // Finish of a switch Google would hide days with is refused as the switch itself is; x-finish judges the rest.
            $this->ran('x-day', 'finish refused for hidden days');
            return;
        }
        if (!$out['ok']) {
            $this->ran('x-day', 'refused');
            $this->fail('x-day', "step {$this->step} ($do): refused, though the journal says exactly where the day is (its own row is still there, or the change carried its skip)", $context + ['out' => $out['error'], 'message' => $out['message'] ?? null]);
            return;
        }
        $this->ran('x-day', $judge['expect']);
        if ($judge['expect'] === 'day') {
            $this->expect('x-day', !$skipped($date), "step {$this->step} ($do): the day is a day again on its own date", $context + ['skips' => $master['exdates_json']]);
            foreach ($near as $n) {
                $this->expect('x-day', !$skipped($n), "step {$this->step} ($do): the day before or after is not skipped", $context + ['near' => $n, 'skips' => $master['exdates_json']]);
            }
            return;
        }
        if ($judge['expect'] === 'stopped') {
            $this->judgeStopped($do, $mark, $master, $dayRows, $skipped, $near, $out, $s0, $s1, $context);
            return;
        }
        $want = $judge['expect'] === 'tags' ? (string) (array_values(array_diff((array) ($mark['fields']['tags'] ?? []), ['sample']))[0] ?? '') : (string) ($mark['fields']['description'] ?? '');
        $has = static function (array $rows) use ($judge, $want): bool {
            foreach ($rows as $r) {
                if ($judge['expect'] === 'tags' ? in_array($want, (array) $r['tags'], true) : (string) $r['description'] === $want) {
                    return true;
                }
            }
            return false;
        };
        if ($judge['expect'] === 'skip') {
            $this->expect('x-day', $skipped($date) && $dayRows($date) === [], "step {$this->step} ($do): the day is skipped on its own date", $context + ['skips' => $master['exdates_json']]);
            foreach ($near as $n) {
                $this->expect('x-day', !$skipped($n), "step {$this->step} ($do): the day before or after is not skipped", $context + ['near' => $n, 'skips' => $master['exdates_json']]);
            }
            return;
        }
        $this->expect('x-day', $has($dayRows($date)), "step {$this->step} ($do): the day's own " . $judge['expect'] . " are back on its own date", $context + ['want' => $want, 'rows' => array_map(static fn(array $r): array => [$r['recurrence_instance_utc'], $r['description'], $r['tags']], $dayRows($date))]);
        foreach ($near as $n) {
            $this->expect('x-day', !$has($dayRows($n)), "step {$this->step} ($do): the day before or after has none of them", $context + ['near' => $n, 'want' => $want]);
        }
    }

    /** Whether finishing the switch asked here would hide a day at Google now, by the harness's own reading. */
    private function finishHides(): bool
    {
        foreach ($this->flipTarget as $gid => $target) {
            if ($this->stand->hidesOnSwitch((string) $gid, $target)) {
                return true;
            }
        }
        return false;
    }

    /** x-day after a flip stopped before its master write landed, a change at Google meanwhile, then Finish or Put back. */
    private function judgeStopped(string $do, array $mark, array $master, \Closure $dayRows, \Closure $skipped, array $near, array $out, array $s0, array $s1, array $context): void
    {
        if ($do === 'putback') {
            $wrote = array_values(array_filter($this->writesBetween($s0, $s1), static fn(array $q): bool => !in_array('X-Harness: remote', (array) ($q['headers'] ?? []), true)));
            $this->expect('x-day', $wrote === [], "step {$this->step} (putback): Put back after a change at Google since sends nothing", $context + ['sent' => count($wrote)]);
            return;
        }
        $edited = $this->marks['e'] ?? null;
        $notes = (string) ($edited['fields']['description'] ?? '');
        if ($edited !== null) {
            $own = array_filter($dayRows($edited['date']), static fn(array $r): bool => (string) $r['description'] === $notes);
            $this->expect('x-day', $own !== [], "step {$this->step} (finish): the plan's edited day keeps its own notes on its own date", $context + ['edited' => $edited['date'], 'notes' => $notes]);
        }
        $date = $mark['date'];
        $remote = static function (array $rows) use ($mark): bool {
            foreach ($rows as $r) {
                if (str_starts_with((string) $r['title'], 'Sample remote day') || str_starts_with((string) $r['description'], 'Sample remote notes')) {
                    return true;
                }
            }
            return false;
        };
        $this->expect('x-day', !$remote($dayRows($date)) && ($mark['kind'] !== 'r-day-delete' || !$skipped($date)),
            "step {$this->step} (finish): the day changed at Google since holds the plan's result on its own date, not the change made there", $context + ['date' => $date]);
        foreach ($near as $n) {
            $this->expect('x-day', !$remote($dayRows($n)) && ($mark['kind'] !== 'r-day-delete' || !$skipped($n) || $n === ($edited['date'] ?? '')),
                "step {$this->step} (finish): nothing of the change made at Google lands on the day before or after", $context + ['near' => $n]);
        }
        // Named once, by the date the day had when it was changed (D j M, the year when not this year).
        $words = (new \DateTimeImmutable($date))->format(substr($date, 0, 4) === gmdate('Y') ? 'D j M' : 'D j M Y');
        $text = '';
        foreach ($this->db->all('SELECT summary, details_json FROM mutations WHERE user_id = ? AND id > ? ORDER BY id', [$this->userId, (int) ($s0['mut'] ?? 0)]) as $m) {
            $text .= ' ' . $m['summary'] . ' ' . $m['details_json'];
        }
        $text .= ' ' . json_encode($out, JSON_UNESCAPED_UNICODE);
        $this->expect('x-day', str_contains($text, $words . ' was '), "step {$this->step} (finish): the day changed at Google since is named by its date as not kept", $context + ['words' => $words]);
    }

    /** A write with a failure injected at its k-th write to Google. */
    private function failing(string $do, array $step, array $s0): array
    {
        $knobs = $this->stand->knobs;
        $knobs->{['f-lost' => 'loseAt', 'f-before' => 'beforeAt', 'f-fatal' => 'fatalAt', 'f-net' => 'netAt'][$do]} = (int) ($step['k'] ?? 1);
        try {
            return $this->write((string) $step['write'], ['do' => $step['write']] + $step, $s0);
        } finally {
            $knobs->loseAt = $knobs->beforeAt = $knobs->fatalAt = $knobs->netAt = null;
            $knobs->down = false;
        }
    }

    /** A change made at Google by someone else, through the stand-in's own API (no If-Match), marked as the remote client's. */
    private function remote(string $do, array $step, array $s0): array
    {
        if ($do === 'r-single') {
            return $this->remoteSingle($step);
        }
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
        $instance = $occ === null ? null : GoogleWriter::instanceId($gid, $occ[0], $allDay);
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
            case 'r-flip':
                if ($allDay) {
                    $z = (string) ($this->spec['series']['flipZone'] ?? 'UTC');
                    $c = (int) ($this->spec['series']['flipClock'] ?? 0);
                    $t = Clock::at(Days::ofDate((string) $g['start']['date']), $c, $z);
                    $body = ['start' => ['dateTime' => $t->format('Y-m-d\TH:i:sP'), 'timeZone' => $z], 'end' => ['dateTime' => Clock::plus($t, 3600)->format('Y-m-d\TH:i:sP'), 'timeZone' => $z]];
                } else {
                    $d = (new \DateTimeImmutable((string) $g['start']['dateTime']))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
                    $body = ['start' => ['date' => $d], 'end' => ['date' => Days::date(Days::ofDate($d) + 1)]];
                }
                // The days Google hides when the form changes at such a time (issue 266, not built for): read before the
                // switch drops the records, by the harness's own reading, and named by the date each shows on.
                $to = isset($body['start']['date'])
                    ? ['start_utc' => $body['start']['date'] . ' 00:00:00', 'end_utc' => $body['end']['date'] . ' 00:00:00', 'all_day' => 1, 'tzid' => 'UTC']
                    : ['start_utc' => Clock::db(new \DateTimeImmutable($body['start']['dateTime'])), 'end_utc' => Clock::db(new \DateTimeImmutable($body['end']['dateTime'])), 'all_day' => 0, 'tzid' => (string) $body['start']['timeZone']];
                foreach ($this->stand->hiddenOnSwitch($gid, $to + ['rrule' => $rule]) as $k) {
                    $this->remoteHidden[$to['all_day'] === 1 ? substr($k, 0, 10) : Clock::local($k, $to['tzid'])->format('Y-m-d')] = true;
                }
                // Set whole, as another client changes the form at Google (the probe of 2026-11-10, 3.19 to 3.29).
                $body = \BetterCal\GoogleProbe\FakeGoogle::asSet($body);
                [$target, $touched] = [$gid, [$touch('time'), $touch('allDay')]];
                break;
            case 'r-day-rename':
            case 'r-day-delete':
            case 'r-day-time':
            case 'r-day-notes':
                // A day as Google lists it now, in Google's form: the plan's edited day for notes, else another day.
                $items = json_decode((string) ($this->stand->fake->transport()('GET', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid) . '/instances',
                    ['Authorization: Bearer sample-access', 'X-Harness: remote'], null)['body'] ?? ''), true)['items'] ?? [];
                $dateOf = static fn(array $i): string => isset($i['start']['date']) ? (string) $i['start']['date'] : (new \DateTimeImmutable((string) $i['start']['dateTime']))->setTimezone(new \DateTimeZone((string) ($i['start']['timeZone'] ?? $tz)))->format('Y-m-d');
                $edited = $this->marks['e']['date'] ?? null;
                $pool = array_values(array_filter($items, static fn(array $i): bool => $do === 'r-day-notes' ? $dateOf($i) === $edited : $dateOf($i) !== $edited));
                if ($pool === [] || ($do === 'r-day-time' && $allDay)) {
                    return ['skip' => true];
                }
                $it = $pool[min(count($pool) - 1, (int) floor($step['pick'] * count($pool)))];
                $date = $dateOf($it);
                $body = match ($do) {
                    'r-day-rename' => ['summary' => "Sample remote day $n"],
                    'r-day-delete' => ['status' => 'cancelled'],
                    'r-day-time' => ['start' => $shift($it['start'], 7200), 'end' => $shift($it['end'], 7200)],
                    'r-day-notes' => ['description' => "Sample remote notes $n"],
                };
                $this->marks[(string) ($step['mark'] ?? 'g')] = ['date' => $date, 'fields' => [], 'kind' => $do, 'body' => $body];
                [$target, $touched] = [(string) $it['id'], [$touch($do === 'r-day-delete' ? 'skip' : ($do === 'r-day-time' ? 'time' : ($do === 'r-day-notes' ? 'description' : 'title')), null)]];
                break;
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
                if ($instance !== null && !empty($step['moved'])) {
                    // The day's own time moved too, two hours later (a day later for an all-day series), its length kept.
                    $len = (new \DateTimeImmutable((string) ($g['end']['dateTime'] ?? $g['end']['date'])))->getTimestamp() - (new \DateTimeImmutable((string) ($g['start']['dateTime'] ?? $g['start']['date'])))->getTimestamp();
                    $at = Clock::parse((string) $occ[0])->getTimestamp() + ($allDay ? 86400 : 7200);
                    $body += $allDay ? ['start' => ['date' => gmdate('Y-m-d', $at)], 'end' => ['date' => gmdate('Y-m-d', $at + $len)]]
                        : ['start' => ['dateTime' => self::inZone($at, $tz), 'timeZone' => $tz], 'end' => ['dateTime' => self::inZone($at + $len, $tz), 'timeZone' => $tz]];
                    $touched[] = $touch('time', $occ[0]);
                }
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
                    'guestsCanModify' => ['guestsCanModify' => true],
                    'visibility' => ['visibility' => 'private'],
                    default => ['colorId' => (string) (1 + $n % 11)],
                };
                $touched = [$touch('gonly')]; // no field the rows here hold, for x-conflict's r-gonly count
                break;
            case 'r-free':
                // Free or busy changed at Google by another client (1.5.8, #303): a field the rows here hold (show_as), so a
                // change to the series is a conflict until polled, as a rename is. On the series, or on one day of it (an
                // exception whose only difference is its marking), set to the other of what Google holds there.
                $onDay = $step['pick'] > 0.5 && $instance !== null;
                $target = $onDay ? $instance : $gid;
                $there = $onDay ? ($this->stand->fake->events[$instance] ?? $g) : $g;
                $body = ['transparency' => GoogleStand::showAsOf($there) === 'free' ? 'opaque' : 'transparent'];
                $touched = [$touch('showAs', $onDay ? $occ[0] : null)];
                break;
            case 'r-etag':
                $this->stand->fake->seed($g);
                $this->etagOnly[$gid] = true;
                return ['asked' => ['kind' => 'remote', 'gid' => $gid, 'fields' => [], 'days' => []]];
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
        // The series as Google holds it, less what Google rewrites on its own, before and after: a change that leaves it
        // as it was (a day put back to what it already held) moved only the etag.
        $resource = function () use ($gid): array {
            $out = [];
            foreach ($this->stand->fake->events as $id => $e) {
                if ((string) $id === $gid || str_starts_with((string) $id, $gid . '_') || ($e['recurringEventId'] ?? '') === $gid) {
                    $out[$id] = array_diff_key($e, array_flip(['etag', 'updated', 'sequence']));
                }
            }
            ksort($out);
            return $out;
        };
        $was = $resource();
        $answer = ($this->stand->fake->transport())($do === 'r-delete' ? 'DELETE' : 'PATCH', $url, ['Authorization: Bearer sample-access', 'X-Harness: remote'], $body);
        if ($answer['status'] >= 300) {
            $this->na["$do refused by the stand-in: HTTP {$answer['status']}"] = 1;
            return ['skip' => true];
        }
        if ($resource() == $was) {
            $this->etagOnly[$gid] = true;
            return ['asked' => ['kind' => 'remote', 'gid' => $gid, 'fields' => [], 'days' => []]];
        }
        if ($do === 'r-gonly') {
            // A change at Google only in fields the rows here don't hold: no conflict for a write, whose body never carries
            // them (decisions.md, "A write is conditional on what is shown here; Put back on everything"); Put back still
            // counts it (the pending change's since).
            $this->etagOnly[$gid] = true;
        } else {
            $this->dirty[$gid] = true;
        }
        foreach ($touched as $t) {
            $this->touches[] = ['step' => $this->step, 'gid' => $gid, 'remote' => true, 'polled' => false] + $t;
        }
        foreach ($this->pendings as $id => $p) {
            $this->pendings[$id]['since'][$gid] = true;
        }
        return ['asked' => ['kind' => 'remote', 'gid' => $gid, 'fields' => [], 'days' => []]];
    }

    /**
     * A change at Google by another client to the case's event in no series (withSingle): each field named set to a
     * value of Google's own, or, for a field in `same`, to the value the change here will set (the time the whole time
     * the change here sends, remoteTime). Status has two values, so Google's change of it is
     * always to tentative.
     */
    private function remoteSingle(array $step): array
    {
        $gid = $this->single;
        $g = $gid === null ? null : ($this->stand->fake->events[$gid] ?? null);
        if ($g === null || ($g['status'] ?? '') === 'cancelled') {
            return ['skip' => true];
        }
        $n = $this->step;
        $same = (array) ($step['same'] ?? []);
        $body = [];
        $touched = [];
        foreach ((array) ($step['fields'] ?? []) as $f) {
            $mine = in_array($f, $same, true);
            switch ($f) {
                case 'title':
                    $body['summary'] = $mine ? self::SINGLE_VALUES['title'] : "Sample remote single title $n";
                    break;
                case 'description':
                case 'location':
                    $body[$f] = $mine ? self::SINGLE_VALUES[$f] : "Sample remote single $f $n";
                    break;
                case 'url':
                    $body['source'] = ['url' => $mine ? self::SINGLE_VALUES['url'] : "https://example.test/single-remote-$n", 'title' => 'Sample link'];
                    break;
                case 'status':
                    $body['status'] = 'tentative';
                    break;
                case 'showAs':
                    // Two values: Google's change is to the other one, which is also what a change here sets.
                    $body['transparency'] = GoogleStand::showAsOf($g) === 'free' ? 'opaque' : 'transparent';
                    break;
                case 'time':
                    $time = $this->remoteTime($g, $step, $mine);
                    if ($time === []) {
                        continue 2;
                    }
                    $body += $time;
                    break;
            }
            $touched[] = $f;
        }
        if ($body === []) {
            return ['skip' => true];
        }
        $answer = ($this->stand->fake->transport())('PATCH', 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($gid),
            ['Authorization: Bearer sample-access', 'X-Harness: remote'], $body);
        if ($answer['status'] >= 300) {
            $this->na["r-single refused by the stand-in: HTTP {$answer['status']}"] = 1;
            return ['skip' => true];
        }
        $this->dirty[$gid] = true;
        foreach ($touched as $f) {
            $this->touches[] = ['step' => $this->step, 'gid' => $gid, 'remote' => true, 'polled' => false, 'field' => $f, 'key' => null];
        }
        return ['asked' => ['kind' => 'remote', 'gid' => $gid, 'fields' => [], 'days' => []]];
    }

    /**
     * Google's change of the event's time (remoteSingle): with `same`, to the whole time the change here will set (the
     * request the w-single step builds from the record shown when the event was made), in its zone; else by the step's
     * `time.how`: shifted whole (two hours, or a day for an all-day event), its start earlier (an hour, a day), its end
     * later, its kind changed (a timed event made all-day from the date its start shows in its zone over `time.days`
     * days; an all-day event made timed at 18:00 to 19:00 in `time.zone` on its first date), or its zone alone (the
     * instants kept, written in `time.zone`, or the next zone where that is the event's own; an all-day event has no zone
     * and has its kind changed instead). A timed value is written in its zone's offset with that `timeZone`, as Google
     * holds one another client set; a change of kind is set whole (FakeGoogle::asSet).
     */
    private function remoteTime(array $g, array $step, bool $mine): array
    {
        $allDay = isset($g['start']['date']);
        $at = static fn(array $w): int => self::instantOf((string) ($w['date'] ?? $w['dateTime'] ?? '1970-01-01'));
        $s = $at((array) $g['start']);
        $e = $at((array) $g['end']);
        $zone = (string) ($g['start']['timeZone'] ?? 'UTC');
        $toAllDay = $allDay;
        if ($mine) {
            $shown = $this->served[(string) $this->single] ?? null;
            if ($shown === null) {
                return [];
            }
            $want = self::timeRequest($shown, (array) ($step['mineTime'] ?? []));
            [$s, $e, $toAllDay] = self::timeOf($want);
            if (!empty($step['mineTime']['typed']) && !$toAllDay) {
                $s -= 1800; // the typed start the request resolves to (writeSingle)
            }
            $zone = (string) $want['tzid'];
        } else {
            $time = (array) ($step['time'] ?? []);
            $other = (string) ($time['zone'] ?? 'UTC');
            $how = (string) ($time['how'] ?? 'shift');
            $how = $how === 'zone' && $allDay ? 'kind' : $how;
            switch ($how) {
                case 'start':
                    $s -= $allDay ? 86400 : 3600;
                    break;
                case 'end':
                    $e += $allDay ? 86400 : 3600;
                    break;
                case 'kind':
                    $toAllDay = !$allDay;
                    if ($toAllDay) {
                        $day = self::instantOf(self::localDate($s, $zone));
                        [$s, $e] = [$day, $day + 86400 * max(1, (int) ($time['days'] ?? 1))];
                    } else {
                        $date = gmdate('Y-m-d', $s);
                        [$s, $e, $zone] = [self::wall($date, '18:00', $other), self::wall($date, '19:00', $other), $other];
                    }
                    break;
                case 'zone':
                    $zone = $other !== $zone ? $other : self::SINGLE_ZONES[((int) array_search($zone, self::SINGLE_ZONES, true) + 1) % count(self::SINGLE_ZONES)];
                    break;
                default:
                    $s += $allDay ? 86400 : 7200;
                    $e += $allDay ? 86400 : 7200;
            }
        }
        $when = static fn(int $t): array => $toAllDay ? ['date' => gmdate('Y-m-d', $t)] : ['dateTime' => self::inZone($t, $zone), 'timeZone' => $zone];
        $body = ['start' => $when($s), 'end' => $when($e)];
        return $toAllDay !== $allDay ? \BetterCal\GoogleProbe\FakeGoogle::asSet($body) : $body;
    }

    private function poll(string $do, array $step = [], array $s0 = []): array
    {
        $calendar = $this->stand->calendarId;
        if ($do === 'p-empty') {
            // A whole read (or a 410 reset, then a whole read) that lists nothing for a calendar holding rows.
            if (($s0['rows'] ?? []) === []) {
                return ['skip' => true];
            }
            $this->stand->knobs->emptyNext = true;
            $this->db->run('UPDATE calendars SET google_sync_token = ? WHERE id = ?', [($step['pick'] ?? 0) < 0.5 ? null : 'stale-token', $calendar]);
            try {
                $this->stand->poll();
            } finally {
                $this->stand->knobs->emptyNext = false;
            }
            return ['asked' => ['kind' => 'poll', 'gid' => null, 'fields' => [], 'days' => []]];
        }
        if ($do === 'p-overlap') {
            // A write here (a tag on an ordinary day) while the poll fetches Google's list.
            $master = $this->seriesOf($s0['rows'] ?? [], (float) ($step['pick'] ?? 0));
            $occ = $master === null ? null : $this->occurrenceOf($master, fmod((float) $step['pick'] * 5.0, 1.0));
            if ($occ === null) {
                return ['skip' => true];
            }
            $tag = "overlap-{$this->step}";
            $events = $this->events;
            $user = $this->userId;
            $inst = self::instanceArg($master, $occ[0]);
            $mid = (int) $master['id'];
            $note = (object) ['refused' => null];
            $this->overlap = [$occ[0], $tag, (string) $master['uid'], $note, $occ[1], $mid];
            // The tag given during the fetch is this step's own request (and its Activity entry is this step's), recorded
            // before the poll runs so a poll that refuses its listing still knows what the step scheduled.
            $asked = ['kind' => 'this', 'gid' => (string) $master['google_event_id'], 'key' => $occ[0], 'fields' => ['tags' => ['sample', $tag]], 'days' => [$occ[1]], 'local' => true];
            $this->lastAsked = $asked;
            $this->stand->knobs->duringFetch = static function () use ($events, $user, $mid, $inst, $tag, $note): void {
                try {
                    $events->patch($user, $mid, ['scope' => 'this', 'instanceStart' => $inst, 'tagNames' => ['sample', $tag]]);
                    $events->throwDeferred();
                } catch (\Throwable $e) {
                    $note->refused = $e instanceof HttpError ? $e->errorCode : get_class($e);
                }
            };
            try {
                $this->stand->poll();
            } finally {
                $this->stand->knobs->duringFetch = null;
            }
            return ['asked' => $asked];
        }
        if ($do === 'p-410-pending') {
            if ($this->pendings === []) {
                return ['skip' => true];
            }
            $do = 'p-410';
        }
        if ($do === 'p-budget') {
            // A whole read stopped by the page budget (one page allowed, two items a page), then completed.
            $this->db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$calendar]);
            $pages = \BetterCal\Support\Limits::get('GOOGLE_SYNC_PAGES');
            $this->stand->knobs->pageSize = 2;
            \BetterCal\Support\Limits::configure(['GOOGLE_SYNC_PAGES' => 1]);
            $stopped = false;
            try {
                $this->stand->poll();
            } catch (\Throwable $e) {
                // The stopped read: its reason recorded on the calendar, nothing applied.
                $stopped = true;
            } finally {
                $this->stand->knobs->pageSize = null;
                \BetterCal\Support\Limits::configure(['GOOGLE_SYNC_PAGES' => $pages]);
            }
            $this->budgetMid = $stopped ? $this->here() : null;
            if (!$stopped) {
                $this->ran('x-budget', 'read fit one page');
            }
            $this->db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$calendar]);
            $this->stand->poll();
            return ['asked' => ['kind' => 'poll', 'gid' => null, 'fields' => [], 'days' => []]];
        }
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

    /** A rename at Google of $instance, run right after the app's next accepted write (the stand's race knob). */
    private function armRace(string $instance, string $title): void
    {
        $fake = $this->stand->fake;
        $this->stand->knobs->race = static function () use ($fake, $instance, $title): void {
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(GoogleStand::CALENDAR) . '/events/' . rawurlencode($instance);
            ($fake->transport())('PATCH', $url, ['Authorization: Bearer sample-access', 'X-Harness: remote'], ['summary' => $title]);
        };
    }

    private function undoStep(string $do, array $step): array
    {
        // "race": "day" (fixed cases): the day the last w-race renamed is renamed at Google again right after the undo's
        // first accepted write, before its read-back.
        if (($step['race'] ?? null) === 'day' && $this->lastRace !== null) {
            [$gid, $key, $instance] = $this->lastRace;
            $title = "Sample remote day {$this->step}";
            $this->raced = [$gid, $key, $title];
            $this->armRace($instance, $title);
        }
        try {
            return $this->undoOrRedo($do, $step);
        } finally {
            $this->stand->knobs->race = null;
        }
    }

    private function undoOrRedo(string $do, array $step): array
    {
        $entries = $this->db->all('SELECT id, source, details_json, undone FROM mutations WHERE user_id = ? ORDER BY id DESC', [$this->userId]);
        if ($do === 'u-bare') {
            $expect = null;
            // Passed over: an undo's own entry. A redo's entry (one that undoes an undo's) is a change of its own, so the bare
            // Undo after an edit, its undo and a redo takes the redo back (decisions.md, "The bare Undo steps one change further
            // back on a Google calendar as it does locally", settled 2026-11-09).
            // With google_series_undo off (Product::$seriesUndo), the product's default in 1.5.5, an entry of kind series is
            // passed over too (decisions.md, "Undo of a whole-series change on a Google calendar waits for 1.5.6").
            $passedOver = fn(array $e): bool => $this->hops((int) $e['id']) % 2 === 1 || $this->heldBack((int) $e['id']);
            foreach ($entries as $e) {
                if ((int) $e['undone'] === 0 && in_array($e['source'], ['web', 'quickadd', 'review', 'rsvp', 'dedup:owner'], true) && !$passedOver($e)) {
                    $expect = (int) $e['id'];
                    break;
                }
            }
            if ($expect === null) {
                return ['skip' => true];
            }
            $this->lastAsked = $this->askedOfEntry($expect) + ['entry' => $expect];
            $answer = $this->undo->undoLatest($this->userId);
            return ['entry' => $expect, 'asked' => $this->lastAsked, 'answer' => $answer];
        }
        if (isset($step['entryOf'])) {
            // A day shape's undo of the entry a marked step made (not the newest, which is the series' change since).
            $id = (int) ($this->marks[(string) $step['entryOf']]['entry'] ?? 0);
            if ($id === 0) {
                return ['skip' => true];
            }
            $this->lastAsked = $this->askedOfEntry($id) + ['entry' => $id];
            $answer = $this->undo->undoById($this->userId, $id);
            return ['entry' => $id, 'asked' => $this->lastAsked, 'answer' => $answer];
        }
        $pool = array_values(array_filter($entries, static fn(array $e): bool => (int) $e['undone'] === 0
            && ($do === 'redo' ? self::undoes($e['details_json']) !== null : !in_array($e['source'], ['feed', 'google', 'system'], true))));
        if ($pool === []) {
            return ['skip' => true];
        }
        $id = (int) $pool[min(count($pool) - 1, (int) floor($step['pick'] * count($pool)))]['id'];
        $this->lastAsked = $this->askedOfEntry($id) + ['entry' => $id];
        $answer = $this->undo->undoById($this->userId, $id);
        return ['entry' => $id, 'asked' => $this->lastAsked, 'answer' => $answer];
    }

    /**
     * Whether the account's setting holds this entry's undo back: google_series_undo off (as the run made the account,
     * Product::account, read from the account's stored settings) and the entry of kind series, as the journal stores it.
     */
    private function heldBack(int $entry): bool
    {
        $settings = json_decode((string) $this->db->scalar('SELECT settings_json FROM users WHERE id = ?', [$this->userId]), true);
        if (($settings['google_series_undo'] ?? false) === true) {
            return false;
        }
        $d = json_decode((string) $this->db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$entry]), true);
        return is_array($d) && (($d['google']['kind'] ?? null) === 'series');
    }

    /** How many undo hops an entry is from the change at the bottom of its chain: 0 a change, 1 its undo, 2 a redo, and so on. */
    private function hops(int $entry): int
    {
        $n = 0;
        $seen = [];
        while (($of = self::undoes((string) $this->db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$entry]))) !== null && !isset($seen[$of])) {
            $seen[$of] = true;
            $entry = $of;
            $n++;
        }
        return $n;
    }

    /**
     * Every row of the case by its identity, so rows compare across steps whatever ids a store gives them: a series by
     * its Google id, a day of a series by that and its key, an event of its own by its Google id.
     *
     * @return array<string,array> identity => the row's comparable state, with its id
     */
    private function byIdentity(array $rows): array
    {
        $out = [];
        $l = $this->lstate($rows);
        foreach ($rows as $id => $r) {
            $parent = $r['recurrence_parent_id'] !== null ? ($rows[(int) $r['recurrence_parent_id']] ?? null) : null;
            $ident = match (true) {
                $parent !== null => 'day:' . $parent['google_event_id'] . ':' . $r['recurrence_instance_utc'],
                $r['rrule'] !== null => 'series:' . $r['google_event_id'],
                default => 'event:' . ($r['google_event_id'] ?? $id),
            };
            $out[$ident] = array_diff_key($l[$id], ['parent' => 1]) + ['id' => $id];
        }
        ksort($out);
        return $out;
    }

    /** The fields of a row whose values an undo is judged on: a series' or event's every field; a day's own and held-here ones. */
    private static function judged(string $ident, array ...$states): array
    {
        $all = ['title', 'description', 'location', 'url', 'status', 'showAs', 'start', 'end', 'allDay', 'tz', 'rrule', 'skips', 'tags', 'people', 'reminders', 'icon',
            'attendance', 'lat', 'lng', 'trip', 'floating', 'links', 'key', 'uid'];
        if (!str_starts_with($ident, 'day:')) {
            return $all;
        }
        $f = ['tags', 'people', 'reminders', 'icon', 'attendance', 'lat', 'lng', 'links', 'key', 'uid'];
        $own = [];
        foreach ($states as $st) {
            $own = array_merge($own, (array) ($st['own'] ?? []));
        }
        $map = ['title' => ['title'], 'description' => ['description'], 'location' => ['location'], 'url' => ['url'], 'status' => ['status'], 'showAs' => ['showAs'],
            'date' => ['start', 'end'], 'time' => ['start', 'end', 'tz'], 'length' => ['end'], 'allDay' => ['allDay']];
        foreach (array_unique($own) as $o) {
            $f = array_merge($f, $map[$o] ?? []);
        }
        return array_values(array_unique($f));
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
        if ($of !== null && $depth < 6) {
            $inner = $this->askedOfEntry($of, $depth + 1);
            $inner['kind'] = ($inner['kind'] ?? 'undo') === 'undo' ? 'redo' : 'undo';
            return $inner;
        }
        $d = json_decode((string) $this->db->scalar('SELECT details_json FROM mutations WHERE id = ?', [$entry]), true);
        $gid = isset($d['google']['googleId']) ? explode('_', (string) $d['google']['googleId'], 2)[0] : null;
        return ['kind' => 'undo', 'of' => null, 'gid' => $gid, 'fields' => [], 'days' => ['*']];
    }

    // ---- the checks -----------------------------------------------------------------------

    private function check(string $do, array $out, array $s0, array $s1): void
    {
        $asked = ($out['asked'] ?? null) ?? ['kind' => '?', 'gid' => null, 'fields' => [], 'days' => []];
        $writes = $this->writesBetween($s0, $s1);
        $taken = $this->takenBetween($s0, $s1);
        $entries = array_map('intval', array_column($this->db->all('SELECT id FROM mutations WHERE user_id = ? AND id > ? ORDER BY id', [$this->userId, $s0['mut']]), 'id'));
        $kind = substr($do, 0, 2);
        $failure = $kind === 'f-' ? $this->stand->knobs->fired : null;
        $isWrite = $kind === 'w-' || $kind === 'f-' || $kind === 'u-' || in_array($do, ['finish', 'putback', 'redo'], true);
        $gid = $asked['gid'] ?? null;
        $error = $out['ok'] ? null : (string) $out['error'];
        $context = ['step' => $this->step, 'do' => $do] + ($error === null ? [] : ['error' => $error, 'message' => $out['message'] ?? '']);
        // A change of this series left half done by an earlier step (checkFailure adds this step's own after).
        $onPending = $gid !== null && array_filter($this->pendings, static fn(array $p): bool => isset($p['gids'][$gid])) !== [];

        // A whole read that listed nothing for a calendar holding rows is refused by the poll (x-empty's expected outcome).
        $listedEmpty = $kind === 'p-' && $this->stand->knobs->listed === 'empty' && $s0['rows'] !== [];
        // A non-HTTP error from a step that injected none is the product failing to answer.
        if (!$out['ok'] && !empty($out['thrown']) && $failure === null && !$listedEmpty) {
            $this->fail('x-honest', "step {$this->step} ($do): the product threw $error instead of answering", $context);
        }

        // x-conditional: every PATCH and DELETE the app sends carries If-Match, but a day's PATCH inside a change to all
        // events, after the series' own conditional write or a recovery's read (decisions.md, "Inside a change to all
        // events a day is written with no condition"): a step that writes a series (a write to a non-instance id) or
        // recovers one (Finish, Put back), and the PATCH to one of its instance ids.
        $instance = static fn(array $q): bool => preg_match('/_\d{8}(T\d{6}Z)?$/', rawurldecode(basename((string) $q['path']))) === 1;
        $seriesRun = in_array($do, ['finish', 'putback'], true) || str_starts_with($do, 'f-')
            || array_filter($writes, static fn(array $q): bool => !$instance($q) && $q['method'] === 'PATCH' && preg_grep('/^If-Match:\s*\S/i', $q['headers']) !== []) !== [];
        foreach ($writes as $q) {
            $this->ran('x-conditional', $do);
            if ($seriesRun && $q['method'] === 'PATCH' && $instance($q)) {
                continue;
            }
            if (in_array($q['method'], ['PATCH', 'DELETE'], true) && preg_grep('/^If-Match:\s*\S/i', $q['headers']) === []) {
                $this->fail('x-conditional', "step {$this->step} ($do): a {$q['method']} went to Google without If-Match", $context + ['path' => rawurldecode(basename((string) $q['path']))]);
                break;
            }
        }
        // x-write-asked: no key outside the writer's list (OwnFields' fields Google holds, plus the resource's identity).
        foreach ($writes as $q) {
            $extra = array_values(array_diff(array_keys((array) $q['body']), self::WRITER_KEYS));
            if ($extra !== []) {
                $this->fail('x-write-asked', "step {$this->step} ($do): a write sent " . implode(', ', $extra) . ", outside the writer's list", $context + ['path' => rawurldecode(basename((string) $q['path']))]);
                break;
            }
        }
        // x-empty: an empty listing never deletes; it is not applied, Review shows one notice for the calendar, and the next
        // poll tries again; the next whole read that lists events closes the notice.
        $notices = (int) $this->db->scalar("SELECT COUNT(*) FROM review_items WHERE user_id = ? AND kind = 'google_empty' AND status = 'open'", [$this->userId]);
        if ($listedEmpty) {
            $this->ran('x-empty', 'listed nothing: ' . $do);
            $this->expect('x-empty', $notices === 1, "step {$this->step} ($do): a whole read that listed nothing leaves one open google_empty notice for the calendar", $context + ['notices' => $notices]);
        } elseif ($kind === 'p-' && $this->stand->knobs->listed === 'events') {
            $this->ran('x-empty', 'listed events');
            $this->expect('x-empty', $notices === 0, "step {$this->step} ($do): a whole read that listed events closes the calendar's google_empty notice", $context + ['notices' => $notices]);
        }
        if ($listedEmpty) {
            $this->ran('x-empty', $this->overlap !== null && $this->overlap[3]->refused === null ? 'empty read with a write during the fetch' : 'empty read');
            $want = $this->beforeAndScheduled($s0['here']['l'], $s1['here']['l']);
            $this->expect('x-empty', $want == $s1['here']['l'], "step {$this->step} ($do): a whole read that listed nothing for a calendar holding rows changed no row (the step's own write during the fetch aside)", $context + ['diff' => self::diff($want, $s1['here']['l'])]);
            $this->expect('x-empty', $s1['here']['token'] === $s0['here']['token'] || $s1['here']['token'] === null || $s1['here']['token'] === 'stale-token',
                "step {$this->step} ($do): an empty listing does not advance the sync position", $context + ['token' => $s1['here']['token']]);
        }
        // x-overlap: a write here while a poll fetches survives the poll.
        if ($do === 'p-overlap' && $this->overlap !== null) {
            [$key, $tag, $uid, $note, $date] = $this->overlap;
            $this->overlap = null;
            if ($note->refused !== null) {
                // The write itself refused (a series with changes not yet at Google refuses writes, A6): nothing to keep.
                $this->ran('x-overlap', 'write during fetch refused: ' . $note->refused);
            } else {
                $kept = false;
                $seriesNow = null;
                foreach (self::masters($s1['rows']) as $mr) {
                    if ((string) $mr['uid'] === $uid) {
                        $seriesNow = $mr;
                    }
                }
                $dayNow = $seriesNow !== null ? (self::days($seriesNow, 200)[$date] ?? null) : null;
                if ($dayNow === null || in_array($dayNow, Recurrence::decodeExdates($seriesNow['exdates_json'] ?? null), true)) {
                    $kept = true; // the series no longer has the day: x-alone judges its removal
                    $this->ran('x-overlap', 'day gone from the series');
                }
                foreach ($s1['rows'] as $r) {
                    $m = $r['recurrence_parent_id'] !== null ? ($s1['rows'][(int) $r['recurrence_parent_id']] ?? null) : null;
                    if ((string) $r['uid'] === $uid && in_array($tag, $r['tags'], true) && self::dayOf($r, $m) === $date) {
                        $kept = true;
                    }
                }
                $this->ran('x-overlap', 'write during fetch');
                $this->expect('x-overlap', $kept, "step {$this->step} ($do): a tag given while the poll fetched Google's list is on its day after the poll", $context + ['key' => $key]);
            }
        }
        // x-budget: a whole read stopped by its page budget applies nothing; the read that completes it then agrees (x-agree).
        if ($do === 'p-budget' && $this->budgetMid !== null) {
            $this->ran('x-budget', 'stopped read');
            $this->expect('x-budget', $this->budgetMid['l'] == $s0['here']['l'], "step {$this->step} ($do): a whole read stopped by its page budget changed no row", $context + ['diff' => self::diff($s0['here']['l'], $this->budgetMid['l'])]);
            $this->budgetMid = null;
        }
        // x-pending, the poll rule: while a series has changes not yet at Google, a poll neither removes nor rewrites its rows.
        if ($kind === 'p-' && $this->pendings !== []) {
            $gids = [];
            foreach ($this->pendings as $p) {
                $gids += $p['gids'];
            }
            $was = self::ofSeries($s0['l'], $gids, $s0['rows']);
            $now = self::ofSeries($s1['l'], $gids, $s1['rows']);
            $this->ran('x-pending', 'poll while half done');
            $this->expect('x-pending', $was == $now, "step {$this->step} ($do): a poll while a series has changes not yet at Google left that series' rows as they were", $context + ['diff' => self::diff($was, $now)]);
        }
        // x-readback: while a change is being sent the last write wins. A day renamed at Google between the app's series
        // write and its read back is the change's again, or the change says what Google holds there; the series renamed
        // there after that write keeps its title (the series is written once), and the change says so.
        $this->racedNow = $this->raced;
        if ($this->raced !== null) {
            [$rg, $rkey, $rtitle] = $this->raced;
            $this->raced = null;
            $rec = $rkey === null ? [$s1['g']['digest'][$rg]['title'] ?? null] : ($s1['g']['digest'][$rg]['records'][$rkey] ?? null);
            $this->ran('x-readback', $rkey === null ? 'race on the series' : 'race');
            $said = $s1['differs'] > $s0['differs'];
            $this->expect('x-readback', !$out['ok'] || ($rkey === null ? ($rec[0] ?? null) === $rtitle && $said : $rec === null || $rec[0] !== $rtitle || $said),
                "step {$this->step} ($do): " . ($rkey === null
                    ? "a series renamed at Google right after the write's master write keeps its title there, and the change says so"
                    : "a day renamed at Google between the write's master write and its read-back is the change's again, or the change says what Google holds there"), $context + ['key' => $rkey, 'record' => $rec]);
        }

        // x-sync-silent: a poll sends Google nothing.
        if ($kind === 'p-') {
            $this->ran('x-sync-silent', $do);
            $this->expect('x-sync-silent', $writes === [] && $s0['g'] == $s1['g'], "step {$this->step} ($do): a poll sends Google nothing", $context + ['writes' => self::brief($writes)]);
        }

        // A write of the app's own that reached Google on a series whose change is half done (A6 refuses it; until then it
        // goes through) is a change at Google since that change, as a remote one is, for Put back's check.
        if ($onPending && $out['ok'] && $taken !== [] && !in_array($do, ['finish', 'putback'], true)) {
            foreach ($this->pendings as $id => $p) {
                if (isset($p['gids'][$gid])) {
                    $this->pendings[$id]['since'][$gid] = true;
                }
            }
        }
        // A6: a write to a series whose change is half done is refused with the pending reason, writing nothing.
        if ($isWrite && $onPending && !in_array($do, ['finish', 'putback'], true) && $failure === null) {
            $this->ran('x-pending', 'write while half done');
            if ($out['ok']) {
                $this->fail('x-pending', "step {$this->step} ($do): a write to a series whose change is half done went through; the settled rule (A6) refuses it with the pending reason, pointing to Review", $context);
            } else {
                $this->expect('x-pending', self::sameHere($s0, $s1) && $taken === [] && $s0['g'] == $s1['g'], "step {$this->step} ($do): a write refused while its series' change is half done writes nothing", $context + ['diff' => self::diff($s0['l'], $s1['l'])]);
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
            $this->expect('x-write-asked', self::onlyKept($s0['g']['only'], $s1['g']['only']), "step {$this->step} ($do): a write here leaves every field only Google holds as it was (colorId, attendees, visibility, guest permissions)",
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
        // Changed since by what is shown: the series' digest at Google differs from the rows here (remote steps that cancel
        // out, a skip added and removed, leave none).
        $shownDiffers = $gid !== null && ($this->ldigest($s0['rows'])[$gid] ?? null) != ($s0['g']['digest'][$gid] ?? null);
        if ($isWrite && $gid !== null && isset($this->dirty[$gid]) && !$shownDiffers) {
            $this->etagOnly[$gid] = true;
        }
        $removal = in_array($asked['kind'] ?? '', ['delfollow', 'del1'], true);
        if ($removal && $gid !== null && isset($this->dirty[$gid]) && ($s0['g']['digest'][$gid] ?? null) === null) {
            // The series is already gone at Google: the removal completes here (decisions.md, "Removing a series already gone
            // at Google succeeds", case 303).
            $this->ran('x-conflict', 'removal of a series deleted at Google');
            if (!empty($asked['whole']) && $failure === null && !$onPending) {
                // A delete of the whole series from its first day; one from a later day is an edit of the series' end.
                $this->expect('x-conflict', $out['ok'], "step {$this->step} ($do): removing a series already gone at Google succeeds", $context);
            }
        }
        // A request that changes nothing sends nothing and is no conflict: what x-conflict protects is the change made at
        // Google, judged by what the step did, never by the field the request names. One that changes nothing asks Google
        // nothing at all (no read either: a write that would change something reads the series first, rule 1) and writes
        // nothing here. A whole-series clock asked to be the clock it already has, with the acted day's own time the same,
        // is such a request (seed 857 case 249).
        $asksNothing = true;
        foreach (array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']) as $q) {
            $asksNothing = $asksNothing && in_array('X-Harness: remote', $q['headers'], true);
        }
        $changedNothing = $out['ok'] && $asksNothing && self::sameHere($s0, $s1);
        if ($isWrite && $gid !== null && isset($this->dirty[$gid]) && $shownDiffers && $changedNothing && $failure === null) {
            $this->ran('x-conflict', 'nothing to change');
        }
        // With the conflict choice on, a change or delete of an event in no series is x-choice's to judge (below); with it
        // off, it is refused here as any other write (google-conflict-choice.md, (e): "Off: ... every path is the 1.5.6 code").
        $choice = $this->choiceOn && in_array($asked['kind'], ['single', 'single-del'], true);
        // With it on, a change or delete of one day of a repeating event over a change at Google not yet read is x-choice's
        // too, read as that day (google-conflict-choice.md, Slice 2); with it off, the 1.5.6 refusal below ("every day path
        // is the code it was").
        $dayChoice = $this->choiceOn && $this->daySent !== null && $kind === 'w-' && in_array($asked['kind'], ['this', 'del1'], true) && empty($asked['local'])
            && $gid !== null && isset($this->dirty[$gid]) && $shownDiffers && !$changedNothing && !$onPending && $failure === null;
        // The undo or redo of an edit of one event or one day, with it on, is compared too (Slice 3a), whatever changed at
        // Google since; with it off, or for any other entry, the refusals below and x-undo-fields as before.
        $undoEntry = $out['entry'] ?? ($asked['entry'] ?? null);
        $uc = in_array($do, self::UNDO_KINDS, true) && $failure === null && !$onPending ? $this->undoChoiceOf($undoEntry === null ? null : (int) $undoEntry, $asked, $s0) : null;
        // An undo of an entry that changed only what is held here alone sends Google nothing whatever changed there (Slice 3a,
        // (i): "The undo of a change held here alone on a Google calendar (tags, reminders): Untouched: sends Google nothing").
        $undoLocal = false;
        if (in_array($do, self::UNDO_KINDS, true) && $undoEntry !== null && isset($this->entryStates[(int) $undoEntry])) {
            $changed = self::entryChange($this->entryStates[(int) $undoEntry]);
            $undoLocal = $changed !== [] && array_filter($changed, static fn(array $f): bool => array_diff($f, ['tags', 'people', 'reminders', 'icon', 'attendance', 'lat', 'lng', 'own', 'links']) !== []) === [];
        }
        if ($isWrite && $gid !== null && isset($this->dirty[$gid]) && $shownDiffers && !$changedNothing && !($removal && ($s0['g']['digest'][$gid] ?? null) === null) && !$onPending && empty($asked['local']) && $failure === null && !$undoOfDelete && !$choice && !$dayChoice && $uc === null && !($this->choiceOn && $undoLocal) && !in_array($asked['kind'], ['create', 'finish', 'putback'], true)) {
            $this->ran('x-conflict', $do);
            // What protects the change made at Google is the refusal, whatever its reason: google_conflict, google_deleted for
            // a series deleted there (rule 8), or one decided here before Google is asked (stale_undo, invalid_recurrence_instance).
            // Its code goes in the tally.
            $this->ran('x-conflict', 'refused: ' . ($error ?? 'went through'));
            $this->expect('x-conflict', !$out['ok'], "step {$this->step} ($do): a change to a series changed at Google since the last poll is refused", $context);
            if (!$out['ok']) {
                $this->expect('x-conflict', self::sameHere($s0, $s1) && $taken === [] && $s0['g'] == $s1['g'], "step {$this->step} ($do): the refusal writes nothing here or at Google", $context + ['diff' => self::diff($s0['l'], $s1['l']), 'writes' => self::brief($taken)]);
            }
        }

        // x-conflict, the shown-fields rule: a series whose etag alone moved at Google, or that changed there only in fields
        // the rows here don't hold, is no conflict for a write (x-write-asked checks those fields keep Google's values).
        if ($isWrite && $gid !== null && isset($this->etagOnly[$gid]) && (!isset($this->dirty[$gid]) || !$shownDiffers) && !$onPending && $failure === null && empty($asked['local'])
            && !in_array($asked['kind'], ['finish', 'putback', 'create'], true)) {
            $this->ran('x-conflict', 'etag or Google-only fields');
            $this->expect('x-conflict', $error !== 'google_conflict', "step {$this->step} ($do): a change to a series whose shown fields at Google are as here (its etag or a Google-only field moved) is no conflict", $context);
        }

        // x-write-asked, a new event's marking: what the create sent and stored of free or busy.
        if ($do === 'w-create' && $out['ok'] && $this->made !== null) {
            [$mid, $mgid, $mAllDay] = $this->made;
            $g1 = $s1['g']['digest'][$mgid] ?? null;
            $this->checkNewMarking($do, $s1['rows'][$mid] ?? null, $g1 === null ? null : ($g1['showAs'] ? 'free' : 'busy'),
                array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']), $mAllDay, $context);
        }
        $this->made = null;

        // x-choice: a change or delete of an event in no series, the conflict choice on.
        if ($choice && $this->singleSent !== null) {
            $this->checkChoice($do, $out, $s0, $s1, $context);
        }
        $this->singleSent = null;
        // x-choice for one day of a repeating event: whether the step stored the read (a question, or a day no longer one).
        $dayStored = $dayChoice && $this->checkDayChoice($do, $out, $s0, $s1, $context);
        $daySent = $this->daySent;
        $this->daySent = null;
        // x-choice for the undo of one edit: whether the step stored the read and refused.
        $undoStored = $uc !== null && $this->checkUndoChoice($do, $out, $s0, $s1, $uc, $context);

        // x-pending: what a failure leaves.
        if ($failure !== null) {
            $this->checkFailure($failure, $out, $s0, $s1, $asked, $context);
        } elseif ($kind === 'f-') {
            $this->ran('f-unfired', $do); // fewer writes than k: an ordinary write
        }

        $this->recovered = in_array($do, ['finish', 'putback'], true) && $out['ok'] ? $this->pending : null;
        if ($do === 'finish' || $do === 'putback') {
            $this->checkRecovery($do, $out, $s0, $s1, $context, $taken);
        }
        if (in_array($do, ['u-entry', 'u-bare', 'redo'], true)) {
            $this->checkUndo($do, $out, $s0, $s1, $asked, $context, $taken, $uc);
        }

        // x-honest: a refusal with no pending change leaves both sides as they were; a write that changed something leaves an entry.
        // The conflict choice's question stores what it read from Google (google-conflict-choice.md, case 3: "the read is
        // stored"): x-choice judges what it leaves.
        if ($isWrite && !$out['ok'] && $failure === null && !isset($out['extra']['pending']) && !in_array($do, ['finish', 'putback'], true) && !($choice && $error === 'google_changed') && !$dayStored && !$undoStored) {
            $this->ran('x-honest', 'refusal');
            $this->expect('x-honest', self::sameHere($s0, $s1) && $s0['g']['digest'] == $s1['g']['digest'], "step {$this->step} ($do): a refusal ($error) changes nothing here or at Google", $context + ['diff' => self::diff($s0['l'], $s1['l'])]);
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
            $this->etagOnly = [];
            foreach ($this->touches as $i => $t) {
                $this->touches[$i]['polled'] = true;
            }
        }
        // A write that went through left the series' etag here current, so the next one is no conflict; so did a refusal
        // after which the rows hold what Google holds (the product read it back).
        // A series commit or a Finish that went through to Google stores what Google holds after its read-back, as a poll
        // does: for its series the harness's "changed at Google since the last poll" is reset the way a poll resets it
        // (series-model.md, Google as a Contract: "the rows stored are what Google holds after the last read-back").
        $readBack = $out['ok'] && $taken !== [] && ($do === 'finish' || in_array($asked['kind'] ?? '', ['all', 'following', 'delfollow', 'answer'], true));
        // The conflict choice reads the event first and stores what it read, as a poll does, whether the change then goes
        // through or asks (google-conflict-choice.md, (e), the step): the event here is Google's again.
        $readBack = $readBack || ($choice && ($out['ok'] || $error === 'google_changed'));
        // So does the choice for a day: the repeating event is read whole and stored (Slice 2, (e)).
        // A repeating event deleted at Google is not that: a day's delete completes "as today" and nothing says the deletion
        // is stored, so the series stays changed at Google since the last poll.
        // Only when the step read Google: a change that changes nothing reads nothing and stores nothing.
        $readGoogle = array_filter(array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']), static fn(array $q): bool => $q['method'] === 'GET' && !in_array('X-Harness: remote', $q['headers'], true)) !== [];
        $readBack = $readBack || ($dayChoice && $readGoogle && ($out['ok'] || $dayStored) && ($s0['g']['digest'][$gid] ?? null) !== null);
        // And the undo of one edit (Slice 3a, (c)): the read is stored whether it then goes through or asks.
        $readBack = $readBack || ($uc !== null && $readGoogle && ($out['ok'] || $undoStored) && ($s0['g']['digest'][(string) $uc['gid']] ?? null) !== null);
        if ($readBack) {
            $series = $do === 'finish' ? array_keys($this->recovered['gids'] ?? []) : [$gid];
            foreach (array_filter($series, static fn($g): bool => $g !== null) as $g) {
                unset($this->dirty[$g], $this->etagOnly[$g]);
                foreach ($this->touches as $i => $t) {
                    if ($t['gid'] === $g) {
                        $this->touches[$i]['polled'] = true;
                    }
                }
            }
        } elseif ($gid !== null && $isWrite && empty($asked['local']) && in_array($asked['kind'] ?? '', ['all', 'following', 'delfollow', 'answer'], true)
            && $error === 'google_conflict' && ($this->ldigest($s1['rows'])[$gid] ?? 1) == ($s1['g']['digest'][$gid] ?? 2)) {
            unset($this->dirty[$gid]);
        }
        $this->done[$this->step] = ['do' => $do, 'asked' => $asked, 'entries' => $entries, 'ok' => $out['ok'], 'rows0' => $s0['rows'], 'rows1' => $s1['rows']];
        // Each entry this step made, with the rows as the step found and left them: what an undo or redo of it is judged by.
        if ($entries !== [] && $kind !== 'p-') {
            $before = $this->byIdentity($s0['rows']);
            $after = $this->byIdentity($s1['rows']);
            $exclude = $do === 'w-race' && $this->racedIdent !== null ? [$this->racedIdent] : [];
            // The conflict choice first stores what it read "as a poll does, with its own Activity entry" (api-contract.md,
            // Google Calendar), so the change's own entry starts from the rows with that read stored: a field Google changed
            // is the change's only where the change set it to something else. The store's entry (a source of Google's) is
            // never undone by a step here.
            $read = $choice && $gid !== null ? $this->withReadStored($s0, (string) $gid)
                : ($dayChoice && $out['ok'] && $daySent !== null && ($s0['g']['digest'][$gid] ?? null) !== null ? $this->dayReadStored($s0, $s1, $daySent)
                : ($uc !== null && $out['ok'] ? $this->undoReadStored($s0, $s1, $uc) : null));
            $sources = [];
            if ($read !== null) {
                [$in, $params] = Db::in($entries);
                foreach ($this->db->all("SELECT id, source FROM mutations WHERE id IN $in", $params) as $m) {
                    $sources[(int) $m['id']] = (string) $m['source'];
                }
            }
            foreach ($entries as $e) {
                $from = $read !== null && !in_array($sources[$e] ?? '', ['google', 'feed', 'system'], true) ? $read : $before;
                $this->entryStates[$e] = ['before' => $from, 'after' => $after, 'step' => $this->step, 'exclude' => $exclude];
            }
        }
        $this->racedIdent = null;
        if ($out['ok'] && ($kind === 'w-' || $kind === 'f-')) {
            foreach (array_keys($asked['fields']) as $f) {
                $this->touches[] = ['step' => $this->step, 'gid' => $gid, 'remote' => false, 'field' => $f, 'key' => $asked['key'] ?? null, 'polled' => true,
                    'from' => ($asked['kind'] ?? '') === 'following' ? ($asked['from'] ?? null) : null];
            }
        }

        // x-agree: after a write that went through and after a poll, the rows are what Google holds; a poll then changes nothing.
        if (($kind === 'p-' || ($isWrite && $out['ok'])) && $this->dirty === [] && $s1['half'] === []) {
            $this->checkAgree($do, $s1, $context);
        }
    }

    /**
     * x-write-asked, a new event made here with no Show as chosen (the owner's rule of 2026-11-15, #303): an all-day one is
     * free, its create sending `transparency: "transparent"`; a timed one sends nothing for the field and is busy, Google's
     * own default (`opaque`). Here and at Google alike. $google is the stand-in's marking of it in the column's words, null
     * when Google holds no such event; $requests the app's requests during the create.
     */
    private function checkNewMarking(string $do, ?array $row, ?string $google, array $requests, bool $allDay, array $context): void
    {
        $this->ran('x-write-asked', 'a new event made here, ' . ($allDay ? 'all-day' : 'timed'));
        $want = $allDay ? 'free' : 'busy';
        $inserts = array_values(array_filter($requests, static fn(array $q): bool => $q['method'] === 'POST' && !in_array('X-Harness: remote', $q['headers'], true)));
        $sent = array_map(static fn(array $q): mixed => array_key_exists('transparency', (array) $q['body']) ? $q['body']['transparency'] : '(none)', $inserts);
        $context += ['allDay' => $allDay, 'here' => $row['show_as'] ?? null, 'google' => $google, 'sent' => $sent];
        $this->expect('x-write-asked', $row !== null && (string) $row['show_as'] === $want && $google === $want,
            "step {$this->step} ($do): a new " . ($allDay ? 'all-day event made here is free' : 'timed event made here is busy') . ', here and at Google', $context);
        $this->expect('x-write-asked', $inserts !== [] && $sent === array_fill(0, count($inserts), $allDay ? 'transparent' : '(none)'),
            "step {$this->step} ($do): the create of a new " . ($allDay ? 'all-day event sends transparency "transparent"' : 'timed event sends nothing for transparency'), $context);
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
        $changed = array_intersect_key(['title' => 1, 'description' => 1, 'location' => 1, 'url' => 1, 'showAs' => 1], $asked['fields']);
        if ($this->racedNow !== null && $this->racedNow[0] === $gid && $this->racedNow[1] === null) {
            $changed['title'] = 1; // the series renamed at Google during the write: x-readback's to judge
        }
        $series = ['title', 'description', 'location', 'url', 'showAs', 'zone', 'rule', 'start', 'end', 'allDay', 'skips'];
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

    /**
     * x-choice (interleaving-invariants.md, x-choice): a change or delete of an event in no series, the conflict choice
     * on, judged by the comparison of three states the harness records, never by kind of change
     * (google-conflict-choice.md, the one page, (a) to (d)): what the person was shown (the request's `over`, else the
     * row here before the step), what Google holds (the stand-in before the step) and what the change sets (the request).
     * Each field's outcome: not set and Google's as shown, the same; not set and Google's changed, kept; set and Google's
     * as shown or already as set, sent; set and Google's changed to something else, asked. A delete sets every field to
     * a value nothing holds. Then: asked anywhere, 409 google_changed naming exactly the asked fields (was, now, yours)
     * and the kept ones, the event here holding what Google holds and nothing of the request, nothing sent; else the
     * change goes through, each sent field holding the change's value here and at Google, each kept one Google's at both
     * and named in `kept`, a delete gone at both. One read, at most one write, none after a question. An Overwrite, sent
     * right after the question with nothing changed at Google since, goes through.
     */
    /**
     * The case's rows by identity as the conflict choice's read leaves them before the change is applied: the event in no
     * series holding the six fields the stand-in held when the step began (the read stored, as a poll stores it), every
     * other row as it was. Null when Google held no such event.
     */
    private function withReadStored(array $s0, string $gid): ?array
    {
        $g = $s0['g']['digest'][$gid] ?? null;
        $id = $this->singles[$gid] ?? null;
        if ($g === null || $id === null || !isset($s0['rows'][$id])) {
            return null;
        }
        $rows = $s0['rows'];
        $rows[$id] = ['title' => $g['title'], 'description' => $g['description'], 'location' => $g['location'], 'url' => $g['url'], 'status' => $g['status'] ? 'tentative' : 'confirmed', 'show_as' => $g['showAs'] ? 'free' : 'busy',
            'start_utc' => gmdate('Y-m-d H:i:s', $g['time'][0]), 'end_utc' => gmdate('Y-m-d H:i:s', $g['time'][1]), 'all_day' => $g['time'][2] ? 1 : 0] + $rows[$id];
        return $this->byIdentity($rows);
    }

    private function checkChoice(string $do, array $out, array $s0, array $s1, array $context): void
    {
        $sent = $this->singleSent;
        $gid = (string) $sent['gid'];
        $id = (int) $sent['id'];
        $action = (string) $sent['action'];
        $body = (array) $sent['body'];
        $g0 = $s0['g']['digest'][$gid] ?? null;
        if ($g0 === null || !isset($s0['rows'][$id])) {
            $this->ran('x-choice', 'event gone at Google');
            return;
        }
        $G = array_diff_key($g0, ['single' => 1]);
        $R = self::rowSix($s0['rows'][$id]);
        $over = (array) ($body['over'] ?? []);
        $error = $out['ok'] ? null : (string) $out['error'];

        // The request budget (case 1: "one read, one conditional write"; case 3: "nothing is sent").
        $app = array_values(array_filter(array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']), static fn(array $q): bool => !in_array('X-Harness: remote', $q['headers'], true)));
        $reads = count(array_filter($app, static fn(array $q): bool => $q['method'] === 'GET'));
        $writes = count($app) - $reads;

        // Two refusals, by what is true of the request, both before anything else, nothing read from Google and nothing
        // written here or there. A time in parts where the rule asks for one whole (T2: on a request carrying `over`, a time
        // the request changes is sent whole, and `over` names the time whole or not at all) is an out-of-date client: 400
        // client_outdated. A whole time whose resolved end is not after its start (T4, narrowed: the body's time after its
        // typed clocks, and `over`'s) is an invalid time: 400 invalid_request. Parts are judged first.
        $bodyTime = $action === 'delete' ? [] : array_intersect_key($body, array_flip(self::TIME_KEYS));
        $overTime = array_intersect_key($over, array_flip(self::TIME_KEYS));
        $parts = [];
        $invalidWhy = [];
        if (array_key_exists('over', $body) && $bodyTime !== [] && !self::isWhole($bodyTime)) {
            $parts[] = 'the time sent is not whole: ' . implode(', ', array_keys($bodyTime));
        }
        if (array_key_exists('over', $body) && $bodyTime !== [] && self::isWhole($bodyTime) && ($invalid = self::invalidState(self::resolved($body))) !== null) {
            $invalidWhy[] = 'the time sent, resolved' . (isset($body['typed']) ? ' with its typed clocks' : '') . ", is no event state: $invalid";
        }
        if ($overTime !== [] && !self::isWhole($overTime)) {
            $parts[] = "over's time is not whole: " . implode(', ', array_keys($overTime));
        } elseif ($overTime !== [] && ($invalid = self::invalidState(self::allDayRead($overTime))) !== null) {
            $invalidWhy[] = "over's time is no event state: $invalid";
        }
        if ($parts !== [] || $invalidWhy !== []) {
            [$code, $rule] = $parts !== [] ? ['client_outdated', 'T2'] : ['invalid_request', 'T4'];
            $context += ['action' => $action, 'refused' => [...$parts, ...$invalidWhy], 'reads' => $reads, 'writes' => $writes];
            $this->ran('x-choice', $parts !== [] ? 'refused: time in parts (T2)' : 'refused: resolved end not after start (T4)');
            $this->expect('x-choice', $error === $code && ($out['status'] ?? null) === 400,
                $parts !== [] ? "step {$this->step} ($do): a request carrying over whose time is in parts is refused, 400 client_outdated (T2)"
                    : "step {$this->step} ($do): a request carrying over whose whole time, resolved, does not end after its start is refused, 400 invalid_request (T4)",
                $context + ['error' => $error, 'status' => $out['status'] ?? null, 'expected' => $code]);
            $this->expect('x-choice', $reads === 0 && $writes === 0, "step {$this->step} ($do): the refusal reads nothing from Google and writes nothing there ($rule)", $context);
            $this->expect('x-choice', $s0['rows'] == $s1['rows'] && $s0['g'] == $s1['g'],
                "step {$this->step} ($do): the refusal changes nothing here or at Google ($rule)", $context + ['here' => self::diff($s0['l'], $s1['l'])]);
            return;
        }

        // What the person was shown: `over` where the request sends it, else the row as stored ((b)). The time is `over`'s
        // whole time, one value (T4: "the base ... for the time is `over`'s whole time").
        $B = $R;
        foreach (['title', 'description', 'location', 'url', 'status', 'showAs'] as $f) {
            if (array_key_exists($f, $over)) {
                $B[$f] = self::servedValue($f, $over[$f]);
            }
        }
        if ($overTime !== []) {
            $B['time'] = self::timeOf(self::allDayRead($overTime)); // the one resolved base (#302)
        }
        // What the change sets, only where it differs from what was shown ((a): "a field sent at its base value is not
        // wanted"). The time is the body's whole time as one value, nothing of it taken from the base or the row (T1, T4:
        // "The server never completes one part of a time from the base and another from the row"); compared by instants
        // and kind, the zone not compared (the page's field table), so a zone changed alone is no change of the time.
        $W = [];
        if ($action !== 'delete') {
            foreach (['title', 'description', 'location', 'url', 'status', 'showAs'] as $f) {
                if (array_key_exists($f, $body)) {
                    $W[$f] = self::servedValue($f, $body[$f]);
                }
            }
            if ($action === 'relationship') {
                $W['status'] = ($body['relationship'] ?? '') === 'maybe';
            }
            if ($bodyTime !== []) {
                if (!self::isWhole($bodyTime)) {
                    // A time in part with no `over`: an API caller's, read as 1.5.6 reads it against the row (T3), which the
                    // harness does not state and does not draw (the database suite covers that path).
                    $this->ran('x-choice', 'a time in part with no over (T3, not drawn)');
                    return;
                }
                $W['time'] = self::timeOf(self::resolved($body));
                // A whole time at the shown instants and kind in another zone: the time not wanted, the zone still sent to
                // Google on its own (the page and api-contract.md). Not asserted and never drawn; tallied so a run shows it
                // stays at zero.
                if ($overTime !== [] && $W['time'] === $B['time'] && !$W['time'][2] && (string) $bodyTime['tzid'] !== (string) ($overTime['tzid'] ?? '')) {
                    $this->ran('x-choice', 'same instants, another zone (never drawn)');
                }
            }
            $W = array_filter($W, static fn($v, string $f): bool => $v !== $B[$f], ARRAY_FILTER_USE_BOTH);
        }
        $outcome = [];
        foreach (self::CHOICE_FIELDS as $f) {
            $wanted = $action === 'delete' || array_key_exists($f, $W);
            $outcome[$f] = !$wanted ? ($G[$f] === $B[$f] ? 'same' : 'kept')
                : ($G[$f] === $B[$f] || ($action !== 'delete' && $G[$f] === $W[$f]) ? 'send' : 'ask');
        }
        $asks = array_keys(array_filter($outcome, static fn(string $o): bool => $o === 'ask'));
        $kept = array_keys(array_filter($outcome, static fn(string $o): bool => $o === 'kept'));
        sort($asks);
        sort($kept);
        $toSend = $action === 'delete' || array_filter($W, static fn($v, string $f): bool => $G[$f] !== $v, ARRAY_FILTER_USE_BOTH) !== [];
        $context += ['action' => $action, 'outcomes' => $outcome, 'shown' => $B['time'], 'google' => $G['time'], 'row' => $R['time']] + (isset($W['time']) ? ['wanted' => $W['time']] : []);
        $this->ran('x-choice', $action . ($sent['overwriting'] !== null ? ' (overwrite)' : '') . ': ' . ($asks !== [] ? 'asks' : ($kept !== [] ? 'keeps' : ($G === $B ? 'nothing changed at Google since shown' : 'sends'))));
        $budget = $context + ['reads' => $reads, 'writes' => $writes, 'requests' => array_map(static fn(array $q): string => $q['method'] . ' ' . rawurldecode(basename((string) $q['path'])), $app)];
        // The zone a timed time sent lands in, here and at Google (T1: the zone is part of the one value the change sets).
        $sentZone = $bodyTime !== [] && !$bodyTime['allDay'] ? (string) $bodyTime['tzid'] : null;
        $googleZone = function (): ?string {
            $m = $this->stand->fake->events[(string) $this->single] ?? null;
            return $m === null ? null : ($m['start']['timeZone'] ?? null);
        };
        // The step runs "only when the request sends Google something (a non-empty `patchBody`, a delete, an answer)" (the
        // page, (e)), and a request that changes nothing sends nothing and asks Google nothing (decisions.md, "A request that
        // changes nothing sends nothing and is no conflict"). A change whose every field the row here already holds (a poll
        // stored Google's Maybe and the person, still shown Planned, clicks Maybe: seed 94756 case 266) is such a request:
        // it goes through with no read and no write, and the three-way comparison is not asked.
        if ($action !== 'delete' && array_filter($W, static fn($v, string $f): bool => $R[$f] !== $v, ARRAY_FILTER_USE_BOTH) === []) {
            $this->ran('x-choice', 'sends Google nothing');
            $this->expect('x-choice', $out['ok'] && $reads === 0 && $writes === 0 && $s1['g'] == $s0['g'], "step {$this->step} ($do): a change the row here already holds goes through asking Google nothing", $budget);
            return;
        }
        $this->ran('x-choice', 'budget');
        $this->expect('x-choice', $action === 'delete' || $W !== [] ? $reads === 1 : $reads <= 1, "step {$this->step} ($do): a change to one event reads it from Google once", $budget);
        $this->expect('x-choice', $asks !== [] ? $writes === 0 : ($toSend ? $writes === 1 : $writes <= 1),
            "step {$this->step} ($do): " . ($asks !== [] ? 'a change that asks sends Google nothing' : 'a change that goes through makes one conditional write' . ($toSend ? '' : ' at most (nothing left to send)')), $budget);

        $now = null;
        foreach ($s1['rows'] as $r) {
            if ((string) $r['google_event_id'] === $gid && $r['rrule'] === null && $r['recurrence_parent_id'] === null) {
                $now = $r;
            }
        }
        $g1 = $s1['g']['digest'][$gid] ?? null;
        $held = static fn(?array $r): ?array => $r === null ? null : ['tags' => $r['tags'], 'people' => $r['people'], 'reminders' => $r['reminders_json'] ?? null, 'icon' => $r['icon'] ?? null, 'attendance' => $r['attendance']];
        $told = static function (array $list): array {
            $out = [];
            foreach ($list as $item) {
                $out[(string) ($item['field'] ?? '')] = $item;
            }
            ksort($out);
            return $out;
        };
        $keptRight = function (array $list, string $where) use ($kept, $G, $told, $context): void {
            $byField = $told($list);
            $this->expect('x-choice', array_keys($byField) == $kept, "step {$this->step}: $where names exactly the fields Google changed that the change did not set (kept)", $context + ['kept' => $list]);
            foreach (array_intersect_key($byField, array_flip($kept)) as $f => $item) {
                $this->expect('x-choice', self::servedValue($f, $item['now'] ?? null) === $G[$f], "step {$this->step}: $where gives a kept field's value as Google holds it", $context + ['field' => $f, 'now' => $item['now'] ?? null]);
            }
        };
        if ($asks !== []) {
            $this->expect('x-choice', $error === 'google_changed', "step {$this->step} ($do): a change of a field Google changed to something else since it was shown asks: 409 google_changed", $context + ['asks' => $asks]);
            if ($error === 'google_changed') {
                $byField = $told((array) ($out['extra']['fields'] ?? []));
                $this->expect('x-choice', array_keys($byField) == $asks, "step {$this->step} ($do): the question names exactly the fields asked about", $context + ['fields' => $out['extra']['fields'] ?? null]);
                foreach (array_intersect_key($byField, array_flip($asks)) as $f => $item) {
                    $this->expect('x-choice', self::servedValue($f, $item['was'] ?? null) === $B[$f] && self::servedValue($f, $item['now'] ?? null) === $G[$f],
                        "step {$this->step} ($do): the question gives each field as shown (was) and at Google now (now)", $context + ['field' => $f, 'told' => $item]);
                    // yours: what the change sets, the body's whole time for the time (T4), never completed from anything.
                    $this->expect('x-choice', $action === 'delete' ? ($item['yours'] ?? null) === null : self::servedValue($f, $item['yours'] ?? null) === $W[$f],
                        "step {$this->step} ($do): the question gives each field as the change sets it (yours, null for a delete)", $context + ['field' => $f, 'told' => $item]);
                    if ($f === 'time') {
                        // A time is one value (T1): was, now and yours each whole, the four keys, so Overwrite can send them
                        // back as `over` and body whole (T2). A timed one names its zone: was `over`'s, now Google's, yours
                        // the body's. An all-day one's zone is not asserted (the server stores UTC whatever is sent).
                        $whole = is_array($item['was'] ?? null) && self::isWhole($item['was']) && is_array($item['now'] ?? null) && self::isWhole($item['now'])
                            && ($action === 'delete' || (is_array($item['yours'] ?? null) && self::isWhole($item['yours'])));
                        $this->expect('x-choice', $whole, "step {$this->step} ($do): the question gives the time whole, start, end, allDay and tzid, in was, now and yours (T1)", $context + ['told' => $item]);
                        // An all-day one names UTC (google-conflict-choice.md (b): "an all-day time's zone UTC"). (A zone left
                        // out is the statement above's failure, not counted again here.)
                        $zoneOf = static fn(mixed $t): ?string => is_array($t) && isset($t['tzid']) ? (string) $t['tzid'] : null;
                        $want = static fn(mixed $t, ?string $timed): ?string => is_array($t) && !empty($t['allDay']) ? 'UTC' : $timed;
                        $zones = ['was' => [$zoneOf($item['was'] ?? null), $want($item['was'] ?? null, $zoneOf($overTime))],
                            'now' => [$zoneOf($item['now'] ?? null), $want($item['now'] ?? null, $G['time'][2] ? null : $googleZone())]]
                            + ($action === 'delete' ? [] : ['yours' => [$zoneOf($item['yours'] ?? null), $want($item['yours'] ?? null, $sentZone)]]);
                        $zones = array_filter($zones, static fn(array $p): bool => $p[0] !== null && $p[1] !== null && $p[0] !== $p[1]);
                        $this->expect('x-choice', $zones === [], "step {$this->step} ($do): was, now and yours each name their zone: over's, Google's, the change's when timed, UTC when all-day (T1)", $context + ['told' => $item, 'zones' => $zones]);
                    }
                }
                $keptRight((array) ($out['extra']['kept'] ?? []), 'the question');
            }
            $this->expect('x-choice', $now !== null && self::rowSix($now) === $G, "step {$this->step} ($do): after the question the event here holds what Google holds (the read is stored)", $context + ['here' => $now === null ? null : self::rowSix($now), 'google' => $G]);
            $this->expect('x-choice', $now !== null && $held($now) == $held($s0['rows'][$id]), "step {$this->step} ($do): nothing of the request is written here, tags in the same request included", $context + ['before' => $held($s0['rows'][$id]), 'after' => $held($now)]);
            $this->expect('x-choice', $this->takenBetween($s0, $s1) === [] && $s1['g'] == $s0['g'], "step {$this->step} ($do): after the question Google holds what it held", $context);
        } else {
            $this->expect('x-choice', $out['ok'], "step {$this->step} ($do): a change that asks about no field goes through", $context);
            if ($out['ok'] && $action === 'delete') {
                $this->expect('x-choice', $now === null && $g1 === null, "step {$this->step} ($do): the delete removes the event here and at Google", $context + ['here' => $now !== null, 'google' => $g1 !== null]);
            } elseif ($out['ok']) {
                $E = [];
                foreach (self::CHOICE_FIELDS as $f) {
                    $E[$f] = match ($outcome[$f]) { 'send' => $W[$f], 'kept' => $G[$f], default => $B[$f] };
                }
                $this->expect('x-choice', $now !== null && self::rowSix($now) === $E, "step {$this->step} ($do): here, each field sent holds the change's value and each kept one Google's", $context + ['here' => $now === null ? null : self::rowSix($now), 'expected' => $E]);
                $this->expect('x-choice', $g1 !== null && array_diff_key($g1, ['single' => 1]) === $E, "step {$this->step} ($do): at Google, each field sent holds the change's value and each kept one Google's", $context + ['google' => $g1, 'expected' => $E]);
                if (isset($W['time']) && $sentZone !== null) {
                    // A timed time sent lands whole, its zone with it (T1), here and at Google.
                    $this->expect('x-choice', $now !== null && (string) $now['tzid'] === $sentZone && $googleZone() === $sentZone,
                        "step {$this->step} ($do): the zone the change sets lands with its time, here and at Google (T1)", $context + ['zone' => $sentZone, 'here' => $now['tzid'] ?? null, 'google' => $googleZone()]);
                }
                if (isset($body['tagNames'])) {
                    $want = (array) $body['tagNames'];
                    sort($want);
                    $this->expect('x-choice', $now !== null && $now['tags'] == $want, "step {$this->step} ($do): the tags in the same request are written", $context + ['tags' => $now['tags'] ?? null]);
                }
                $answer = (array) ($out['answer'] ?? []);
                $this->expect('x-choice', array_key_exists('kept', $answer), "step {$this->step} ($do): the answer carries kept", $context + ['answer' => $answer]);
                $keptRight((array) ($answer['kept'] ?? []), 'the answer');
            }
        }
        if ($sent['overwriting'] !== null) {
            // Overwrite, sent as the question says right after it (nothing changed at Google since): it goes through.
            $this->ran('x-choice', 'overwrite');
            $this->expect('x-choice', $out['ok'], "step {$this->step} ($do): Overwrite, sent as the question says with nothing changed at Google since, goes through", $context);
            // What lands is exactly what the question showed as the person's ((c): "Overwrite sends what the question showed
            // as yours"), here and at Google, for every field it asked about with a value (a delete's yours is null).
            if ($out['ok']) {
                foreach ((array) ($sent['overwriting']['extra']['fields'] ?? []) as $item) {
                    $f = (string) ($item['field'] ?? '');
                    if (!in_array($f, self::CHOICE_FIELDS, true) || ($item['yours'] ?? null) === null) {
                        continue;
                    }
                    $yours = self::servedValue($f, $item['yours']);
                    $this->ran('x-choice', 'overwrite lands yours');
                    $this->expect('x-choice', $now !== null && self::rowSix($now)[$f] === $yours && $g1 !== null && $g1[$f] === $yours,
                        "step {$this->step} ($do): Overwrite lands exactly what the question showed as yours, here and at Google", $context + ['field' => $f, 'yours' => $yours, 'here' => $now === null ? null : self::rowSix($now)[$f], 'google' => $g1[$f] ?? null]);
                    if ($f === 'time' && empty($item['yours']['allDay']) && isset($item['yours']['tzid'])) {
                        // ... a timed yours in its zone (T1).
                        $this->expect('x-choice', $now !== null && (string) $now['tzid'] === (string) $item['yours']['tzid'] && $googleZone() === (string) $item['yours']['tzid'],
                            "step {$this->step} ($do): Overwrite lands the zone of the yours the question showed, here and at Google (T1)", $context + ['yours' => $item['yours'], 'here' => $now['tzid'] ?? null, 'google' => $googleZone()]);
                    }
                }
            }
        }
        // Nothing else of the case changes, here or at Google.
        $others = static fn(array $l): array => array_diff_key($l, [$id => 1]);
        $this->expect('x-choice', $others($s0['l']) == $others($s1['l']), "step {$this->step} ($do): a change to one event changes no other row here", $context + ['diff' => self::diff($others($s0['l']), $others($s1['l']))]);
        $this->expect('x-choice', array_diff_key($s0['g']['digest'], [$gid => 1]) == array_diff_key($s1['g']['digest'], [$gid => 1]), "step {$this->step} ($do): a change to one event changes nothing else at Google", $context);
    }

    // ---- one day of a repeating event (google-conflict-choice.md, Slice 2), by the harness's own reading ------------------

    /** The fields a day shows, raw as the rows store them, from a digest (gstate's or ldigest's): its record at the key, else the series' values there; null when the key is skipped. */
    private static function dayRaw(array $digest, string $key): ?array
    {
        if (in_array($key, $digest['skips'], true)) {
            return null;
        }
        $rec = $digest['records'][$key] ?? null;
        if ($rec !== null) {
            return ['title' => $rec[0], 'start' => $rec[1], 'end' => $rec[2], 'status' => $rec[3], 'allDay' => (int) $rec[4], 'description' => $rec[5], 'location' => $rec[6], 'url' => $rec[7], 'showAs' => $rec[8]];
        }
        return self::seriesRawAt($digest, $key);
    }

    /** The repeating event's own values at a day (RFC 5545 3.8.5.3: the key its start, the series' length from it), from a digest. */
    private static function seriesRawAt(array $digest, string $key): array
    {
        $len = self::instantOf((string) $digest['end']) - self::instantOf((string) $digest['start']);
        return ['title' => (string) $digest['title'], 'description' => (string) $digest['description'], 'location' => (string) $digest['location'], 'url' => (string) $digest['url'],
            'status' => (string) $digest['status'], 'showAs' => (string) $digest['showAs'], 'start' => $key, 'end' => gmdate('Y-m-d H:i:s', self::instantOf($key) + $len), 'allDay' => (int) $digest['allDay']];
    }

    /** A day's raw fields as the comparison reads them, by the page's equalities (rowSix's). */
    private static function rawSix(array $raw): array
    {
        return ['title' => self::titleOf($raw['title']), 'description' => trim((string) $raw['description']), 'location' => trim((string) $raw['location']), 'url' => trim((string) $raw['url']),
            'status' => (string) $raw['status'] === 'tentative', 'showAs' => (string) $raw['showAs'] === 'free',
            'time' => [self::instantOf((string) $raw['start']), self::instantOf((string) $raw['end']), (int) $raw['allDay'] === 1]];
    }

    /**
     * What the person was shown of a day, read as the page says with no `over` (Slice 2, (b): "the day read as the series
     * state reads it: an edited day's own fields from its row, a field it does not own from the repeating event's row, and
     * an ordinary day the stored repeating event at that day (its fields, the day's start, the event's length)"). The time
     * is the row's when the day owns any part of it (series-model.md, Own Fields: date, time, length, allDay).
     */
    private static function dayBase(array $rows, int $mid, string $key): array
    {
        $m = $rows[$mid];
        $series = self::seriesRawAt(['title' => $m['title'], 'description' => $m['description'], 'location' => $m['location'], 'url' => $m['url'], 'status' => $m['status'],
            'showAs' => $m['show_as'] ?? 'busy', 'start' => $m['start_utc'], 'end' => $m['end_utc'], 'allDay' => $m['all_day']], $key);
        foreach ($rows as $r) {
            if ((int) ($r['recurrence_parent_id'] ?? 0) !== $mid || (string) $r['recurrence_instance_utc'] !== $key) {
                continue;
            }
            foreach (['title', 'description', 'location', 'url', 'status'] as $f) {
                if (in_array($f, $r['own'], true)) {
                    $series[$f] = (string) $r[$f];
                }
            }
            if (in_array('showAs', $r['own'], true)) {
                $series['showAs'] = (string) ($r['show_as'] ?? 'busy');
            }
            if (array_intersect(['date', 'time', 'length', 'allDay'], $r['own']) !== []) {
                $series = ['start' => $r['start_utc'], 'end' => $r['end_utc'], 'allDay' => (int) $r['all_day']] + $series;
            }
        }
        return $series;
    }

    /** The day row of a master at a key in a set of rows, or null. */
    private static function dayRow(array $rows, int $mid, string $key): ?array
    {
        foreach ($rows as $r) {
            if ((int) ($r['recurrence_parent_id'] ?? 0) === $mid && (string) $r['recurrence_instance_utc'] === $key) {
                return $r;
            }
        }
        return null;
    }

    /**
     * The case's rows by identity as the day's read leaves them before the change is applied, for the change's own entry:
     * the rows after the step (the store and the write; the write touches the day alone), with the day as the store left
     * it: its fields Google's, the rest as it was before the step (its own list, tags and what is held here), or no row
     * where neither it nor Google's record of it existed; a delete's skip taken off. The harness's own construction, as
     * withReadStored is for one event.
     */
    private function dayReadStored(array $s0, array $s1, array $sent): array
    {
        $gid = (string) $sent['gid'];
        $key = (string) $sent['key'];
        $ident = "day:$gid:$key";
        $before = $this->byIdentity($s1['rows']);
        $was = $this->byIdentity($s0['rows']);
        $g0 = $s0['g']['digest'][$gid] ?? null;
        $raw = $g0 === null ? null : self::dayRaw($g0, $key);
        $google = $raw === null ? [] : array_intersect_key($raw, array_flip(['title', 'description', 'location', 'url', 'status', 'showAs', 'start', 'end', 'allDay']));
        if ($sent['action'] === 'delete' && isset($before["series:$gid"])) {
            $before["series:$gid"]['skips'] = array_values(array_diff($before["series:$gid"]['skips'], [$key]));
        }
        if (isset($was[$ident])) {
            $before[$ident] = $google + $was[$ident];
        } elseif ($g0 !== null && isset($g0['records'][$key]) && isset($before[$ident])) {
            $before[$ident] = $google + $before[$ident];
        } else {
            unset($before[$ident]);
        }
        ksort($before);
        return $before;
    }

    /**
     * x-choice for one day of a repeating event (google-conflict-choice.md, Slice 2; api-contract.md, "One day of a
     * repeating event"), the conflict choice on, over a change at Google not yet read. The harness sends no `over` for a
     * day, so what the person was shown is the day as stored (dayBase); what Google holds is the day's record in the
     * stand-in, else the repeating event's values there (Slice 2, (c)); what the change sets is the request. Each field's
     * outcome is the slice 1 comparison: not set, the same or kept; set, sent or asked. Then: the repeating event gone at
     * Google, an edit google_deleted and a delete completed; the day no longer one of the repeating event's days after the
     * read (skipped at Google, or neither a record of Google's nor a day of its rule), an edit google_conflict and a delete
     * completed, nothing sent, the read stored; asked anywhere, 409 google_changed naming exactly the asked fields (was, now,
     * yours, from) and `day`, the read stored, nothing of the request written and nothing sent; else the change goes
     * through, the day holding each sent field's value and Google's for the rest, here and at Google, `kept` naming exactly
     * this day's other changed fields, every other day as Google holds it. Returns whether the step stored the read and
     * refused (a question, or a day no longer one).
     */
    private function checkDayChoice(string $do, array $out, array $s0, array $s1, array $context): bool
    {
        $sent = (array) $this->daySent;
        $gid = (string) $sent['gid'];
        $mid = (int) $sent['mid'];
        $key = (string) $sent['key'];
        $action = (string) $sent['action'];
        $body = (array) $sent['body'];
        $error = $out['ok'] ? null : (string) $out['error'];
        $g0 = $s0['g']['digest'][$gid] ?? null;
        $g1 = $s1['g']['digest'][$gid] ?? null;
        $l0 = $this->ldigest($s0['rows'])[$gid] ?? null;
        $l1 = $this->ldigest($s1['rows'])[$gid] ?? null;
        $app = array_values(array_filter(array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']), static fn(array $q): bool => !in_array('X-Harness: remote', $q['headers'], true)));
        $reads = count(array_filter($app, static fn(array $q): bool => $q['method'] === 'GET'));
        $taken = $this->takenBetween($s0, $s1);
        $kindOf = ($action === 'delete' ? 'delete' : ($action === 'relationship' ? 'Planned or Maybe' : ($action === 'useSeries' ? 'Use series value' : (isset($body['start']) ? 'drag' : 'edit'))))
            . (self::dayRow($s0['rows'], $mid, $key) !== null ? ' of an edited day' . (!empty($sent['byRow']) ? ' by its row' : '') : ' of an ordinary day');
        $context += ['day' => $key, 'action' => $kindOf];
        if (!isset($s0['rows'][$mid]) || $l0 === null) {
            return false;
        }
        if ($action === 'useSeries') {
            // "Use series value" for a field the day already shows at the series' value as stored here changes nothing:
            // no read and nothing sent (decisions.md, "A request that changes nothing sends nothing and is no conflict").
            $f = (string) $body['useSeries'][0];
            if (self::rawSix(self::dayBase($s0['rows'], $mid, $key))[$f] === self::rawSix(self::seriesRawAt($l0, $key))[$f]) {
                $this->ran('x-choice', "day: Use series value of a field already at the series' value (not judged)");
                return false;
            }
        }
        // The repeating event deleted at Google (the unlikely list: "an edit of a day gets the google_deleted sentence it
        // gets today; a delete of a day completes, as today").
        if ($g0 === null) {
            $this->ran('x-choice', "day: the repeating event deleted at Google, $kindOf");
            $this->expect('x-choice', $action === 'delete' ? $out['ok'] : $error === 'google_deleted',
                "step {$this->step} ($do): " . ($action === 'delete' ? 'a delete of a day of a repeating event deleted at Google completes' : 'a change to a day of a repeating event deleted at Google is refused google_deleted'), $context);
            $this->expect('x-choice', $taken === [], "step {$this->step} ($do): nothing is sent for a day of a repeating event deleted at Google", $context + ['writes' => self::brief($taken)]);
            return false;
        }
        // Still a day after the read (Slice 2, (d): "the day has a row, or the stored rule gives that day and no skip takes
        // it"): a live record of Google's at the key, or a day of Google's rule that Google does not skip.
        $gm = $g0['rule'];
        $rule = 'FREQ=' . ($gm['FREQ'] ?? '') . implode('', array_map(static fn(string $k, string $v): string => ";$k=$v", array_keys(array_diff_key($gm, ['FREQ' => 1])), array_diff_key($gm, ['FREQ' => 1])));
        $gRaw = self::dayRaw($g0, $key);
        $isDay = $gRaw !== null && (isset($g0['records'][$key])
            || Truth::isOccurrence(['start_utc' => $g0['start'], 'end_utc' => $g0['end'], 'rrule' => $rule, 'tzid' => $g0['zone'] ?? 'UTC', 'all_day' => $g0['allDay']], $key));
        if (!$isDay) {
            $this->ran('x-choice', "day: no longer a day of the repeating event, $kindOf");
            $this->expect('x-choice', $action === 'delete' ? $out['ok'] : $error === 'google_conflict',
                "step {$this->step} ($do): " . ($action === 'delete' ? 'a delete of a day no longer one of the repeating event once the read is stored completes' : 'a change to a day no longer one of the repeating event once the read is stored is refused google_conflict'),
                $context + ['google' => $gRaw === null ? 'skipped' : 'not a day of the rule']);
            $this->expect('x-choice', $taken === [], "step {$this->step} ($do): nothing is sent for a day no longer one of the repeating event", $context + ['writes' => self::brief($taken)]);
            $this->expect('x-choice', $l1 == $g0, "step {$this->step} ($do): the read of the repeating event is stored when the day is no longer one of its days", $context + ['diff' => self::digestDiff((array) $g0, (array) $l1)]);
            return !$out['ok'];
        }
        $B = self::rawSix(self::dayBase($s0['rows'], $mid, $key));
        $shown = self::rawSix((array) self::dayRaw($l0, $key));
        if ($shown !== $B) {
            // The rows show the day otherwise than its own list reads it: the base is the page's reading; counted, so a run
            // shows how often the two differ.
            $this->ran('x-choice', 'day: the row shows a field it does not own otherwise than its series');
        }
        $G = self::rawSix($gRaw);
        $mG = self::rawSix(self::seriesRawAt($g0, $key));
        $m0 = self::rawSix(self::seriesRawAt($l0, $key));
        // What the change sets, where it differs from what was shown ("a field sent at its base value is not wanted").
        $W = [];
        if ($action !== 'delete') {
            foreach (['title', 'description', 'location', 'url', 'showAs'] as $f) {
                if (array_key_exists($f, $body)) {
                    $W[$f] = self::servedValue($f, $body[$f]);
                }
            }
            if ($action === 'relationship') {
                $W['status'] = ($body['relationship'] ?? '') === 'maybe';
            }
            if (isset($body['start'])) {
                $W['time'] = self::timeOf(self::allDayRead(array_intersect_key($body, array_flip(self::TIME_KEYS))));
            }
            if ($action === 'useSeries') {
                // "What is sent after the read is the repeating event's value as stored then" (case 12).
                $f = (string) $body['useSeries'][0];
                $W[$f] = $mG[$f];
            }
            $W = array_filter($W, static fn($v, string $f): bool => $v !== $B[$f], ARRAY_FILTER_USE_BOTH);
            if ($W === []) {
                $this->ran('x-choice', "day: nothing the change sets differs from what was shown, $kindOf (not judged)");
                return false;
            }
        }
        $outcome = [];
        foreach (self::CHOICE_FIELDS as $f) {
            $wanted = $action === 'delete' || array_key_exists($f, $W);
            $outcome[$f] = !$wanted ? ($G[$f] === $B[$f] ? 'same' : 'kept')
                : ($G[$f] === $B[$f] || ($action !== 'delete' && $G[$f] === $W[$f]) ? 'send' : 'ask');
        }
        $asks = array_keys(array_filter($outcome, static fn(string $o): bool => $o === 'ask'));
        $kept = array_keys(array_filter($outcome, static fn(string $o): bool => $o === 'kept'));
        sort($asks);
        sort($kept);
        // Where the change at Google was, for the tally: the repeating event's own fields, this day, another day.
        $seriesPart = static fn(array $d): array => array_diff_key($d, ['records' => 1, 'skips' => 1]);
        $others = static fn(array $d): array => [array_diff_key($d['records'], [$key => 1]), array_values(array_diff($d['skips'], [$key]))];
        $where = array_keys(array_filter(['the whole event' => $seriesPart($l0) != $seriesPart($g0), 'this day' => $G !== $B, 'another day' => $others($l0) != $others($g0)]));
        $from = [];
        foreach ($asks as $f) {
            // Slice 2, (f): series when the repeating event's own value at that day changed with this read and the day
            // shows that new value; day otherwise.
            $from[$f] = $mG[$f] !== $m0[$f] && $G[$f] === $mG[$f] ? 'series' : 'day';
        }
        $context += ['outcomes' => $outcome, 'where' => $where];
        $this->ran('x-choice', "day: $kindOf over a change to " . ($where === [] ? 'nothing it shows' : implode(' and ', $where)) . ': ' . ($asks !== [] ? 'asks (from ' . implode(', ', array_unique($from)) . ')' : ($kept !== [] ? 'keeps' : 'sends')));
        if ($asks === [] && !empty($sent['byRow']) && self::dayRow($s1['rows'], $mid, $key) === null && $action !== 'delete') {
            // Slice 2, the unlikely list: "An edited day whose row is gone once the read is stored, with nothing to ask (its
            // record went back to the repeating event's values in a way the comparison does not see): today's refusal
            // (google_conflict, CONFLICT_MESSAGE), the read stored." The request names the row, which the store took away;
            // only a day Google holds at the repeating event's own values loses it.
            $this->ran('x-choice', "day: an edited day's row gone with the stored read, $kindOf");
            $this->expect('x-choice', !isset($g0['records'][$key]), "step {$this->step} ($do): an edited day's row goes with the stored read only where Google holds the day at the repeating event's values", $context + ['google' => $gRaw]);
            $this->expect('x-choice', $error === 'google_conflict', "step {$this->step} ($do): a change sent to an edited day's row that the stored read took away is refused google_conflict", $context);
            $this->expect('x-choice', $taken === [] && $l1 == $g0, "step {$this->step} ($do): that refusal sends nothing and leaves the read stored", $context + ['writes' => self::brief($taken), 'diff' => self::digestDiff((array) $g0, (array) $l1)]);
            return !$out['ok'];
        }
        $budget = $context + ['reads' => $reads, 'writes' => count($taken), 'requests' => array_map(static fn(array $q): string => $q['method'] . ' ' . rawurldecode(basename((string) $q['path'])), $app)];
        $this->expect('x-choice', $reads >= 1, "step {$this->step} ($do): a change to a day reads the repeating event from Google first", $budget);
        $toSend = $action === 'delete' || array_filter($W, static fn($v, string $f): bool => $G[$f] !== $v, ARRAY_FILTER_USE_BOTH) !== [];
        $this->expect('x-choice', $asks !== [] ? $taken === [] : ($toSend ? count($taken) === 1 : count($taken) <= 1),
            "step {$this->step} ($do): " . ($asks !== [] ? 'a change to a day that asks sends Google nothing' : 'a change to a day that goes through makes one write' . ($toSend ? '' : ' at most (nothing left to send)')), $budget);
        $told = static function (array $list): array {
            $o = [];
            foreach ($list as $item) {
                $o[(string) ($item['field'] ?? '')] = $item;
            }
            ksort($o);
            return $o;
        };
        $keptRight = function (array $list, string $where) use ($kept, $G, $told, $context): void {
            $byField = $told($list);
            $this->expect('x-choice', array_keys($byField) == $kept, "step {$this->step}: $where names exactly this day's fields Google changed that the change did not set (kept)", $context + ['kept' => $list]);
            foreach (array_intersect_key($byField, array_flip($kept)) as $f => $item) {
                $this->expect('x-choice', self::servedValue($f, $item['now'] ?? null) === $G[$f], "step {$this->step}: $where gives a kept field of the day as Google holds it", $context + ['field' => $f, 'now' => $item['now'] ?? null]);
            }
        };
        $ownTag = isset($body['tagNames']) ? (string) end($body['tagNames']) : null;
        if ($asks !== []) {
            $this->expect('x-choice', $error === 'google_changed', "step {$this->step} ($do): a change to a field of the day that Google changed to something else since it was shown asks: 409 google_changed", $context + ['asks' => $asks]);
            if ($error === 'google_changed') {
                $byField = $told((array) ($out['extra']['fields'] ?? []));
                $this->expect('x-choice', array_keys($byField) == $asks, "step {$this->step} ($do): the question about a day names exactly the fields asked about", $context + ['fields' => $out['extra']['fields'] ?? null]);
                foreach (array_intersect_key($byField, array_flip($asks)) as $f => $item) {
                    $this->expect('x-choice', self::servedValue($f, $item['was'] ?? null) === $B[$f] && self::servedValue($f, $item['now'] ?? null) === $G[$f],
                        "step {$this->step} ($do): the question gives each field of the day as shown (was) and at Google now (now)", $context + ['field' => $f, 'told' => $item, 'shown' => $B[$f], 'google' => $G[$f]]);
                    $this->expect('x-choice', $action === 'delete' ? ($item['yours'] ?? null) === null : self::servedValue($f, $item['yours'] ?? null) === $W[$f],
                        "step {$this->step} ($do): the question gives each field of the day as the change sets it (yours, null for a delete)", $context + ['field' => $f, 'told' => $item]);
                    $this->expect('x-choice', ($item['from'] ?? null) === $from[$f], "step {$this->step} ($do): the question says where the change was made: series when the whole repeating event changed and the day shows it, day otherwise",
                        $context + ['field' => $f, 'from' => $item['from'] ?? null, 'expected' => $from[$f]]);
                }
                $day = $out['extra']['day'] ?? null;
                $this->expect('x-choice', is_array($day) && self::isWhole($day) && self::timeOf(self::allDayRead($day)) === $B['time'],
                    "step {$this->step} ($do): the question names the day by its time as shown, whole (day)", $context + ['dayTold' => $day, 'shown' => $B['time']]);
                $keptRight((array) ($out['extra']['kept'] ?? []), 'the question');
            }
            $this->expect('x-choice', $l1 == $g0, "step {$this->step} ($do): after the question the repeating event here is what Google holds (the read is stored)", $context + ['diff' => self::digestDiff((array) $g0, (array) $l1)]);
            $this->expect('x-choice', $taken === [] && $s1['g'] == $s0['g'], "step {$this->step} ($do): after the question about a day Google holds what it held", $context + ['writes' => self::brief($taken)]);
            if ($ownTag !== null) {
                $tagged = array_filter($s1['rows'], static fn(array $r): bool => in_array($ownTag, $r['tags'], true));
                $this->expect('x-choice', $tagged === [], "step {$this->step} ($do): nothing of the request is written here on a question, tags in the same request included", $context + ['tag' => $ownTag]);
            }
            return true;
        }
        $this->expect('x-choice', $out['ok'], "step {$this->step} ($do): a change to a day that asks about no field goes through", $context);
        if (!$out['ok'] || $l1 === null || $g1 === null) {
            return false;
        }
        if ($action === 'delete') {
            $this->expect('x-choice', self::dayRaw($l1, $key) === null && self::dayRaw($g1, $key) === null, "step {$this->step} ($do): the delete removes the day here and at Google",
                $context + ['here' => self::dayRaw($l1, $key), 'google' => self::dayRaw($g1, $key)]);
        } else {
            $E = [];
            foreach (self::CHOICE_FIELDS as $f) {
                $E[$f] = match ($outcome[$f]) { 'send' => $W[$f], 'kept' => $G[$f], default => $B[$f] };
            }
            $here = self::dayRaw($l1, $key);
            $there = self::dayRaw($g1, $key);
            $this->expect('x-choice', $here !== null && self::rawSix($here) === $E, "step {$this->step} ($do): here, the day holds each field sent at the change's value and the rest as Google holds them",
                $context + ['here' => $here === null ? null : self::rawSix($here), 'expected' => $E]);
            $this->expect('x-choice', $there !== null && self::rawSix($there) === $E, "step {$this->step} ($do): at Google, the day holds each field sent at the change's value and the rest as Google held them",
                $context + ['google' => $there === null ? null : self::rawSix($there), 'expected' => $E]);
            if ($ownTag !== null) {
                $row = self::dayRow($s1['rows'], $mid, $key);
                $want = (array) $body['tagNames'];
                sort($want);
                $this->expect('x-choice', $row !== null && $row['tags'] == $want, "step {$this->step} ($do): the tags in the same request are written on the day", $context + ['tags' => $row['tags'] ?? null]);
            }
            $answer = (array) ($out['answer'] ?? []);
            $this->expect('x-choice', array_key_exists('kept', $answer), "step {$this->step} ($do): the answer for a day carries kept", $context + ['answer' => $answer]);
            $keptRight((array) ($answer['kept'] ?? []), 'the answer');
        }
        // The write reached this day alone at Google, and every other day here shows as Google holds it (the read stored).
        $strip = static fn(array $d): array => ['records' => array_diff_key($d['records'], [$key => 1]), 'skips' => array_values(array_diff($d['skips'], [$key]))] + $d;
        $this->expect('x-choice', $strip($g1) == $strip($g0), "step {$this->step} ($do): a change to one day changes nothing else of the repeating event at Google", $context + ['diff' => self::digestDiff($strip($g0), $strip($g1))]);
        $this->expect('x-choice', $l1 == $g1, "step {$this->step} ($do): after a change to one day the repeating event here is what Google holds, its other days included (the read is stored)", $context + ['diff' => self::digestDiff($g1, $l1)]);
        return false;
    }

    /** Where two digests of a series differ, key by key (records and the rule by their own keys), for a finding's detail. */
    private static function digestDiff(array $a, array $b): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (($a[$k] ?? null) == ($b[$k] ?? null)) {
                continue;
            }
            if (in_array($k, ['records', 'rule'], true) && is_array($a[$k] ?? null) && is_array($b[$k] ?? null)) {
                foreach (array_unique(array_merge(array_keys($a[$k]), array_keys($b[$k]))) as $r) {
                    if (($a[$k][$r] ?? null) != ($b[$k][$r] ?? null)) {
                        $out[$k][$r] = [$a[$k][$r] ?? null, $b[$k][$r] ?? null];
                    }
                }
                continue;
            }
            $out[$k] = [$a[$k] ?? null, $b[$k] ?? null];
        }
        return $out;
    }

    // ---- the undo of one edit (google-conflict-choice.md, Slice 3a), by the harness's own reading -------------------------


    /** A day as an identity map (byIdentity's) shows it: the series' values at the key, the row's where it owns them (series-model.md, Own Fields). */
    private static function identDay(array $ids, string $gid, string $key, bool $withRow = true): ?array
    {
        $s = $ids["series:$gid"] ?? null;
        if ($s === null) {
            return null;
        }
        $raw = self::seriesRawAt(['title' => $s['title'], 'description' => $s['description'], 'location' => $s['location'], 'url' => $s['url'], 'status' => $s['status'],
            'showAs' => $s['showAs'], 'start' => $s['start'], 'end' => $s['end'], 'allDay' => $s['allDay']], $key);
        $r = $withRow ? ($ids["day:$gid:$key"] ?? null) : null;
        foreach ($r === null ? [] : ['title', 'description', 'location', 'url', 'status', 'showAs'] as $f) {
            if (in_array($f, (array) $r['own'], true)) {
                $raw[$f] = (string) $r[$f];
            }
        }
        if ($r !== null && array_intersect(['date', 'time', 'length', 'allDay'], (array) $r['own']) !== []) {
            $raw = ['start' => $r['start'], 'end' => $r['end'], 'allDay' => (int) $r['allDay']] + $raw;
        }
        return $raw;
    }

    /**
     * Whether an undo or redo is the page's (Slice 3a): the conflict choice on, and the entry an edit of one event or one day
     * (its step's request a change of one event or one day) that holds the row it left (its recorded states change that one
     * row, and leave it there), and changed a field the comparison reads, with that row still here. Not: an entry that left
     * a day no row (the redo of an undo that removed the row among them), a create, a delete, an answer, a change to all
     * events, one held here alone. Returns what the judgement needs: the identity, B (the compared fields as the entry left
     * them), W (as before it: a field the day did not own before the entry, the series' value at that day as stored before
     * the undo, (b)), the fields the entry changed, and those the day followed.
     */
    private function undoChoiceOf(?int $entry, array $asked, array $s0): ?array
    {
        if (!$this->choiceOn || $entry === null || $this->heldBack($entry)) {
            return null;
        }
        $state = $this->entryStates[$entry] ?? null;
        $of = $asked['of'] ?? null;
        // The person's own entry: a poll's or a store's (a source of Google's) is log only, never this.
        if (in_array((string) $this->db->scalar('SELECT source FROM mutations WHERE id = ?', [$entry]), ['google', 'feed', 'system'], true)) {
            return null;
        }
        if ($state === null || !in_array($of === null ? null : ($this->done[$of]['asked']['kind'] ?? null), ['this', 'single'], true)) {
            return null;
        }
        $change = self::entryChange($state);
        if (count($change) !== 1) {
            return null;
        }
        $ident = (string) array_key_first($change);
        $isDay = preg_match('/^day:([^:]+):(.+)$/', $ident, $m) === 1;
        $now = $this->byIdentity($s0['rows']);
        if ($change[$ident] === ['-'] || (!$isDay && (!str_starts_with($ident, 'event:') || $change[$ident] === ['+'])) || !isset($now[$ident])) {
            return null;
        }
        $gid = $isDay ? $m[1] : substr($ident, strlen('event:'));
        $key = $isDay ? $m[2] : null;
        // A later change the comparison does not read (tags, people, reminders, a skip, the row made again) is still the
        // stale check's, asked before anything is read ((d)): such an undo keeps the code it had.
        $since = $this->changedSince($state, $now, ['ident' => $ident, 'isDay' => $isDay]);
        if ($since !== []) {
            return null;
        }
        if ($isDay) {
            // A day keyed in its series as it is now: the series' clock unchanged since the entry (otherwise the journal's
            // own placement rules decide, x-day's).
            $sa = $state['after']["series:$gid"] ?? null;
            $sn = $now["series:$gid"] ?? null;
            if ($sa === null || $sn === null || self::clockOf($sa) !== self::clockOf($sn) || (int) $sa['allDay'] !== (int) $sn['allDay']) {
                return null;
            }
            $after = self::identDay($state['after'], $gid, $key);
            $before = self::identDay($state['before'], $gid, $key);
            $seriesNow = self::rawSix((array) self::identDay($now, $gid, $key, false));
            $row = $state['before'][$ident] ?? null;
            $W = self::rawSix($before);
            $followed = [];
            foreach (self::CHOICE_FIELDS as $f) {
                $owns = $row !== null && array_intersect($f === 'time' ? ['date', 'time', 'length', 'allDay'] : [$f], (array) $row['own']) !== [];
                if (!$owns) {
                    $W[$f] = $seriesNow[$f];
                    $followed[] = $f;
                }
            }
        } else {
            $after = $state['after'][$ident];
            $before = $state['before'][$ident];
            $W = self::rawSix($before);
            $followed = [];
        }
        $B = self::rawSix($after);
        $was = self::rawSix($before);
        $F = array_values(array_filter(self::CHOICE_FIELDS, static fn(string $f): bool => $B[$f] !== $was[$f]));
        if ($F === [] || $this->changedSince($state, $now, ['ident' => $ident, 'isDay' => $isDay, 'F' => $F]) !== []) {
            return null;
        }
        return ['entry' => $entry, 'ident' => $ident, 'isDay' => $isDay, 'gid' => $gid, 'key' => $key, 'B' => $B, 'W' => $W, 'F' => $F, 'followed' => $followed,
            'redo' => ($asked['kind'] ?? '') === 'redo'];
    }

    /** The rows by identity as the undo's read leaves them before the undo is applied: the target's compared fields Google's, the rest of its series as stored after the step. */
    private function undoReadStored(array $s0, array $s1, array $uc): array
    {
        $c = $this->byIdentity($s0['rows']);
        $g0 = $s0['g']['digest'][$uc['gid']] ?? null;
        if ($g0 === null || !isset($c[$uc['ident']])) {
            return $c;
        }
        if ($uc['isDay']) {
            // Every other row of the series as the store left it, where the read changed what it shows; as before the
            // step elsewhere, so an undo that touches another day is still seen.
            $after = $this->byIdentity($s1['rows']);
            $l0 = $this->ldigest($s0['rows'])[$uc['gid']] ?? null;
            $prefix = 'day:' . $uc['gid'] . ':';
            foreach (array_unique(array_merge(array_keys($after), array_keys($c))) as $ident) {
                if ($ident === $uc['ident'] || ($ident !== 'series:' . $uc['gid'] && !str_starts_with($ident, $prefix))) {
                    continue;
                }
                $read = $l0 === null || ($ident === 'series:' . $uc['gid']
                    ? array_diff_key($l0, ['records' => 1]) != array_diff_key($g0, ['records' => 1])
                    : self::dayRaw($l0, substr($ident, strlen($prefix))) != self::dayRaw($g0, substr($ident, strlen($prefix))));
                if (!$read) {
                    continue;
                }
                if (isset($after[$ident])) {
                    $c[$ident] = $after[$ident];
                } else {
                    unset($c[$ident]);
                }
            }
            $raw = self::dayRaw($g0, (string) $uc['key']);
            if ($raw === null) {
                return $c;
            }
        } else {
            $raw = ['title' => $g0['title'], 'description' => $g0['description'], 'location' => $g0['location'], 'url' => $g0['url'], 'status' => $g0['status'] ? 'tentative' : 'confirmed',
                'showAs' => $g0['showAs'] ? 'free' : 'busy', 'start' => gmdate('Y-m-d H:i:s', $g0['time'][0]), 'end' => gmdate('Y-m-d H:i:s', $g0['time'][1]), 'allDay' => $g0['time'][2] ? 1 : 0];
        }
        // Only where the six differ: a value the comparison reads as equal (trimmed, "(No title)") keeps the row's spelling.
        $mine = self::rawSix($c[$uc['ident']]);
        $theirs = self::rawSix($raw);
        foreach (['title', 'description', 'location', 'url', 'status', 'showAs'] as $f) {
            if ($mine[$f] !== $theirs[$f]) {
                $c[$uc['ident']][$f] = $raw[$f];
            }
        }
        if ($mine['time'] !== $theirs['time']) {
            $c[$uc['ident']] = ['start' => $raw['start'], 'end' => $raw['end'], 'allDay' => (int) $raw['allDay']] + $c[$uc['ident']];
        }
        if ($uc['isDay']) {
            // A field the read changed on the day is the day's own exactly when Google holds it otherwise than the series
            // there (as a poll reads a record: series-model.md, Google as a Contract); a field the read did not change keeps
            // what the row said of it.
            $series = self::rawSix(self::seriesRawAt($g0, (string) $uc['key']));
            $own = (array) $c[$uc['ident']]['own'];
            foreach (['title', 'description', 'location', 'url', 'status', 'showAs'] as $f) {
                if ($mine[$f] !== $theirs[$f]) {
                    $own = $theirs[$f] === $series[$f] ? array_values(array_diff($own, [$f])) : array_values(array_unique(array_merge($own, [$f])));
                }
            }
            sort($own);
            $c[$uc['ident']]['own'] = $own;
        }
        return $c;
    }

    /**
     * x-choice for the undo of one edit (google-conflict-choice.md, Slice 3a, (j)): B the compared fields the entry changed
     * as it left them, W as before it, G what the stand-in holds before the undo, and every other field's B the rows before
     * the undo. Asks exactly when a field the entry changed has G ≠ B and G ≠ W: 409 google_changed with `entry`, those
     * fields with was B, now G, yours W (a day's with `from` and `day`), nothing sent, the read stored, the entry not undone,
     * nothing held here alone put back. Otherwise the entry is undone, each field it changed at W here and at Google (a field
     * the day followed at the series' value after the read), every other compared field at G, `kept` naming exactly those
     * with G ≠ B. The event or series gone at Google: google_deleted; a day no longer one, or whose row went with the read:
     * google_conflict, the read stored. Returns whether the step stored the read and refused.
     */
    private function checkUndoChoice(string $do, array $out, array $s0, array $s1, array $uc, array $context): bool
    {
        $gid = (string) $uc['gid'];
        $ident = (string) $uc['ident'];
        $key = (string) $uc['key'];
        $error = $out['ok'] ? null : (string) $out['error'];
        $g0 = $s0['g']['digest'][$gid] ?? null;
        $g1 = $s1['g']['digest'][$gid] ?? null;
        $taken = $this->takenBetween($s0, $s1);
        $undone = (int) $this->db->scalar('SELECT undone FROM mutations WHERE id = ?', [$uc['entry']]) === 1;
        $what = ($uc['redo'] ? 'redo' : 'undo') . ' of an edit of ' . ($uc['isDay'] ? 'a day' : 'an event');
        $context += ['entry' => $uc['entry'], 'fields' => $uc['F'], 'kind' => $what];
        $app = array_values(array_filter(array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']), static fn(array $q): bool => !in_array('X-Harness: remote', $q['headers'], true)));
        $reads = count(array_filter($app, static fn(array $q): bool => $q['method'] === 'GET'));
        $s0ids = $this->byIdentity($s0['rows']);
        $s1ids = $this->byIdentity($s1['rows']);
        $held = static fn(?array $r): ?array => $r === null ? null : array_intersect_key($r, array_flip(['tags', 'people', 'reminders', 'icon', 'attendance', 'lat', 'lng', 'links']));
        if ($g0 === null) {
            $this->ran('x-choice', "undo: $what, deleted at Google");
            $this->expect('x-choice', $error === 'google_deleted' && !$undone && $taken === [], "step {$this->step} ($do): the undo of an edit of something deleted at Google is refused google_deleted, nothing sent", $context + ['error' => $error]);
            return false;
        }
        $l0 = $this->ldigest($s0['rows'])[$gid] ?? null;
        $l1 = $this->ldigest($s1['rows'])[$gid] ?? null;
        $mid = null;
        if ($uc['isDay']) {
            foreach (self::masters($s0['rows']) as $mr) {
                if ((string) $mr['google_event_id'] === $gid) {
                    $mid = (int) $mr['id'];
                }
            }
            $gm = $g0['rule'];
            $rule = 'FREQ=' . ($gm['FREQ'] ?? '') . implode('', array_map(static fn(string $k, string $v): string => ";$k=$v", array_keys(array_diff_key($gm, ['FREQ' => 1])), array_diff_key($gm, ['FREQ' => 1])));
            $gRaw = self::dayRaw($g0, $key);
            $isDay = $gRaw !== null && (isset($g0['records'][$key])
                || Truth::isOccurrence(['start_utc' => $g0['start'], 'end_utc' => $g0['end'], 'rrule' => $rule, 'tzid' => $g0['zone'] ?? 'UTC', 'all_day' => $g0['allDay']], $key));
            if ($mid === null || $l0 === null || !$isDay) {
                $this->ran('x-choice', "undo: $what, no longer a day");
                $this->expect('x-choice', $error === 'google_conflict' && !$undone && $taken === [], "step {$this->step} ($do): the undo of an edit of a day no longer one of the repeating event's days once the read is stored is refused google_conflict, nothing sent", $context + ['error' => $error]);
                $this->expect('x-choice', $l1 == $g0, "step {$this->step} ($do): that refusal leaves the read stored", $context + ['diff' => self::digestDiff($g0, (array) $l1)]);
                return !$out['ok'];
            }
            $G = self::rawSix($gRaw);
            $Rest = self::rawSix(self::dayBase($s0['rows'], $mid, $key));
            $mG = self::rawSix(self::seriesRawAt($g0, $key));
            $m0 = self::rawSix(self::seriesRawAt($l0, $key));
        } else {
            $G = array_diff_key($g0, ['single' => 1]);
            $Rest = self::rawSix($s0ids[$ident]);
            $mG = $m0 = $G;
        }
        $B = $Rest;
        foreach ($uc['F'] as $f) {
            $B[$f] = $uc['B'][$f];
        }
        $W = $uc['W'];
        $outcome = [];
        foreach (self::CHOICE_FIELDS as $f) {
            $outcome[$f] = in_array($f, $uc['F'], true) ? ($G[$f] === $B[$f] || $G[$f] === $W[$f] ? 'send' : 'ask') : ($G[$f] === $Rest[$f] ? 'same' : 'kept');
        }
        $asks = array_keys(array_filter($outcome, static fn(string $o): bool => $o === 'ask'));
        $kept = array_keys(array_filter($outcome, static fn(string $o): bool => $o === 'kept'));
        sort($asks);
        sort($kept);
        $context += ['outcomes' => $outcome];
        $this->ran('x-choice', "undo: $what: " . ($asks !== [] ? 'asks' : ($kept !== [] ? 'keeps' : 'goes through')));
        $this->expect('x-choice', $reads >= 1, "step {$this->step} ($do): the undo of an edit reads what Google holds first", $context + ['reads' => $reads]);
        $told = static function (array $list): array {
            $o = [];
            foreach ($list as $item) {
                $o[(string) ($item['field'] ?? '')] = $item;
            }
            ksort($o);
            return $o;
        };
        if ($asks !== []) {
            $this->expect('x-choice', $error === 'google_changed', "step {$this->step} ($do): the undo of an edit whose field was changed again since, to another value than before the edit, asks: 409 google_changed", $context + ['asks' => $asks, 'error' => $error]);
            if ($error === 'google_changed') {
                $this->expect('x-choice', (int) ($out['extra']['entry'] ?? 0) === (int) $uc['entry'], "step {$this->step} ($do): the undo's question names its entry", $context + ['told' => $out['extra']['entry'] ?? null]);
                $byField = $told((array) ($out['extra']['fields'] ?? []));
                $this->expect('x-choice', array_keys($byField) == $asks, "step {$this->step} ($do): the undo's question names exactly the fields changed again since", $context + ['fields' => $out['extra']['fields'] ?? null]);
                foreach (array_intersect_key($byField, array_flip($asks)) as $f => $item) {
                    $this->expect('x-choice', self::servedValue($f, $item['was'] ?? null) === $B[$f] && self::servedValue($f, $item['now'] ?? null) === $G[$f] && self::servedValue($f, $item['yours'] ?? null) === $W[$f],
                        "step {$this->step} ($do): the undo's question gives each field as the entry left it (was), as Google holds it (now) and as the undo puts it back (yours)", $context + ['field' => $f, 'told' => $item, 'B' => $B[$f], 'G' => $G[$f], 'W' => $W[$f]]);
                    if ($uc['isDay']) {
                        $from = $mG[$f] !== $m0[$f] && $G[$f] === $mG[$f] ? 'series' : 'day';
                        $this->expect('x-choice', ($item['from'] ?? null) === $from, "step {$this->step} ($do): the undo's question about a day says where the change was made (from)", $context + ['field' => $f, 'from' => $item['from'] ?? null, 'expected' => $from]);
                    }
                }
                if ($uc['isDay']) {
                    $day = $out['extra']['day'] ?? null;
                    $this->expect('x-choice', is_array($day) && self::isWhole($day), "step {$this->step} ($do): the undo's question about a day names the day, whole", $context + ['dayTold' => $day]);
                }
            }
            $this->expect('x-choice', !$undone && $taken === [] && $s1['g'] == $s0['g'], "step {$this->step} ($do): after the undo's question nothing is sent and the entry is not undone", $context + ['undone' => $undone, 'writes' => self::brief($taken)]);
            $stored = $uc['isDay'] ? $l1 == $g0 : (isset($s1ids[$ident]) && self::rawSix($s1ids[$ident]) === $G);
            $this->expect('x-choice', $stored, "step {$this->step} ($do): after the undo's question the rows hold what Google holds (the read is stored)", $context);
            // A day's row the stored read took away (Google holds the day at the series' values again) holds nothing to compare.
            $this->expect('x-choice', !isset($s1ids[$ident]) || $held($s1ids[$ident]) == $held($s0ids[$ident]), "step {$this->step} ($do): after the undo's question nothing held here alone is put back", $context + ['before' => $held($s0ids[$ident]), 'after' => $held($s1ids[$ident] ?? null)]);
            return true;
        }
        if ($uc['isDay'] && !$out['ok'] && $error === 'google_conflict' && !isset($s1ids[$ident])) {
            // Slice 3a, the unlikely list: "The day's row went with the stored read and nothing asks ...: the undo's whole
            // refusal, the read stored."
            $this->ran('x-choice', "undo: $what, its row gone with the stored read");
            $this->expect('x-choice', !isset($g0['records'][$key]) && !$undone && $taken === [] && $l1 == $g0, "step {$this->step} ($do): an undo refused for a day's row gone with the read: Google holds the day at the series' values, nothing sent, the read stored", $context);
            return true;
        }
        $this->expect('x-choice', $out['ok'] && $undone, "step {$this->step} ($do): the undo of an edit that asks about no field goes through", $context + ['error' => $error]);
        if (!$out['ok'] || $g1 === null) {
            return false;
        }
        $E = [];
        foreach (self::CHOICE_FIELDS as $f) {
            $E[$f] = in_array($f, $uc['F'], true) ? (in_array($f, $uc['followed'], true) ? $mG[$f] : $W[$f]) : ($outcome[$f] === 'kept' ? $G[$f] : $Rest[$f]);
        }
        if ($uc['isDay']) {
            $here = $l1 === null ? null : self::dayRaw($l1, $key);
            $there = self::dayRaw($g1, $key);
            $here = $here === null ? null : self::rawSix($here);
            $there = $there === null ? null : self::rawSix($there);
        } else {
            $here = isset($s1ids[$ident]) ? self::rawSix($s1ids[$ident]) : null;
            $there = array_diff_key($g1, ['single' => 1]);
        }
        $this->expect('x-choice', $here === $E, "step {$this->step} ($do): after the undo the rows hold each field the entry changed as before it and every other as Google holds it", $context + ['here' => $here, 'expected' => $E]);
        $this->expect('x-choice', $there === $E, "step {$this->step} ($do): after the undo Google holds each field the entry changed as before it and every other as it held it", $context + ['google' => $there, 'expected' => $E]);
        $answer = (array) ($out['answer'] ?? []);
        $this->expect('x-choice', array_key_exists('kept', $answer) && array_keys($told((array) $answer['kept'])) == $kept, "step {$this->step} ($do): the undo's answer names exactly the other fields changed in Google Calendar since (kept)", $context + ['kept' => $answer['kept'] ?? null, 'expected' => $kept]);
        foreach (array_intersect_key($told((array) ($answer['kept'] ?? [])), array_flip($kept)) as $f => $item) {
            $this->expect('x-choice', self::servedValue($f, $item['now'] ?? null) === $G[$f], "step {$this->step} ($do): the undo's answer gives a kept field as Google holds it", $context + ['field' => $f, 'now' => $item['now'] ?? null]);
        }
        return false;
    }

    private function checkFailure(string $failure, array $out, array $s0, array $s1, array $asked, array $context): void
    {
        $taken = $this->stand->knobs->taken;
        $context += ['failure' => $failure, 'taken' => $taken];
        if ($failure === 'before' && $taken === 0) {
            $this->ran('x-pending', 'nothing sent');
            $this->expect('x-pending', !$out['ok'], "step {$this->step}: a write whose first request never left fails", $context);
            $this->expect('x-pending', $s1['half'] === [] && self::sameHere($s0, $s1) && $s0['g']['digest'] == $s1['g']['digest'],
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
        // "Changed since" counts from the half-done change's last step: only a change made at Google after it is one
        // (interleaving-invariants.md, A2: "any change made at Google to a series of the change after the failure"). Put
        // back may run while Google holds what the change started from or what its steps left, judged by content
        // (series-model.md, Recovery: "its etag at Google is the one its last answered step left or the one the change
        // started from"), and a change made at Google before this one, read or not, is part of what it started from: the
        // change read the series before its first write. So the record starts empty, and remote() adds to it.
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

    private function checkRecovery(string $do, array $out, array $s0, array $s1, array $context, array $taken): void
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
                $this->expect('x-putback', $taken === [] && $s0['g'] == $s1['g'] && self::sameHere($s0, $s1) && in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: a refused Put back sends nothing, changes nothing here and leaves the change in Review",
                    $context + ['writes' => self::brief($taken), 'diff' => self::diff($s0['l'], $s1['l'])]);
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
                // By identity, not row id: a master Put back recreates keeps its new id, with what hung off it reattached
                // (GoogleSeries::reattach; series-model.md, Recovery: "a row the recovery brings back under a new id ... takes
                // back its id, its own columns and what hung off it" names edited days, not the master).
                $series = static function (array $idents, array $gids): array {
                    $out = [];
                    foreach ($idents as $ident => $row) {
                        foreach (array_keys($gids) as $g) {
                            if (str_starts_with($ident, 'series:' . $g) || str_starts_with($ident, 'day:' . $g . ':')) {
                                $out[$ident] = array_diff_key($row, ['id' => 1]);
                            }
                        }
                    }
                    return $out;
                };
                $was = $series($this->byIdentity($p['s0']['rows']), $p['gids']);
                $now = $series($this->byIdentity($s1['rows']), $p['gids']);
                $this->expect('x-putback', self::same($was, $now), "step {$this->step}: after Put back the rows here are as before the change", $context + ['diff' => self::diff($was, $now)]);
                $this->expect('x-putback', !in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: Put back clears the change from Review", $context);
                $this->settled($p);
            }
            return;
        }
        $this->ran('x-finish', $changedSince === [] ? 'unchanged' : 'changed since');
        if (!$out['ok']) {
            if ($error === 'google_hidden_days' && $this->finishHides()) {
                // The hidden-days rule at Finish: a switch Google would hide days with, by the harness's own reading, is
                // refused before anything is sent, the change left in Review.
                $this->ran('x-finish', 'refused for hidden days');
                // Nothing is sent; the rows here take what Google holds, as after any stop (GoogleSeries::run).
                $this->expect('x-finish', $taken === [] && in_array($p['id'], array_column($s1['half'], 'id'), true),
                    "step {$this->step}: a Finish refused for hidden days sends nothing and leaves the change in Review", $context + ['writes' => self::brief($taken)]);
                return;
            }
            $this->expect('x-finish', $error === 'google_finish_local' && in_array($p['id'], array_column($s1['half'], 'id'), true), "step {$this->step}: Finish fails only with google_finish_local, or with google_hidden_days where the switch would hide a day, the change left in Review", $context);
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
            foreach (['title' => 'title', 'description' => 'description', 'location' => 'location', 'url' => 'url', 'showAs' => 'show_as'] as $f => $col) {
                if (isset($asked['fields'][$f])) {
                    $this->expect('x-finish', (($g[$f] ?? null) === $asked['fields'][$f] && (string) $master[$col] === $asked['fields'][$f]) || $differences !== [],
                        "step {$this->step}: after Finish the series holds the change's $f at Google and here, or the answer names why not", $context + ['want' => $asked['fields'][$f], 'google' => $g[$f] ?? null, 'here' => $master[$col]]);
                }
            }
            if (isset($asked['fields']['tags'])) {
                $want = $asked['fields']['tags'];
                sort($want);
                $this->expect('x-finish', $master['tags'] === $want, "step {$this->step}: after Finish the series has the change's tags (its part here, review R3)", $context + ['want' => $want, 'here' => $master['tags']]);
            }
        }
        // A4: a change at Google since, to a day the change didn't touch, is kept; or, where Finish can't tell what the
        // change's own write to the series did to the days from a change made there since, the day is the change's again
        // and Finish's answer says what it replaced, under its lead sentence, with what Google held (decisions.md, "Finish
        // sets the plan's days where it can't tell its own change from another"). What Finish keeps is the
        // stand-in's state when Finish runs (series-model.md, Recovery: "Finish runs the same targets from Google's state"),
        // so a later remote step on the same day supersedes an earlier one: a day edited then cancelled at Google is
        // skipped after Finish, one cancelled then edited holds what the stand-in holds. Each such day is judged against
        // what the stand-in held for it just before Finish, never against the earliest remote step's value.
        $g0 = $s0['g']['digest'][$gid] ?? null;
        $days = [];
        foreach ($this->touches as $t) {
            if (empty($t['remote']) || $t['step'] < $p['step'] || $t['gid'] !== $gid || $g === null || $g0 === null || $t['key'] === null || !in_array($t['field'], ['title', 'description'], true) || isset($asked['fields'][$t['field']])) {
                continue;
            }
            if (array_intersect(array_keys($asked['fields']), ['time', 'length', 'count']) !== []) {
                continue; // a change of the series' time drops every record at Google: Finish can't keep what Google dropped
            }
            $days[(string) $t['key']][$t['field']] = true;
        }
        foreach ($days as $key => $fields) {
            $rec0 = $g0['records'][$key] ?? null;
            $rec1 = $g['records'][$key] ?? null;
            $skip0 = in_array($key, (array) ($g0['skips'] ?? []), true);
            $skip1 = in_array($key, (array) ($g['skips'] ?? []), true);
            $kept = $skip1 === $skip0;
            foreach (array_keys($fields) as $f) {
                $i = $f === 'title' ? 0 : 5;
                $kept = $kept && ($rec1[$i] ?? null) === ($rec0[$i] ?? null);
            }
            $this->ran('x-finish', 'A4 ' . ($skip0 ? 'a day cancelled at Google' : ($rec0 === null ? 'a day put back at Google' : 'a day edited at Google')));
            $text = implode(' ', array_map('strval', $differences));
            $held = $skip0 ? 'was deleted in Google Calendar; Finish put it back' : ($rec0 === null ? '' : (string) $rec0[isset($fields['title']) ? 0 : 5]);
            $said = in_array(self::REPLACED_LEAD, $differences, true) && ($held === '' || str_contains($text, $held));
            $this->expect('x-finish', $kept || $said,
                "step {$this->step}: Finish keeps a change made at Google since to a day the change didn't touch, or says what it replaced there, with what Google held (A4)", $context + ['key' => $key, 'before' => ['record' => $rec0, 'skipped' => $skip0], 'after' => ['record' => $rec1, 'skipped' => $skip1]]);
        }
        $this->settled($p);
    }

    private function checkUndo(string $do, array $out, array $s0, array $s1, array $asked, array $context, array $taken, ?array $uc = null): void
    {
        $entry = $out['entry'] ?? ($asked['entry'] ?? null);
        $error = $out['ok'] ? null : (string) $out['error'];
        if ($entry === null) {
            return;
        }
        $context['entry'] = $entry;
        $undone = (int) $this->db->scalar('SELECT undone FROM mutations WHERE id = ?', [$entry]) === 1;
        if ($this->heldBack((int) $entry)) {
            // With the setting off: refused 409 not_undoable with the contract's words, before anything is read or sent, with
            // nothing written (decisions.md, "Undo of a whole-series change on a Google calendar waits for 1.5.6").
            $this->ran('x-undo-fields', 'held back');
            $asked = array_filter(array_slice($this->stand->fake->requests, $s0['req'], $s1['req'] - $s0['req']), static fn(array $q): bool => !in_array('X-Harness: remote', $q['headers'], true));
            $this->expect('x-undo-fields', !$out['ok'] && $error === 'not_undoable' && ($out['message'] ?? null) === Product::SERIES_UNDO_OFF,
                "step {$this->step} ($do): an undo or redo of a whole-series change on a Google calendar is refused not_undoable with the contract's words while the setting is off", $context);
            $this->expect('x-undo-fields', !$undone && self::sameHere($s0, $s1) && $asked === [] && $s0['g'] == $s1['g'],
                "step {$this->step} ($do): the held-back undo writes nothing here and sends Google nothing, not even a read", $context + ['sent' => count($asked), 'diff' => self::diff($s0['l'], $s1['l'])]);
            return;
        }
        if ($do === 'u-bare') {
            $this->ran('x-undo-bare', 'newest own');
            // Which entry it took; what a refusal wrote is x-conflict's and x-undo-fields' to judge.
            $this->expect('x-undo-bare', $out['ok'] ? $undone : !$undone, "step {$this->step}: the bare Undo takes the newest of the account's own changes not undone, passing over an undo's own entry", $context + ['undone' => $undone,
                'entries' => array_map(static fn(array $e): string => $e['id'] . ' ' . $e['source'] . ' undone=' . $e['undone'] . ' undoes=' . (self::undoes($e['details_json']) ?? '-') . ' ' . mb_substr((string) $e['summary'], 0, 60),
                    $this->db->all('SELECT id, source, undone, summary, details_json FROM mutations WHERE user_id = ? ORDER BY id', [$this->userId]))]);
        }
        $state = $this->entryStates[$entry] ?? null;
        if (!$out['ok']) {
            $this->ran('x-undo-fields', 'refused');
            // The undo of one edit stores its read before it refuses (Slice 3a, (c)): what that refusal leaves is x-choice's.
            if ($uc === null || !in_array($error, ['google_changed', 'google_conflict', 'google_deleted'], true)) {
                $this->expect('x-undo-fields', self::sameHere($s0, $s1) && $taken === [] && $s0['g'] == $s1['g'], "step {$this->step} ($do): a refused undo ($error) writes nothing here or at Google", $context + ['diff' => self::diff($s0['l'], $s1['l'])]);
            }
            if ($uc !== null && in_array($error, ['google_changed', 'google_conflict', 'google_deleted'], true)) {
                return; // x-choice judged it
            }
            if ($state !== null && $this->changedSince($state, $this->byIdentity($s0['rows']), $uc) === []) {
                // An undo of an entry nothing has changed since goes through (decisions.md, "An undo is stale when a poll has
                // since brought a remote change of the same field" and "Staleness has one definition: grounds are fields"):
                // the only refusals left are the pending gate while a change is half done, not_undoable from the release
                // gate, and Google's refusal of a series changed there and not yet read (x-conflict's to judge).
                // An event in no series (`event:`) changed there and not yet read counts as a series does: before the single
                // block's refusals left an event's making as the newest entry over an unpolled change at Google (seed 7
                // case 260), the harness never undid one, and this list held series alone, a check defect.
                $gids = array_map(static fn(string $i): string => substr($i, strpos($i, ':') + 1), array_filter(array_keys(self::entryChange($state)), static fn(string $i): bool => str_starts_with($i, 'series:') || str_starts_with($i, 'event:')));
                $unread = array_intersect($gids, array_keys($this->dirty)) !== [] || isset($this->dirty[(string) ($asked['gid'] ?? '')]);
                // An undo that switches a series back between timed and all-day where Google would hide days is refused with
                // the change's own sentence (google_hidden_days), judged by the harness's own reading of the stand-in.
                $hides = false;
                foreach ($gids as $g) {
                    $was = $state['before']['series:' . $g] ?? null;
                    $hides = $hides || ($was !== null && $this->stand->hidesOnSwitch($g, ['start_utc' => $was['start'], 'end_utc' => $was['end'], 'all_day' => $was['allDay'], 'tzid' => $was['tz'], 'rrule' => $was['rrule']]));
                }
                // A day Google hid when another client switched the series' form at such a time (issue 266, not built
                // for): nothing is asserted about an undo of a change to it.
                $hidden = false;
                foreach (array_keys(self::entryChange($state)) as $ident) {
                    if (preg_match('/^day:([^:]+):(.+)$/', (string) $ident, $mm) === 1) {
                        $was = $state['before']['series:' . $mm[1]] ?? null;
                        $k = $mm[2];
                        $d = strlen($k) <= 10 || ($was !== null && !empty($was['allDay'])) ? substr($k, 0, 10) : Clock::local($k, (string) ($was['tz'] ?? 'UTC'))->format('Y-m-d');
                        $hidden = $hidden || isset($this->remoteHidden[$d]);
                    }
                }
                $allowed = in_array($error, ['google_pending', 'not_undoable'], true) || ($uc === null && $unread && in_array($error, ['google_conflict', 'google_deleted'], true))
                    || ($error === 'google_hidden_days' && $hides) || $hidden;
                if (!$allowed) {
                    $this->fail('x-undo-fields', $error === 'stale_undo'
                        ? "step {$this->step} ($do): an undo of the entry step {$state['step']} made was refused as stale though nothing it changed has changed since"
                        : "step {$this->step} ($do): an undo of the entry step {$state['step']} made was refused ($error) though nothing it changed has changed since",
                        ['do' => "$do $error"] + $context);
                }
            }
            return;
        }
        if ($state === null) {
            $this->ran('x-undo-fields', 'entry not recorded'); // made by a step the harness didn't record (a poll's)
            return;
        }
        // The undo of one edit stored its read first (Slice 3a, (c)): it is judged over the rows as the read left them, and the
        // fields the comparison judged are no longer the stale check's ((d)).
        $this->judgeUndo($do, $state, $uc === null ? $this->byIdentity($s0['rows']) : $this->undoReadStored($s0, $s1, $uc), $this->byIdentity($s1['rows']), $context, $uc);
    }

    /**
     * What an entry changed, read from its recorded states: identity => list of judged fields that differ, with '+' for a
     * row it made and '-' for one it removed.
     */
    private static function entryChange(array $state): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($state['before']), array_keys($state['after']))) as $ident) {
            if (in_array($ident, $state['exclude'] ?? [], true)) {
                continue;
            }
            $a = $state['before'][$ident] ?? null;
            $b = $state['after'][$ident] ?? null;
            if ($a === null) {
                $out[$ident] = ['+'];
            } elseif ($b === null) {
                $out[$ident] = ['-'];
            } else {
                $skip = $state['exclude'] ?? [];
                // "ident#field": a field a change at Google made during the step (a w-race on the master), no part of the entry.
                $f = array_values(array_filter(self::judged($ident, $a, $b), static fn(string $k): bool => ($a[$k] ?? null) != ($b[$k] ?? null) && !in_array($ident . '#' . $k, $skip, true)));
                if (($a['own'] ?? []) != ($b['own'] ?? [])) {
                    $f[] = 'own';
                }
                if ($f !== []) {
                    $out[$ident] = $f;
                }
            }
        }
        return $out;
    }

    /** A series row's start clock: its zone and its local seconds into the day. */
    private static function clockOf(array $r): array
    {
        $tz = (int) $r['allDay'] === 1 ? 'UTC' : (string) $r['tz'];
        $t = Clock::parse((string) $r['start'])->setTimezone(Clock::zone($tz));
        return [$tz, (int) $t->format('H') * 3600 + (int) $t->format('i') * 60 + (int) $t->format('s')];
    }

    /** An occurrence key of series $from carried to series $to's clock: the same local date at $to's start clock and zone. */
    private static function shiftKey(string $key, array $from, array $to): string
    {
        [$tzFrom] = self::clockOf($from);
        [$tzTo, $secTo] = self::clockOf($to);
        $local = Clock::parse($key)->setTimezone(Clock::zone($tzFrom))->setTime(0, 0)->modify('+' . $secTo . ' seconds');
        return (new \DateTimeImmutable($local->format('Y-m-d H:i:s'), Clock::zone($tzTo)))->setTimezone(Clock::utc())->format('Y-m-d H:i:s');
    }

    /**
     * $rows with every day of a series whose times or zone differ from $frame's renamed to its occurrence in $frame: a day
     * keeps its identity through a times or zone change that carried it (decisions.md, "Staleness has one definition:
     * grounds are fields": "a day a change carried to another occurrence as a move, which a later judgement follows").
     * Every judgement of an entry reads its states, the rows before an undo and after it in the frame of the rows before
     * the entry, so the entry's own times change is a change of the days' fields, not rows made and removed.
     */
    private static function inFrame(array $frame, array $rows): array
    {
        foreach ($frame as $ident => $ref) {
            if (!str_starts_with($ident, 'series:') || !isset($rows[$ident]) || ($ref['rrule'] ?? null) === null || ($rows[$ident]['rrule'] ?? null) === null) {
                continue;
            }
            $is = $rows[$ident];
            if (self::clockOf($ref) === self::clockOf($is)) {
                continue;
            }
            $prefix = 'day:' . substr($ident, strlen('series:')) . ':';
            // Its skips are dates too, carried with the days.
            $skips = array_map(static fn(string $k): string => self::shiftKey($k, $is, $ref), (array) ($is['skips'] ?? []));
            sort($skips);
            $rows[$ident]['skips'] = $skips;
            $out = [];
            foreach ($rows as $k => $r) {
                if (str_starts_with($k, $prefix)) {
                    $k = $prefix . self::shiftKey(substr($k, strlen($prefix)), $is, $ref);
                }
                $out[$k] = $r;
            }
            $rows = $out;
        }
        return $rows;
    }

    /**
     * A day row's ground: the fields it owns, by its own list (decisions.md, "An undo is stale when a poll has since brought
     * a remote change of the same field": "each side's ground the fields that row owns where it was made or removed whole").
     * For staleness its links count too, as the journal's grounds count them ($links); for what the row owns, its own list
     * alone, the tags and people it holds without owning them being its series' copy.
     */
    private static function ground(?array $row, bool $links = true): array
    {
        $map = ['title' => ['title'], 'description' => ['description'], 'location' => ['location', 'lat', 'lng'], 'url' => ['url'], 'status' => ['status'], 'showAs' => ['showAs'],
            'date' => ['start', 'end'], 'time' => ['start', 'end', 'tz'], 'length' => ['end'], 'allDay' => ['allDay'], 'tags' => ['tags'], 'people' => ['people'],
            'reminders' => ['reminders'], 'icon' => ['icon'], 'attendance' => ['attendance'], 'coords' => ['lat', 'lng'], 'lat' => ['lat', 'lng']];
        $out = [];
        foreach ((array) ($row['own'] ?? []) as $o) {
            $out = array_merge($out, $map[$o] ?? [$o]);
        }
        // Its tags and people among them: a day's row holds them in its own links (api-contract.md, `stale_undo`: "the
        // fields that row owns, its tags and people among them").
        foreach ($links ? ['tags', 'people'] : [] as $f) {
            if (!empty($row[$f])) {
                $out[] = $f;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * What stands on an entry now: the reasons its undo or redo is stale (decisions.md, "An undo is stale when a poll has since
     * brought a remote change of the same field", as settled 2026-11-09 after the second read; api-contract.md, `stale_undo`).
     * Staleness is the same field or the same day, never a dates change against a times change.
     */
    private function changedSince(array $state, array $now, ?array $uc = null): array
    {
        $since = $this->changedSinceAll($state, $now);
        if ($uc === null || !isset($since[$uc['ident']])) {
            return $since;
        }
        // Slice 3a, (d), as corrected 2026-11-16: for an entry the comparison judges, a later change to a compared field
        // this undo puts back is the comparison's, for an event and a day alike; a place is its text and its pin together.
        // Before the undo's fields are known (undoChoiceOf asks first), every compared field.
        $columns = ['title' => ['title'], 'description' => ['description'], 'location' => ['location', 'lat', 'lng'], 'url' => ['url'], 'status' => ['status'],
            'showAs' => ['showAs'], 'time' => ['start', 'end', 'allDay', 'tz']];
        $compared = [];
        foreach ($uc['F'] ?? array_keys($columns) as $f) {
            $compared = array_merge($compared, $columns[$f] ?? []);
        }
        $left = array_values(array_diff($since[$uc['ident']], $compared));
        if ($left === []) {
            unset($since[$uc['ident']]);
        } else {
            $since[$uc['ident']] = $left;
        }
        return $since;
    }

    private function changedSinceAll(array $state, array $now): array
    {
        $state['after'] = self::inFrame($state['before'], $state['after']);
        $now = self::inFrame($state['before'], $now);
        $out = [];
        $change = self::entryChange($state);
        // The days the entry is about: the rows it changed, made or removed, and the dates it skipped or brought back.
        $days = [];
        foreach ($change as $ident => $fields) {
            if (str_starts_with($ident, 'day:')) {
                $days[$ident] = true;
            } elseif (in_array('skips', $fields, true)) {
                $a = $state['before'][$ident]['skips'] ?? [];
                $b = $state['after'][$ident]['skips'] ?? [];
                foreach (array_merge(array_diff($a, $b), array_diff($b, $a)) as $k) {
                    $days['day:' . substr($ident, strlen('series:')) . ':' . $k] = true;
                }
            }
        }
        // An entry about a day no longer an occurrence of the series it names (a later "this and following" took it into a
        // series of its own, a rule or bound change dropped it) is stale before anything else (seed 419 case 852), and one
        // whose series has gone since likewise.
        // The occurrence is judged against the series as the entry left it, so an entry that itself cut the day off (a delete
        // of this and following, an answer splitting the series) is not stale by its own doing.
        $raw = static fn(array $r): array => ['start_utc' => $r['start'], 'end_utc' => $r['end'], 'rrule' => $r['rrule'], 'tzid' => $r['tz'], 'all_day' => $r['allDay']];
        foreach (array_keys($days) as $d) {
            if (preg_match('/^day:([^:]+):(.+)$/', $d, $m) !== 1 || !isset($state['after']['series:' . $m[1]])) {
                continue;
            }
            $was = $state['after']['series:' . $m[1]];
            $is = $now['series:' . $m[1]] ?? null;
            $ref = $state['before']['series:' . $m[1]] ?? $was;
            if ($is === null) {
                $out[$d] = ['series gone'];
            } elseif ($was['rrule'] !== null && $is['rrule'] !== null && Truth::isOccurrence($raw($was), self::shiftKey($m[2], $ref, $was)) && !Truth::isOccurrence($raw($is), self::shiftKey($m[2], $ref, $is))
                && !Truth::isOccurrence($raw($is), $m[2])) {
                // A day whose own key is still a day of the series is one, whatever its start's clock did: a rule that names
                // its hour (BYHOUR) keeps its days at that clock when the start moves (fresh seed 37440 case 222, the series'
                // start moved an hour at Google and stored by an undo's read; reading the day at the start's new clock found
                // none, a check defect).
                $out[$d] = ['no longer an occurrence of its series'];
            }
        }
        // The days a change of a series' rule, bound or start added are its ground: a later change on one (a row made or
        // changed at an added date) stands on it (decisions.md, "An undo of a Google series change is planned as a forward
        // change is": the days a change added are its ground, so a later edit on one makes the undo stale).
        foreach ($change as $ident => $fields) {
            if (!str_starts_with($ident, 'series:') || array_intersect($fields, ['rrule', 'start']) === [] || !isset($state['before'][$ident])) {
                continue;
            }
            $was = $state['before'][$ident];
            $left = $state['after'][$ident];
            if ($was['rrule'] === null || $left['rrule'] === null) {
                continue;
            }
            $prefix = 'day:' . substr($ident, strlen('series:')) . ':';
            $plain = static fn(?array $r): ?array => $r === null ? null : array_diff_key($r, ['id' => 1]);
            foreach ($now as $d => $row) {
                if (!str_starts_with($d, $prefix) || $plain($state['after'][$d] ?? null) == $plain($row)) {
                    continue;
                }
                $k = substr($d, strlen($prefix));
                if (Truth::isOccurrence($raw($left), self::shiftKey($k, $was, $left)) && !Truth::isOccurrence($raw($was), $k)) {
                    $out[$d] = ['a day the entry added, changed since'];
                }
            }
        }
        if ($out !== []) {
            return $out;
        }
        foreach ($change as $ident => $fields) {
            $b = $state['after'][$ident] ?? null;
            $c = $now[$ident] ?? null;
            // A series the entry made whole (a split from a day on, a redo making it again) stands on every later change to it:
            // every field of what it made is its ground, the series' own fields and skips, and every day of it (decisions.md,
            // "Staleness has one definition: grounds are fields": a row or series made whole has every field of what it made
            // as its ground; seed 17 case 202, seed 13 case 173).
            if ($fields === ['+'] && str_starts_with($ident, 'series:')) {
                if ($c === null) {
                    $out[$ident] = ['series gone'];
                    continue;
                }
                $f = array_values(array_filter(self::judged($ident, $b, $c), static fn(string $k): bool => ($b[$k] ?? null) != ($c[$k] ?? null)));
                if ($f !== []) {
                    $out[$ident] = $f;
                }
                $prefix = 'day:' . substr($ident, strlen('series:')) . ':';
                $plain = static fn(?array $r): ?array => $r === null ? null : array_diff_key($r, ['id' => 1]);
                foreach (array_unique(array_merge(array_keys($state['after']), array_keys($now))) as $d) {
                    if (str_starts_with($d, $prefix) && $plain($state['after'][$d] ?? null) != $plain($now[$d] ?? null)) {
                        $out[$d] = ['added to a series the entry made'];
                    }
                }
                continue;
            }
            if ($fields === ['+'] || $fields === ['-']) {
                // A row the entry made or removed whole: a newer change on the row at that day now stales it where the two
                // grounds meet, the fields the entry's row owns and those the newer change changed (a poll changing the
                // day's notes doesn't stale an entry about its place).
                // An event in no series made or removed whole (a create, a delete) is not a day of a series: the entry changed
                // every column of its row, so every field of it is its ground ("a row or series made whole has every field of
                // what it made as its ground", interleaving-invariants.md, x-undo-fields; decisions.md, "Staleness has one
                // definition: grounds are fields": "a column of a row"). The day-row rule (the fields the row owns) is a
                // day's. Seed 94756 case 79: an event made, then made Maybe, then its making undone, is stale.
                $mine = str_starts_with($ident, 'event:') ? self::judged($ident) : self::ground($fields === ['+'] ? $b : ($state['before'][$ident] ?? null));
                if ($fields === ['+'] && $c === null) {
                    $out[$ident] = ['row gone'];
                } elseif ($fields === ['+']) {
                    $f = array_values(array_filter($mine, static fn(string $k): bool => ($b[$k] ?? null) != ($c[$k] ?? null)));
                    if ($f !== []) {
                        $out[$ident] = $f;
                    }
                } elseif ($c !== null) {
                    $f = array_values(array_intersect($mine, self::ground($c)));
                    if ($f !== []) {
                        $out[$ident] = $f;
                    }
                }
                continue;
            }
            foreach ($fields as $f) {
                if ($f === 'skips' && str_starts_with($ident, 'series:') && $c !== null) {
                    // An ordinary day deleted or brought back stands on its series' skips alone: stale only when one of its
                    // own dates has been skipped or brought back since (seed 307 case 463: another day edited stales nothing).
                    // A skip is its own date's ground, never the list whole (decisions.md, "Staleness has one definition:
                    // grounds are fields": "a skip per date").
                    $a = $state['before'][$ident]['skips'] ?? [];
                    $mineDates = array_merge(array_diff($a, $b['skips'] ?? []), array_diff($b['skips'] ?? [], $a));
                    $g = substr($ident, strlen('series:'));
                    foreach ($mineDates as $k) {
                        // A day's row made at that date since, which skipping the date again would remove whole: the removal's
                        // ground is the fields that row owns, which meet the change that gave them (api-contract.md,
                        // `stale_undo`: "where it made or removed a day's row whole, the fields that row owns").
                        $made = $now['day:' . $g . ':' . $k] ?? null;
                        $grew = $made !== null && !isset($state['after']['day:' . $g . ':' . $k]) && self::ground($made) !== [];
                        if ($grew || in_array($k, $b['skips'] ?? [], true) !== in_array($k, $c['skips'] ?? [], true)) {
                            $out[$ident][] = 'skips';
                            break;
                        }
                    }
                    continue;
                }
                if ($f === 'own') {
                    continue; // the own list is never a ground itself, its contents are (the same entry)
                }
                // A field a day's row shows from its series on both sides (owned neither as the entry left it nor now) is
                // the series': a later change of the series' value is that change's ground, never this day's (decisions.md,
                // "Staleness has one definition: grounds are fields": a day's own field). The row may be there for
                // something else it holds (reminders, an icon); with no row left the same undo was never stale (seed 54249
                // case 109: a day kept for its reminders, its rename undone, the series renamed, the rename redone).
                if (str_starts_with($ident, 'day:') && $b !== null && $c !== null && !in_array($f, self::ground($b), true) && !in_array($f, self::ground($c), true)) {
                    continue;
                }
                if ($c === null || ($b[$f] ?? null) != ($c[$f] ?? null)) {
                    $out[$ident][] = $f;
                }
            }
        }
        return $out;
    }

    /**
     * An undo or redo of an entry, judged by the entry's own recorded states (decisions.md, "The undo of a Google edit
     * sends back only the fields that edit changed": "Each changed field goes back at its before value over the event as it
     * stands"; series-model.md, Own Fields: "Undo putting the row, its tags, people and links back as they were"). Undoing
     * an entry puts back, on the rows it changed, the judged fields and own-list entries it changed at their values before
     * it, a row it made gone and a row it removed back; every other row, and every other field of those rows, stays as it
     * was before the undo. An undo of an undo is a redo by the same rule, against the undo's own recorded states, so no
     * direction is worked out. When something the entry changed has changed again since, the undo is stale (A3).
     */
    private function judgeUndo(string $do, array $state, array $now, array $result, array $context, ?array $uc = null): void
    {
        // changedSince frames what it is given itself; framing twice is not a no-op when the frame's clock falls on another
        // local date than the series' clock (a series made timed west of UTC: 00:00 UTC is the evening before), so it gets
        // the states as recorded.
        $rawState = $state;
        $rawNow = $now;
        $state['after'] = self::inFrame($state['before'], $state['after']);
        $now = self::inFrame($state['before'], $now);
        $result = self::inFrame($state['before'], $result);
        // A day renamed at Google while this undo or redo ran ("race": "day", fixed cases) is someone else's change, judged
        // by x-readback: kept out of the comparison here as a w-race's day is kept out of its entry.
        if (($this->racedNow[1] ?? null) !== null) {
            $day = 'day:' . $this->racedNow[0] . ':' . $this->racedNow[1];
            unset($now[$day], $result[$day], $rawNow[$day]);
            $state['exclude'] = array_merge($state['exclude'] ?? [], [$day]);
            $rawState['exclude'] = $state['exclude'];
        }
        $change = self::entryChange($state);
        $since = $this->changedSince($rawState, $rawNow, $uc);
        if ($since !== []) {
            $this->ran('x-undo-fields', 'stale');
            $this->fail('x-undo-fields', "step {$this->step} ($do): an undo went through though what its entry changed has changed since (step {$state['step']}): stale_undo by the rule (A3)", $context + ['since' => $since]);
            return;
        }
        $this->ran('x-undo-fields', 'by recorded states');
        foreach (array_unique(array_merge(array_keys($now), array_keys($result), array_keys($change))) as $ident) {
            $c = $now[$ident] ?? null;
            $r = $result[$ident] ?? null;
            $fields = $change[$ident] ?? null;
            $where = $context + ['identity' => $ident];
            if ($fields === null && $c === null && $r !== null && preg_match('/^day:([^:]+):(.+)$/', $ident, $m) === 1
                && in_array('skips', $change['series:' . $m[1]] ?? [], true)) {
                // A day on a date the entry skipped or brought back, with no row before the undo and one after: brought
                // back as the occurrence the series has there now, owning nothing (decisions.md, "The undo of a Google edit
                // sends back only the fields that edit changed": a row brought back is "the series' occurrence there (the
                // series' current notes)", never the series as a snapshot showed it).
                $series = $result['series:' . $m[1]] ?? null;
                $this->expect('x-undo-fields', ($r['own'] ?? []) === [], "step {$this->step} ($do): a day brought back on a date its entry skipped owns fields of its own", $where + ['now' => $r]);
                foreach (['title', 'description', 'location', 'url', 'showAs', 'tags'] as $f) {
                    if ($series !== null && !in_array($f === 'tags' ? 'tags' : $f, (array) ($r['own'] ?? []), true)) {
                        $this->expect('x-undo-fields', ($r[$f] ?? null) == ($series[$f] ?? null), "step {$this->step} ($do): a day brought back on a date its entry skipped shows the series' $f as it stands", $where + ['field' => $f, 'series' => $series[$f] ?? null, 'now' => $r[$f] ?? null]);
                    }
                }
                continue;
            }
            if ($fields === null) {
                // A row the entry didn't change: exactly as it was before the undo, every field and its own list. But a day's
                // tags and people it does not own are its series' copy, not its own value: "a day's tags and people are its
                // own only when its own list says so; otherwise its links are the series' copy", and a day's row an undo
                // keeps "takes the tags and people it doesn't own from its series as it stands" (decisions.md, "A day kept by
                // an undo shows the series' tags and people it does not own"; series-model.md, Own Fields: "Every other
                // field is inherited and follows the series"). So such a set is expected to be the series' set after the
                // undo, which is what x-own checks, and an owned set to be exactly as it was (seed 40783 case 201).
                $keep = $c === null || $r === null ? [] : array_flip(array_merge(self::judged($ident, $c, $r), ['own']));
                $series = preg_match('/^day:([^:]+):/', $ident, $m) === 1 ? ($result['series:' . $m[1]] ?? null) : null;
                $copied = [];
                if ($c !== null && $r !== null && $series !== null) {
                    foreach (['tags', 'people'] as $f) {
                        if (!in_array($f, (array) ($c['own'] ?? []), true) && !in_array($f, (array) ($r['own'] ?? []), true)) {
                            $copied[] = $f;
                            unset($keep[$f]);
                        }
                    }
                }
                $plain = static fn(?array $x): ?array => $x === null ? null : array_intersect_key($x, $keep);
                $this->expect('x-undo-fields', ($c === null) === ($r === null) && $plain($c) == $plain($r), "step {$this->step} ($do): an undo changed a row its entry did not change", $where + ['was' => $c, 'now' => $r]);
                foreach ($copied as $f) {
                    $this->ran('x-undo-fields', "a day's $f it does not own, after an undo of another row's entry");
                    $this->expect('x-undo-fields', ($r[$f] ?? null) == ($series[$f] ?? null), "step {$this->step} ($do): a day the entry did not change holds other $f than its series' as they stand after the undo, though it does not own them",
                        $where + ['field' => $f, 'series' => $series[$f] ?? null, 'now' => $r[$f] ?? null]);
                }
                continue;
            }
            if ($fields === ['+']) {
                // The undo takes back only what the entry gave the row it made, the fields that row owned; the row stays,
                // following its series in those fields, when a later change gave it something of its own (decisions.md,
                // "An undo is stale when a poll has since brought a remote change of the same field", the third read F2).
                // What a row owns is its own list: tags and people it holds without owning them are its series' copy.
                $gave = self::ground($state['after'][$ident] ?? null, false);
                $later = $c === null ? [] : array_values(array_diff(self::ground($c, false), $gave));
                if ($r === null || $later === []) {
                    $this->expect('x-undo-fields', $r === null || array_intersect(self::ground($r, false), $gave) === [], "step {$this->step} ($do): an undo left a row its entry made", $where + ['now' => $r]);
                    continue;
                }
                $this->expect('x-undo-fields', array_intersect(self::ground($r, false), $gave) === [], "step {$this->step} ($do): an undo left the row its entry made owning what the entry gave it", $where + ['gave' => $gave, 'now' => $r]);
                // The row it keeps shows the series' tags and people as they stand where it doesn't own them, never an
                // empty set left by taking away the copy made with it (series-model.md, Own Fields).
                $series = preg_match('/^day:([^:]+):/', $ident, $mm) === 1 ? ($result['series:' . $mm[1]] ?? null) : null;
                foreach ($series === null ? [] : ['tags', 'people'] as $f) {
                    if (!in_array($f, (array) ($r['own'] ?? []), true)) {
                        $want = (array) ($series[$f] ?? []);
                        $got = (array) ($r[$f] ?? []);
                        sort($want);
                        sort($got);
                        $this->expect('x-undo-fields', $got == $want, "step {$this->step} ($do): the row its entry made, kept for a later change, shows the series' $f as they stand", $where + ['field' => $f, 'want' => $want, 'now' => $got]);
                    }
                }
                $b0 = $state['after'][$ident] ?? [];
                foreach ($later as $f) {
                    // What the later change gave: a list's additions since the entry stay; a value stays as it was.
                    $kept = is_array($c[$f] ?? null) ? array_diff((array) $c[$f], (array) ($b0[$f] ?? [])) === array_intersect(array_diff((array) $c[$f], (array) ($b0[$f] ?? [])), (array) ($r[$f] ?? []))
                        : ($r[$f] ?? null) == ($c[$f] ?? null);
                    $this->expect('x-undo-fields', $kept, "step {$this->step} ($do): an undo took from the row its entry made what a later change gave it", $where + ['field' => $f, 'was' => $c[$f] ?? null, 'now' => $r[$f] ?? null]);
                }
                continue;
            }
            $was = $state['before'][$ident];
            if ($fields === ['-']) {
                $this->expect('x-undo-fields', $r !== null, "step {$this->step} ($do): an undo did not bring back a row its entry removed", $where + ['was' => $was]);
                if ($r === null) {
                    continue;
                }
                if ($c !== null) {
                    // A row at that day now (Google made one since): the row brought back is composed over it, what it
                    // held here alone at its values then, everything else as the day stands now, its own list the union
                    // (decisions.md, "The undo of a Google edit sends back only the fields that edit changed": "over the
                    // day as it stands now, the row at that day now (a title Google gave it stays its own)").
                    $held = ['tags', 'people', 'reminders', 'icon', 'attendance', 'lat', 'lng', 'links'];
                    foreach (self::judged($ident, $was, $c, $r) as $f) {
                        $want = in_array($f, $held, true) ? ($was[$f] ?? null) : ($c[$f] ?? null);
                        $this->expect('x-undo-fields', ($r[$f] ?? null) == $want, "step {$this->step} ($do): a row brought back over the day as it stands has the $f composed", $where + ['field' => $f, 'want' => $want, 'now' => $r[$f] ?? null]);
                    }
                    $wantOwn = array_values(array_unique(array_merge((array) ($c['own'] ?? []), array_values(array_intersect((array) ($was['own'] ?? []), self::LOCAL)))));
                    sort($wantOwn);
                    $gotOwn = (array) ($r['own'] ?? []);
                    sort($gotOwn);
                    $this->expect('x-undo-fields', $gotOwn == $wantOwn, "step {$this->step} ($do): a row brought back over the day as it stands owns what both held", $where + ['want' => $wantOwn, 'now' => $gotOwn]);
                    continue;
                }
                $fields = self::judged($ident, $was, $r);
                $fields[] = 'own';
            }
            // Ownership: for the own-list entries the entry changed, as before it; the rest as before the undo.
            $flipped = array_merge(array_diff((array) ($was['own'] ?? []), (array) ($state['after'][$ident]['own'] ?? [])), array_diff((array) ($state['after'][$ident]['own'] ?? []), (array) ($was['own'] ?? [])));
            $wantOwn = array_values(array_unique(array_merge(array_diff((array) ($c['own'] ?? $was['own'] ?? []), $flipped), array_intersect($flipped, (array) ($was['own'] ?? [])))));
            sort($wantOwn);
            if (str_starts_with($ident, 'day:')) {
                // The retention rule (series-model.md, Own Fields: a row that owns nothing and holds nothing here alone is an
                // ordinary occurrence again): a day row the entry only changed goes exactly when what the undo leaves of it
                // owns nothing and no trip link names it, and stays otherwise.
                $links = in_array('links', $fields, true) ? ($was['links'] ?? []) : ($c['links'] ?? $was['links'] ?? []);
                $goes = $wantOwn === [] && empty($links);
                $this->expect('x-undo-fields', $r !== null || $goes, "step {$this->step} ($do): an undo removed a row its entry only changed, though what the undo leaves of it owns or holds something", $where + ['was' => $c, 'own' => $wantOwn, 'links' => $links]);
                $this->expect('x-undo-fields', $r === null || !$goes, "step {$this->step} ($do): an undo left a row that owns nothing and holds nothing here alone, an ordinary occurrence again", $where + ['now' => $r]);
                $this->ran('x-undo-fields', $goes ? 'row left owning nothing' : 'row left owning something');
            } else {
                $this->expect('x-undo-fields', $r !== null, "step {$this->step} ($do): an undo removed a row its entry only changed", $where + ['was' => $c]);
            }
            if ($r === null) {
                continue;
            }
            foreach ($fields as $f) {
                if ($f === 'own') {
                    continue;
                }
                if ($f === 'skips' && $c !== null) {
                    // Skips go back by their delta over the list as it stands: each date the entry skipped or brought back
                    // toggled back, every other date as before the undo (decisions.md, F1: "a skip is its own date's").
                    $after = (array) ($state['after'][$ident]['skips'] ?? []);
                    $added = array_diff($after, (array) ($was['skips'] ?? []));
                    $removed = array_diff((array) ($was['skips'] ?? []), $after);
                    $want = array_values(array_unique(array_merge(array_diff((array) ($c['skips'] ?? []), $added), $removed)));
                    sort($want);
                    $got = (array) ($r['skips'] ?? []);
                    sort($got);
                    $this->expect('x-undo-fields', $got == $want, "step {$this->step} ($do): an undo puts back the skips its entry changed by their delta", $where + ['field' => $f, 'want' => $want, 'now' => $got]);
                    continue;
                }
                if (in_array($f, ['key', 'start', 'end'], true) && preg_match('/^day:([^:]+):/', $ident, $mm) === 1 && isset($state['after']['series:' . $mm[1]], $now['series:' . $mm[1]])
                    && self::clockOf($state['after']['series:' . $mm[1]]) !== self::clockOf($now['series:' . $mm[1]])) {
                    continue; // carried to its occurrence now by a times change since (the same entry: "a day's edit undone after one goes back at the day's occurrence now")
                }
                $master = preg_match('/^day:([^:]+):/', $ident, $mm) === 1 ? ($result['series:' . $mm[1]] ?? null) : null;
                if ($master !== null && isset(self::OWN_OF[$f]) && array_intersect(self::OWN_OF[$f], (array) ($was['own'] ?? [])) === []) {
                    // A field the day did not own before the entry (it followed its series): back to following it, at the
                    // series' value as it stands now, never the value an old snapshot showed (decisions.md, "The undo of a
                    // Google edit sends back only the fields that edit changed": "one it did not own (it followed its
                    // series) goes back to following it, at the series' value there now, so a day given its own title and
                    // undone after a rename for all events shows the new name"). A field it owned goes back exactly, below.
                    $want = $master[$f] ?? null;
                    $got = $r[$f] ?? null;
                    if (in_array($f, ['tags', 'people'], true)) {
                        // A set of links: compared as a set.
                        $want = (array) $want;
                        $got = (array) $got;
                        sort($want);
                        sort($got);
                    }
                    $this->expect('x-undo-fields', $got == $want, "step {$this->step} ($do): an undo gives back to the series a $f its entry gave the day, at the series' $f as it stands", $where + ['field' => $f, 'want' => $want, 'now' => $got]);
                    continue;
                }
                $want = $was[$f] ?? null;
                $got = $r[$f] ?? null;
                if (in_array($f, ['tags', 'people'], true)) {
                    // A set of links: compared as a set.
                    $want = (array) $want;
                    $got = (array) $got;
                    sort($want);
                    sort($got);
                }
                $this->expect('x-undo-fields', $got == $want, "step {$this->step} ($do): an undo puts back the exact $f its entry changed", $where + ['field' => $f, 'want' => $want, 'now' => $got]);
            }
            $gotOwn = (array) ($r['own'] ?? []);
            sort($gotOwn);
            $this->expect('x-undo-fields', $gotOwn == $wantOwn, "step {$this->step} ($do): an undo leaves the row's own list as its entry found it", $where + ['want' => $wantOwn, 'now' => $gotOwn]);
            // The judged fields the entry didn't change: as before the undo.
            if ($c !== null) {
                foreach (array_diff(self::judged($ident, $c, $r), $fields) as $f) {
                    $this->expect('x-undo-fields', ($r[$f] ?? null) == ($c[$f] ?? null), "step {$this->step} ($do): an undo changed a field its entry did not change", $where + ['field' => $f, 'was' => $c[$f] ?? null, 'now' => $r[$f] ?? null]);
                }
            }
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
        if ($base !== $s0['rows']) {
            // Only the change's own series: anything else (an event of its own made or undone since) is the steps between's.
            $series = array_keys($this->recovered['gids'] ?? []);
            $masterIds = array_keys(array_filter($base, static fn(array $r): bool => $r['recurrence_parent_id'] === null && in_array((string) $r['google_event_id'], $series, true)));
            $base = array_filter($base, static fn(array $r, int $id): bool => in_array($id, $masterIds, true) || in_array((int) ($r['recurrence_parent_id'] ?? 0), $masterIds, true), ARRAY_FILTER_USE_BOTH);
        }
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
                // An event in no series is its row, not a day of anything: its own tag moves with it when its date moves (a
                // poll storing Google's new date, a drag here), and is gone only when its row no longer holds it.
                $after = $s1['rows'][$rowId] ?? null;
                if (in_array($do, self::UNDO_KINDS, true) && isset($this->singles[(string) ($asked['gid'] ?? '')]) && $this->singles[(string) $asked['gid']] === $rowId) {
                    // The undo of a change to an event in no series reaches the event itself, on whatever date its change
                    // left it (an undo's allowance is keyed by the date the change was asked on).
                    continue;
                }
                if ($after !== null && $after['recurrence_parent_id'] === null && $after['rrule'] === null
                    && isset(self::alone([$rowId => $after])[self::dayOf($after, null)][$item])) {
                    continue;
                }
                $row = $s0['rows'][$rowId] ?? null;
                $master = $row !== null && $row['recurrence_parent_id'] !== null ? ($s1['rows'][(int) $row['recurrence_parent_id']] ?? null) : null;
                if (str_starts_with($item, 'coords:') && $row !== null && self::shownPlace($s1['rows'], $row, $master, $day) !== (string) $row['location']) {
                    // A1: coordinates are kept while the day's shown place text is unchanged; the text changed in this
                    // step (whoever changed it, a record Google dropped among them), so they go with the old text.
                    continue;
                }
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
    /** The place text a day shows after a step: its row's on that day of the same series, else the series'. */
    private static function shownPlace(array $rows, array $before, ?array $master, string $day): string
    {
        foreach ($rows as $r) {
            if ((string) $r['uid'] === (string) $before['uid'] && $r['recurrence_parent_id'] !== null && self::dayOf($r, $master) === $day) {
                return (string) $r['location'];
            }
        }
        return (string) ($master['location'] ?? '');
    }

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
            if (in_array($k, ['del1', 'delfollow', 'undo', 'redo', 'finish', 'putback', 'create', 'single-del'], true)) {
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

    /**
     * Google's id of an instance, from the rule alone (audit-155-identity): the series id and the key's date when the
     * series is all-day (the key is that date at 00:00:00 UTC), else the key as UTC basic time with Z.
     */
    private static function googleIdOf(string $series, string $key, bool $allDay): string
    {
        return $series . '_' . (new \DateTimeImmutable($key, new \DateTimeZone('UTC')))->format($allDay ? 'Ymd' : 'Ymd\THis\Z');
    }

    /** x-structure, x-identity (every day row, half-done changes aside), x-own and x-follow, on every live series not changed at Google unpolled. */
    private function checkStructure(string $do, array $s1, array $context): void
    {
        if ($s1['half'] !== []) {
            return; // what Finish or Put back will settle
        }
        $rows = $s1['rows'];
        $this->ran('x-structure', $do);
        // x-identity: a day row's stored Google id is the id of its key under its master's form (rule 1, I1), worked out
        // here (googleIdOf), never by GoogleWriter. Not skipped for a series changed at Google unpolled: the rows here
        // agree among themselves whatever Google holds, and a poll that changes the form must give each day its new id.
        $this->ran('x-identity', $do);
        foreach ($rows as $id => $r) {
            $master = $r['recurrence_parent_id'] !== null ? ($rows[(int) $r['recurrence_parent_id']] ?? null) : null;
            if ($master === null || empty($r['google_event_id']) || empty($master['google_event_id']) || $r['recurrence_instance_utc'] === null) {
                continue;
            }
            $want = self::googleIdOf((string) $master['google_event_id'], (string) $r['recurrence_instance_utc'], (int) $master['all_day'] === 1);
            if ((string) $r['google_event_id'] !== $want) {
                $this->fail('x-identity', "step {$this->step} ($do): a day row's stored Google id is not its key's id under its series' form",
                    $context + ['row' => $id, 'stored' => $r['google_event_id'], 'want' => $want, 'key' => $r['recurrence_instance_utc'], 'allDay' => (int) $master['all_day'], 'own' => $r['own']]);
            }
        }
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
            foreach (['title' => 'title', 'description' => 'description', 'location' => 'location', 'url' => 'url', 'status' => 'status', 'show_as' => 'showAs'] as $f => $ownName) {
                if (!in_array($ownName, $r['own'], true) && (string) $r[$f] !== (string) $master[$f]) {
                    $this->fail($onlyLocal ? 'x-follow' : 'x-own', "step {$this->step} ($do): an edited day that doesn't own its $f has another than its series'", $context + ['row' => $id, 'own' => $r['own'], 'day' => (string) $r[$f], 'series' => (string) $master[$f]]);
                }
            }
            // Tags and people as sets (the snapshot keeps each sorted): a day's are its own only when its list says so,
            // otherwise exactly its series' as they stand (the link rule, audit-155-links).
            foreach (['tags', 'people'] as $f) {
                if (!in_array($f, $r['own'], true) && $r[$f] !== $master[$f]) {
                    $this->fail($onlyLocal ? 'x-follow' : 'x-own', "step {$this->step} ($do): an edited day that doesn't own its $f holds another set than its series'", $context + ['row' => $id, 'own' => $r['own'], 'day' => $r[$f], 'series' => $master[$f]]);
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
                $this->fail('x-agree', "step {$this->step} ($do): the rows here and Google disagree on " . (isset($l['single']) ? 'an event' : 'a series'), $context + ['gid' => $gid, 'google' => $g, 'here' => $l]);
            }
        }
        foreach ($s1['g']['digest'] as $gid => $g) {
            if ($g !== null && !isset($here[$gid])) {
                $this->fail('x-agree', "step {$this->step} ($do): Google holds " . (isset($g['single']) ? 'an event' : 'a series') . " of the case that has no row here", $context + ['gid' => $gid]);
            }
        }
        $before = $s1['l'];
        $token = $this->db->scalar('SELECT google_sync_token FROM calendars WHERE id = ?', [$this->stand->calendarId]);
        try {
            $this->stand->poll();
            $this->db->run('UPDATE calendars SET google_sync_token = NULL WHERE id = ?', [$this->stand->calendarId]);
            $this->stand->poll();
        } catch (\Throwable $e) {
            if ($this->stand->knobs->listed !== 'empty') {
                $this->fail('x-agree', "step {$this->step} ($do): a poll after the step failed: " . get_class($e), $context + ['message' => mb_substr($e->getMessage(), 0, 300)]);
            }
            // An empty whole read is refused, as x-empty requires: put the position back as the step left it.
            $this->db->run('UPDATE calendars SET google_sync_token = ? WHERE id = ?', [$token, $this->stand->calendarId]);
            $this->stand->knobs->listed = null;
            return;
        }
        $this->stand->knobs->listed = null;
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
                // The row itself, so a finding says which day came or went.
                $out[$id] = isset($a[$id]) ? ['removed' => $a[$id]] : ['added' => $b[$id]];
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
