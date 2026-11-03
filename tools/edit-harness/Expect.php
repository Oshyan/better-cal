<?php
// The independent oracle (README, The Oracle): what a whole-series edit
// should do, computed from the design's definitions (series-model.md,
// What a Drag Does, by Family; Every Kind of Edit; Mapping, Defined;
// Naming the Landed Day; Bounds; Zones, DST and All-Day Days) with the
// harness's own rule reading (Shape). Each reading the design offers is
// worked out on its own, so the one the case chose is checked against its
// own expected occurrences. No call into Domain/Series, and nothing here
// reads the plan except the choices the case made.
declare(strict_types=1);

namespace BetterCal\EditHarness;

final class Expect
{
    /** How far an unbounded series is walked (days), by unit, as Truth reads it. */
    private const WINDOW = ['day' => 740, 'week' => 740, 'month' => 2960, 'year' => 9150];

    private array $o;
    private array $e;
    private Shape $s0;
    public int $actedDn = 0;
    public int $landedDn = 0;
    public int $delta = 0;
    public int $clock1 = 0;
    public string $tz1 = 'UTC';
    public bool $allDay1 = false;
    public int $len1 = 0;
    public string $kind = 'fields';
    /** Zone readings offered: clock and moment differ. */
    public bool $zoneGroup = false;
    private int $momentDn = 0;
    private int $momentSecs = 0;
    public ?\DateTimeImmutable $momentAt = null;
    /** @var array<string,int> old key => old local day */
    private array $oldDn = [];
    /** Notes on parts the design leaves open, for the report. */
    public array $unoracled = [];

    public function __construct(array $old, array $edit)
    {
        // A series stored from the period before a first day in a skipped hour
        // (Zones, DST and All-Day Days): its stored day, skipped, holds the
        // clock and is no day of the series, so the series is read from its
        // first shown day, its COUNT without the stored day.
        $raw = $old['raw'];
        if (!$old['allDay'] && count($raw) >= 2 && $raw[0]['key'] === (string) ($old['row']['start_utc'] ?? '') && in_array($raw[0]['key'], $old['exdates'], true)
            && Clock::inGap($raw[1]['dn'], $old['clock'], $old['tz'])) {
            $old['raw'] = array_values(array_slice($raw, 1));
            $old['exdates'] = array_values(array_diff($old['exdates'], [$raw[0]['key']]));
            $old['startDn'] = $raw[1]['dn'];
            if ($old['shape']->count !== null) {
                $old['shape'] = clone $old['shape'];
                $old['shape']->count--;
            }
        }
        $this->o = $old;
        $this->e = $edit;
        $this->s0 = $old['shape'];
        foreach ($old['raw'] as $occ) {
            $this->oldDn[$occ['key']] = $occ['dn'];
        }
        $this->landed();
        $this->classify();
    }

    /** Where the day acted on landed: Δ, the new clock, zone, all-day flag and length (Changing the Series from an Edited Day for an edited day). */
    private function landed(): void
    {
        $o = $this->o;
        $e = $this->e;
        $this->actedDn = $this->oldDn[$e['actedKey']] ?? Clock::dayOf($e['actedKey'], $o['tz']);
        $this->landedDn = $this->actedDn;
        $this->clock1 = $o['clock'];
        $this->tz1 = $o['tz'];
        $this->allDay1 = $o['allDay'];
        $this->len1 = $o['len'];
        $sent = $e['sent'];
        if ($sent === null) {
            return;
        }
        $this->allDay1 = $sent['allDay'] ?? $o['allDay'];
        $this->tz1 = $this->allDay1 ? 'UTC' : ($sent['tz'] ?? $o['tz']);
        $start = Clock::parse($sent['start']);
        $end = Clock::parse($sent['end']);
        $this->len1 = max(0, $end->getTimestamp() - $start->getTimestamp());
        // Every delta is measured from where the occurrence shows (S: its
        // edited row's date, clock, zone and length when it has one, else
        // its key in the series' zone) to where it was put (S'), whichever
        // id the request came through: Δ in whole days, each read in its
        // own zone; the clock change on those clocks; the length change.
        $shown = $e['shown'] ?? ['start' => $e['actedKey'], 'end' => Clock::db(Clock::parse($e['actedKey'])->modify('+' . $o['len'] . ' seconds')), 'tz' => $o['tz'], 'allDay' => $o['allDay']];
        $this->seenTz = $shown['allDay'] ? 'UTC' : $shown['tz'];
        $this->seenStart = $shown['start'];
        $shownLen = Clock::parse($shown['end'])->getTimestamp() - Clock::parse($shown['start'])->getTimestamp();
        if ($this->allDay1 === $o['allDay'] && $this->allDay1 === $shown['allDay']) {
            $this->len1 = max(0, $o['len'] + ($this->len1 - $shownLen));
        }
        $this->readTz = $this->allDay1 ? 'UTC' : ($sent['tz'] ?? $this->seenTz);
        [$this->landedDn, $this->clock1] = $this->landAt($start);
    }

    private string $seenTz = 'UTC';
    private string $seenStart = '';
    private string $readTz = 'UTC';

    /** Where the occurrence lands when S' is $start: its day and the series' new clock. */
    private function landAt(\DateTimeImmutable $start): array
    {
        $o = $this->o;
        $read = $start->setTimezone(Clock::zone($this->readTz));
        $seen = Clock::local($this->seenStart, $this->seenTz);
        $days = Clock::dnOf($read) - Clock::dnOf($seen);
        if ($this->allDay1) {
            return [$this->actedDn + $days, 0];
        }
        $shift = $o['allDay'] ? 0 : Clock::secsOf($read) - Clock::secsOf($seen);
        $clock = $o['allDay'] ? Clock::secsOf($read) : $o['clock'] + $shift;
        $over = intdiv($clock - (($clock % 86400) + 86400) % 86400, 86400);
        return [$this->actedDn + $days + $over, (($clock % 86400) + 86400) % 86400];
    }

    private function classify(): void
    {
        $o = $this->o;
        $this->delta = $this->landedDn - $this->actedDn;
        $timeChanged = $this->clock1 !== $o['clock'] || $this->tz1 !== $o['tz'] || $this->allDay1 !== $o['allDay'] || $this->len1 !== $o['len'];
        if ($this->e['rule'] === 'new') {
            $this->kind = 'newrule';
        } elseif ($this->delta !== 0) {
            $this->kind = 'drag';
        } elseif ($timeChanged) {
            $this->kind = 'time';
        } elseif ($this->e['boundSent'] !== null) {
            $this->kind = 'bound';
        }
        // A zone change: the same clock in the new zone, or the same moment
        // (Zones, DST and All-Day Days). The moment is the wall clock where
        // it was put read on the clock it showed on, so the occurrence
        // keeps its instant; its deltas are then measured as any others.
        if ($this->e['sent'] !== null && $this->tz1 !== $o['tz'] && !$o['allDay'] && !$this->allDay1 && $this->readTz !== $this->seenTz) {
            $put = Clock::parse($this->e['sent']['start'])->setTimezone(Clock::zone($this->readTz));
            $this->momentAt = Clock::at(Clock::dnOf($put), Clock::secsOf($put), $this->seenTz);
            [$this->momentDn, $this->momentSecs] = $this->landAt($this->momentAt);
            $this->zoneGroup = $this->momentDn !== $this->landedDn || $this->momentSecs !== $this->clock1;
        }
    }

    /** The edit under the zone reading chosen: 'moment' makes it a drag by its Δ at the moment's clock. */
    public function withZone(?string $zone): self
    {
        if ($zone !== 'moment' || !$this->zoneGroup) {
            return $this;
        }
        $c = clone $this;
        $c->landedDn = $this->momentDn;
        $c->clock1 = $this->momentSecs;
        $c->delta = $c->landedDn - $c->actedDn;
        $c->kind = $this->kind === 'newrule' ? 'newrule' : ($c->delta !== 0 ? 'drag' : 'time');
        return $c;
    }

    // ---- capability --------------------------------------------------------

    /** Why the whole edit is refused by the rule's capability (Imported Rules, by Capability), or null. */
    public function refusal(): ?string
    {
        $o = $this->o;
        $cap = $this->s0->cap;
        $moves = $this->delta !== 0;
        $zone = $this->tz1 !== $o['tz'];
        $clock = $this->clock1 !== $o['clock'] && !$this->allDay1 && !$o['allDay'];
        $allDay = $this->allDay1 !== $o['allDay'];
        if ($cap === 'unreadable') {
            // Field changes only: no day, time, zone, all-day, length or bound change, and no new rule.
            return $this->kind !== 'fields' || $this->len1 !== $o['len'] ? 'unreadable' : null;
        }
        if ($this->kind === 'newrule') {
            return null;
        }
        if ($cap === 'several' && ($allDay || $moves || $zone || $clock)) {
            return 'several';
        }
        if (($cap === 'weekno' || $cap === 'dayset') && $moves) {
            return $cap;
        }
        return null;
    }

    // ---- readings ----------------------------------------------------------

    /**
     * The readings the design offers for this edit, in its order (the
     * preselected one first where open decision 1 settles it), each a
     * transformation: ['id', 'enabled', 'why', 'T'].
     *
     * @return list<array>
     */
    public function readings(): array
    {
        if ($this->kind === 'newrule') {
            return $this->newRuleReadings();
        }
        if ($this->delta === 0) {
            return [self::reading('same', ['type' => 'same'])];
        }
        $s = $this->s0;
        $a = $this->actedDn;
        $l = $this->landedDn;
        $d = $this->delta;
        if ($s->freq === 'DAILY' && !$s->dailyList) {
            return [self::reading('follow', ['type' => 'shift', 'moved' => null, 'delta' => $d])];
        }
        if ($s->freq === 'WEEKLY' || $s->dailyList) {
            if (count($s->wds) === 1) {
                return [self::reading('follow', ['type' => 'shift', 'moved' => null, 'delta' => $d])];
            }
            $j = $s->memberOf($a);
            $member = self::reading('member', ['type' => 'shift', 'moved' => $j, 'delta' => $d]);
            $newWd = ((Days::wd($a) + $d) % 7 + 7) % 7;
            $others = array_values(array_diff($s->wds, [$s->wds[$j ?? 0]]));
            if ($d % 7 !== 0 && in_array($newWd, $others, true)) {
                $member = self::disabled($member, 'member_taken');
            } elseif ($s->interval > 1 && !$this->weekStartHolds($member['T'])) {
                $member = self::disabled($member, 'off_weeks');
            }
            return [$member, self::reading('pattern', ['type' => 'shift', 'moved' => null, 'delta' => $d])];
        }
        [$ly, $lm, $ld] = Days::ymd($l);
        $llen = Days::monthLen($ly, $lm);
        if ($s->freq === 'MONTHLY') {
            $k = Days::miOf($l) - Days::miOf($a);
            $j = $s->memberOf($a) ?? 0;
            $old = $s->forms[$j];
            if (count($s->forms) === 1) {
                $out = [];
                foreach ($this->landedForms($old, $l, $s->bare) as [$id, $form]) {
                    $out[] = self::reading($id, ['type' => 'forms', 'forms' => [$form], 'k' => [$k]]);
                }
                return $out;
            }
            $n = count($s->forms);
            if ($old[0] === 'pos') {
                return [$this->setPosition($k)];
            }
            $ks = array_fill(0, $n, 0);
            $ks[$j] = $k;
            if ($old[0] === 'd') {
                [, , $ad] = Days::ymd($a);
                $newJ = $old[1] > 0 ? ['d', $ld] : ['d', $ld - $llen - 1];
                $forms = $s->forms;
                $forms[$j] = $newJ;
                $member = self::reading('member', ['type' => 'forms', 'forms' => $forms, 'k' => $ks]);
                $same = $newJ == $old;
                if (!$same && $this->taken($forms, $j)) {
                    $member = self::disabled($member, 'member_taken');
                } elseif ($k % $s->interval !== 0) {
                    $member = self::disabled($member, 'interval_month');
                }
                $dd = $ld - $ad;
                $pforms = [];
                $bad = false;
                foreach ($s->forms as $f) {
                    $x = $f[1] + $dd;
                    $bad = $bad || ($f[1] > 0 ? ($x < 1 || $x > 31) : ($x > -1 || $x < -31));
                    $pforms[] = ['d', $x];
                }
                $pattern = self::reading('pattern', ['type' => 'forms', 'forms' => $pforms, 'k' => array_fill(0, $n, $k)]);
                if ($bad) {
                    $pattern = self::disabled($pattern, 'past_month_end');
                } elseif ($this->taken($pforms, -1)) {
                    $pattern = self::disabled($pattern, 'member_taken');
                }
                return [$member, $pattern];
            }
            // An nth-weekday list.
            $cl = $old[1] > 0 ? intdiv($ld - 1, 7) + 1 : -(intdiv($llen - $ld, 7) + 1);
            $cl = $cl === 5 ? -1 : ($cl === -5 ? 1 : $cl);
            $newJ = ['nth', $cl, Days::wd($l)];
            $forms = $s->forms;
            $forms[$j] = $newJ;
            $member = self::reading('member', ['type' => 'forms', 'forms' => $forms, 'k' => $ks]);
            if ($newJ != $old && $this->taken($forms, $j)) {
                $member = self::disabled($member, 'member_taken');
            } elseif ($k % $s->interval !== 0) {
                $member = self::disabled($member, 'interval_month');
            }
            $dc = ($old[1] > 0) === ($cl > 0) ? $cl - $old[1] : null;
            $dw = Days::wd($l) - $old[2];
            $pforms = [];
            $bad = $dc === null;
            foreach ($s->forms as $f) {
                $x = $f[1] + (int) $dc;
                $bad = $bad || $x === 0 || abs($x) > 4 || ($x > 0) !== ($f[1] > 0);
                $pforms[] = ['nth', $x, (($f[2] + $dw) % 7 + 7) % 7];
            }
            $pattern = self::reading('pattern', ['type' => 'forms', 'forms' => $pforms, 'k' => array_fill(0, $n, $k)]);
            if ($bad || $this->taken($pforms, -1)) {
                $pattern = self::disabled($pattern, 'count_range');
            }
            return [$member, $pattern];
        }
        // Yearly.
        [$ay, $am] = Days::ymd($a);
        $k = $ly - $ay;
        $j = $s->memberOf($a) ?? 0;
        $mIdx = intdiv($j, count($s->forms));
        $dm = $lm - $s->months[$mIdx];
        $months = array_map(static fn(int $m): int => $m + $dm, $s->months);
        foreach ($months as $m) {
            if ($m < 1 || $m > 12) {
                return [self::disabled(self::reading('follow', ['type' => 'same']), 'year_cross')];
            }
        }
        $old = $s->forms[0];
        if ($old[0] === 'nth') {
            $forms = $this->landedForms($old, $l, false);
        } elseif ($lm === 2 && $ld === 29 && count($s->months) === 1) {
            $forms = [['lastFeb', ['d', -1]], ['leap', ['d', 29]]];
        } elseif ($old[0] === 'd' && $old[1] < 0 && $llen - $ld + 1 <= 7) {
            $forms = [['fromEnd', ['d', -($llen - $ld + 1)]], ['day', ['d', $ld]]];
        } else {
            $forms = [['day', ['d', $ld]]];
        }
        $n = count($s->months);
        $whole = $k !== 0 && $dm === 0 && $forms[0][1] == $old && $n > 1;
        if ($whole) {
            $ks = array_fill(0, $n, 0);
            $ks[$mIdx] = $k;
            return [
                self::reading('member', ['type' => 'yearly', 'forms' => [$old], 'months' => $s->months, 'k' => $ks]),
                self::reading('pattern', ['type' => 'yearly', 'forms' => [$old], 'months' => $s->months, 'k' => array_fill(0, $n, $k)]),
            ];
        }
        $out = [];
        foreach ($forms as [$id, $f]) {
            $out[] = self::reading($id, ['type' => 'yearly', 'forms' => [$f], 'months' => $months, 'k' => array_fill(0, $n, $k)]);
        }
        return $out;
    }

    /**
     * A single monthly day's new forms (Naming the Landed Day), the
     * series' own form first: the nth weekday counted the same way; a day
     * counted from the end, its nth-last first; the 29th to 31st, the
     * month-end readings (clamped first, or skipping first for a rule
     * written for the 29th to 31st). Moved by whole months, a form stays
     * as written.
     *
     * @return list<array{0:string,1:array}>
     */
    private function landedForms(array $old, int $l, bool $bare): array
    {
        [$y, $m, $d] = Days::ymd($l);
        $len = Days::monthLen($y, $m);
        if ($old[0] === 'nth') {
            $n = $old[1] > 0 ? intdiv($d - 1, 7) + 1 : -(intdiv($len - $d, 7) + 1);
            $n = $n === 5 ? -1 : ($n === -5 ? 1 : $n);
            return [['nth', ['nth', $n, Days::wd($l)]]];
        }
        if ($this->s0->evalForm($old, $y, $m) === $l && !($old[0] === 'd' && $old[1] < 0)) {
            // Moved by whole months: it stays as written ("every 31st" stays every 31st).
            return [[$old[0] === 'c' ? 'clamp' : ($old[1] >= 29 ? 'skip' : 'day'), $old]];
        }
        $out = [];
        $fromEnd = $len - $d + 1;
        if ($old[0] === 'd' && $old[1] < 0 && $fromEnd <= 7) {
            $out[] = ['fromEnd', ['d', -$fromEnd]];
        }
        if ($d <= 28) {
            $out[] = ['day', ['d', $d]];
            return $out;
        }
        $clamp = $d === 31 ? ['last', ['d', -1]] : ['clamp', ['c', $d]];
        $skip = ['skip', ['d', $d]];
        $ownSkip = $old[0] === 'd' && $old[1] >= 29;
        $forms = $ownSkip ? [$skip, $clamp] : [$clamp, $skip];
        if ($d === $len && $d !== 31) {
            $forms[] = ['last', ['d', -1]];
        }
        foreach ($forms as $f) {
            $out[] = $f;
        }
        return $out;
    }

    /** Set positions move by the landed day's change of position in the set (BYSETPOS over a set of days). */
    private function setPosition(int $k): array
    {
        $s = $this->s0;
        $pos = static function (int $dn) use ($s): ?int {
            [$y, $m] = Days::ymd($dn);
            $hits = $s->setDays($y, $m);
            $i = array_search($dn, $hits, true);
            if ($i === false) {
                return null;
            }
            return $s->forms[0][1] > 0 ? $i + 1 : $i - count($hits);
        };
        $from = $pos($this->actedDn);
        $to = $pos($this->landedDn);
        if ($from === null || $to === null) {
            return self::disabled(self::reading('position', ['type' => 'same']), 'not_in_set');
        }
        $dp = $to - $from;
        $forms = [];
        $bad = false;
        foreach ($s->forms as $f) {
            $x = $f[1] + $dp;
            $bad = $bad || $x === 0 || ($x > 0) !== ($f[1] > 0) || abs($x) > 4 * count($s->set);
            $forms[] = ['pos', $x];
        }
        $r = self::reading('position', ['type' => 'forms', 'forms' => $forms, 'k' => array_fill(0, count($forms), $k)]);
        return $bad ? self::disabled($r, 'count_range') : $r;
    }

    /** Whether two members of a monthly form list name one day in some month (400 months cover every month shape). */
    private function taken(array $forms, int $j): bool
    {
        foreach ($forms as $i => $f) {
            foreach ($forms as $i2 => $f2) {
                if ($i2 > $i && $f == $f2 && ($j < 0 || $i === $j || $i2 === $j)) {
                    return true;
                }
            }
        }
        $probe = clone $this->s0;
        $probe->forms = $forms;
        $y0 = 2026;
        for ($mi = Days::mi($y0, 1), $end = $mi + 400; $mi < $end; $mi++) {
            [$y, $m] = Days::fromMi($mi);
            $seen = [];
            foreach ($forms as $f) {
                $d = $probe->evalForm($f, $y, $m);
                if ($d !== null) {
                    if (isset($seen[$d])) {
                        return true;
                    }
                    $seen[$d] = true;
                }
            }
        }
        return false;
    }

    /** A weekly rule with an interval holds a one-member move when some week start keeps every period's images in one counted week. */
    private function weekStartHolds(array $t): bool
    {
        $s = $this->s0;
        $o = $this->o;
        // The images of a long run of the old pattern, compared over a stretch they fully cover.
        $span = 7 * $s->interval;
        $sample = [];
        foreach ($s->days($o['startDn'], $o['startDn'], $o['startDn'] + $span * 14) as $dn) {
            $sample[] = $this->image($t, $dn);
        }
        if ($sample === []) {
            return true;
        }
        $start = min($sample);
        // Past the first periods, where a moved member's images have no gains beside them, up to where the sample covers.
        $lo = $o['startDn'] + abs($t['delta']) + $span * 2;
        $cut = $o['startDn'] + $span * 10;
        if ($lo >= $cut) {
            $cut = $lo + $span * 4;
            $sample = [];
            foreach ($s->days($o['startDn'], $o['startDn'], $cut + $span * 4) as $dn) {
                $sample[] = $this->image($t, $dn);
            }
        }
        $want = array_values(array_unique(array_filter($sample, static fn(int $d): bool => $d >= $lo && $d <= $cut)));
        sort($want);
        $wds = array_values(array_unique(array_map(static fn(int $d): int => Days::wd($d), $sample)));
        sort($wds);
        for ($w = 0; $w < 7; $w++) {
            $try = clone $s;
            $try->wkst = $w;
            $try->wds = $wds;
            if ($try->days($start, $lo, $cut) === $want) {
                return true;
            }
        }
        return false;
    }

    /** A new pattern: keys on its days stay (keep); the old days shifted offers the drag's mapping (map). */
    private function newRuleReadings(): array
    {
        $out = [self::reading('keep', ['type' => 'keep'])];
        $s1 = $this->newShape();
        $s = $this->s0;
        if ($s1 === null || !$s1->supported() || !$s->supported()) {
            return $out;
        }
        // The old days shifted: the new pattern is what a drag of the old rule
        // makes, a member's first occurrence dragged to another day of its
        // period (the nearest such drag, earlier days of the period first),
        // and that drag's correspondence by member is the reading.
        $weekish = static fn(Shape $x): bool => $x->freq === 'WEEKLY' || $x->dailyList;
        if (($weekish($s1) !== $weekish($s) || (!$weekish($s) && $s1->freq !== $s->freq)) || $s1->interval !== $s->interval || ($s->freq === 'DAILY' && !$s->dailyList)) {
            return $out;
        }
        $first = [];
        foreach (array_slice($this->o['raw'], 0, 4 * max(1, $s->memberCount())) as $occ) {
            $m = $s->memberOf($occ['dn']);
            if ($m !== null && !isset($first[$m])) {
                $first[$m] = $occ['dn'];
            }
        }
        ksort($first);
        $o = $this->o;
        $lo = $o['startDn'];
        $hi = $o['startDn'] + 40 * $s->periodSpan() * $s->interval;
        foreach ($first as $dn) {
            $p = $s->periodOf($dn);
            [$pLo, $pHi] = match ($s->freq) {
                'WEEKLY' => [$p * 7 + ($s->wkst - 3), $p * 7 + ($s->wkst - 3) + 6],
                'MONTHLY' => [Days::dn(...[...Days::fromMi($p), 1]), Days::dn(...[...Days::fromMi($p), Days::monthLen(...Days::fromMi($p))])],
                'DAILY' => [$dn - Days::wd($dn), $dn - Days::wd($dn) + 6],
                default => [Days::dn($p, 1, 1), Days::dn($p, 12, 31)],
            };
            for ($to = $pLo; $to <= $pHi; $to++) {
                if ($to === $dn) {
                    continue;
                }
                $x = clone $this;
                $x->actedDn = $dn;
                $x->landedDn = $to;
                $x->delta = $to - $dn;
                $x->kind = 'drag';
                foreach ($x->readings() as $r) {
                    if (!$r['enabled'] || $r['T']['type'] === 'same') {
                        continue;
                    }
                    $days = array_keys($x->walk($r['T'], $lo, $hi));
                    if ($days !== [] && $days === $s1->days($days[0], $lo, $hi)) {
                        $out[] = self::reading('map', $r['T']);
                        $this->mapDrag = $x;
                        return $out;
                    }
                }
            }
        }
        return $out;
    }

    /** The first day the plan's next dates may start on: where the occurrence lands, or where the day acted on was put when earlier. */
    public function fromDn(): int
    {
        if ($this->e['sent'] === null) {
            return $this->landedDn;
        }
        $put = Clock::dayOf($this->e['sent']['start'], $this->allDay1 ? 'UTC' : $this->tz1);
        return min($this->landedDn, $put);
    }

    /** The drag whose correspondence the map reading uses. */
    public ?self $mapDrag = null;

    private static function reading(string $id, array $t): array
    {
        return ['id' => $id, 'enabled' => true, 'why' => null, 'T' => $t];
    }

    private static function disabled(array $r, string $why): array
    {
        $r['enabled'] = false;
        $r['why'] = $why;
        return $r;
    }

    // ---- images ------------------------------------------------------------

    /** The image of an old occurrence (its local day) under a transformation, or null for a loss. */
    public function image(array $t, int $dn): ?int
    {
        $s = $this->s0;
        switch ($t['type']) {
            case 'same':
                return $dn;
            case 'shift':
                if ($t['moved'] === null) {
                    return $dn + $t['delta'];
                }
                return $s->memberOf($dn) === $t['moved'] ? $dn + $t['delta'] : $dn;
            case 'forms':
                $i = $s->memberOf($dn);
                if ($i === null) {
                    return null;
                }
                [$y, $m] = Days::fromMi(Days::miOf($dn) + $t['k'][$i]);
                return $s->evalForm($t['forms'][$i], $y, $m);
            case 'yearly':
                $i = $s->memberOf($dn);
                if ($i === null) {
                    return null;
                }
                $mIdx = intdiv($i, count($s->forms));
                return $s->evalForm($t['forms'][0], Days::ymd($dn)[0] + $t['k'][$mIdx], $t['months'][$mIdx]);
            case 'keep':
                $s1 = $this->newShape();
                $start = $this->newRuleStart();
                return $s1 !== null && $start !== null && $dn >= $start && $s1->has($dn, $start) ? $dn : null;
        }
        return null;
    }

    /**
     * The new pattern's days from $lo to $hi as images of the old pattern's
     * periods (the walk): each counted old period's members, in their new
     * forms, in the shifted period, the periods before the start and after
     * the end included. Each day with its source (the old pattern's day the
     * member gave in that period, null where it gave none).
     *
     * @return array<int,?int> new day => source day
     */
    public function walk(array $t, int $lo, int $hi): array
    {
        $s = $this->s0;
        $o = $this->o;
        $out = [];
        if ($t['type'] === 'keep') {
            $s1 = $this->newShape();
            $start = $this->newRuleStart();
            if ($s1 === null || $start === null) {
                return [];
            }
            foreach ($s1->days($start, max($lo, $start), $hi) as $d) {
                $out[$d] = $this->s0->supported() && $s->has($d, $o['startDn']) ? $d : null;
            }
            return $out;
        }
        if ($t['type'] === 'same' || $t['type'] === 'shift') {
            $span = abs($t['delta'] ?? 0) + 7 * $s->interval * 2 + 7;
            foreach ($s->days($o['startDn'], $lo - $span, $hi + $span) as $d) {
                $n = $this->image($t, $d);
                if ($n !== null && $n >= $lo && $n <= $hi) {
                    $out[$n] = $d;
                }
            }
            ksort($out);
            return $out;
        }
        $ks = $t['k'];
        $p0 = $s->periodOf($o['startDn']);
        $from = $s->periodOf($lo) - max($ks) - 2;
        $to = $s->periodOf($hi) - min($ks) + 2;
        for ($p = $from; $p <= $to; $p++) {
            if (!$s->counted($p, $p0)) {
                continue;
            }
            for ($i = 0, $n = $s->memberCount(); $i < $n; $i++) {
                if ($t['type'] === 'forms') {
                    [$y, $m] = Days::fromMi($p + $ks[$i]);
                    $new = $s->evalForm($t['forms'][$i], $y, $m);
                } else {
                    $mIdx = intdiv($i, count($s->forms));
                    $new = $s->evalForm($t['forms'][0], $p + $ks[$mIdx], $t['months'][$mIdx]);
                }
                if ($new === null || $new < $lo || $new > $hi) {
                    continue;
                }
                $src = $s->memberDay($i, $p);
                if (!isset($out[$new]) || $out[$new] === null) {
                    $out[$new] = $src;
                }
            }
        }
        ksort($out);
        return $out;
    }

    // ---- the new rule ------------------------------------------------------

    private ?Shape $newShapeCache = null;
    private bool $newShapeDone = false;

    public function newShape(): ?Shape
    {
        if (!$this->newShapeDone) {
            $this->newShapeDone = true;
            $text = $this->e['ruleText'] ?? null;
            if ($text !== null) {
                $this->newShapeCache = Shape::parse($text, $this->o['startDn'] + $this->delta);
            }
        }
        return $this->newShapeCache;
    }

    /** The first day of the new rule on or after the series' start moved by Δ (the sent start). */
    public function newRuleStart(): ?int
    {
        $s1 = $this->newShape();
        if ($s1 === null || !$s1->supported()) {
            return null;
        }
        $from = $this->o['startDn'] + $this->delta;
        $p0 = $s1->periodOf($from);
        for ($p = $p0, $i = 0; $i < 4800; $p += $s1->interval, $i++) {
            foreach ($s1->periodDays($p) as [$d]) {
                if ($d >= $from) {
                    return $d;
                }
            }
        }
        return null;
    }

    // ---- one outcome -------------------------------------------------------

    /**
     * Everything one reading gives under the bound, edge, outside and
     * moved-days choices: the new series' raw days (with the generated
     * skips among them), its start, its skipped days, each edited day's
     * image and outcome, whether the day acted on stays in the series.
     */
    public function outcome(array $reading, array $choices): array
    {
        $o = $this->o;
        $t = $reading['T'];
        $images = [];
        foreach ($o['raw'] as $occ) {
            $images[$occ['key']] = $this->image($t, $occ['dn']);
        }
        $actedImg = $this->e['fromRow'] !== null || isset($images[$this->e['actedKey']]) ? $this->landedDn : null;
        if ($t['type'] !== 'keep' && isset($images[$this->e['actedKey']]) && $images[$this->e['actedKey']] !== $this->landedDn && $this->kind !== 'newrule') {
            $this->unoracled[] = 'acted image ' . Days::date((int) $images[$this->e['actedKey']]) . ' is not the landed day ' . Days::date($this->landedDn);
        }
        $vals = array_values(array_filter($images, static fn($v) => $v !== null));
        $isNewRule = $t['type'] === 'keep' || $this->kind === 'newrule';
        if ($t['type'] === 'keep') {
            $earliest = $this->newRuleStart();
        } else {
            $earliest = $vals === [] ? null : min($vals);
        }
        if ($earliest === null) {
            return ['empty' => true];
        }
        $latest = $o['bounded'] && $vals !== [] ? max($vals) : null;
        $unit = $isNewRule && $this->newShape() !== null ? $this->newShape()->unit() : $this->s0->unit();
        $bound = $this->boundRule($choices);
        $interval = $isNewRule && $this->newShape() !== null ? $this->newShape()->interval : $this->s0->interval;
        $span = ($isNewRule && $this->newShape() !== null ? $this->newShape()->periodSpan() : $this->s0->periodSpan()) * $interval;
        // An unbounded series is walked over its window and on past every
        // exception's image, so a day beyond the window is looked for where
        // it is (README, The Oracle).
        $exImages = [];
        foreach (array_merge($o['exdates'], array_map(static fn(array $r): string => (string) $r['recurrence_instance_utc'], $o['overrides'])) as $k) {
            if (($images[$k] ?? null) !== null) {
                $exImages[] = (int) $images[$k];
            }
        }
        $hi = match ($bound['kind']) {
            'none' => max($earliest + self::WINDOW[$unit], $exImages === [] ? 0 : max($exImages) + $span + 1),
            'end' => (int) $latest,
            'until' => $bound['dn'] + 1,
            default => max((int) $latest, $earliest) + ($bound['n'] + 4) * $span * 8 + 400,
        };
        $walk = $this->walk($t, $earliest, $hi);
        // Edge gains: new days within the images' span that are no image, their source lying outside the old series (a first or last period).
        $edge = [];
        if (!$isNewRule) {
            foreach ($walk as $d => $src) {
                if ($src !== null && ($src < $o['startDn'] || ($o['bounded'] && $src > $o['lastDn'])) && ($latest === null || $d <= $latest)) {
                    $edge[$d] = true;
                }
            }
        }
        $without = ($choices['edge'] ?? 'without') === 'without';
        $days = [];
        $gen = [];
        $counted = 0;
        foreach ($walk as $d => $src) {
            if ($d < $earliest) {
                continue;
            }
            if ($bound['kind'] === 'end' && $d > $latest) {
                break;
            }
            if ($bound['kind'] === 'until' && !$this->beforeUntil($d, $bound)) {
                break;
            }
            if ($bound['kind'] === 'count' && $counted >= $bound['n']) {
                break;
            }
            $days[] = $d;
            if (isset($edge[$d]) && $without) {
                $gen[] = $d;
            } else {
                $counted++;
            }
        }
        $inSeries = array_fill_keys($days, true);
        $genSet = array_fill_keys($gen, true);
        $inside = static fn(?int $d): bool => $d !== null && isset($inSeries[$d]) && !isset($genSet[$d]);
        $skips = [];
        foreach ($o['exdates'] as $k) {
            $img = $images[$k] ?? null;
            if ($inside($img)) {
                $skips[] = $img;
            }
        }
        foreach ($gen as $d) {
            $skips[] = $d;
        }
        sort($skips);
        $rows = [];
        foreach ($o['overrides'] as $id => $row) {
            $key = (string) $row['recurrence_instance_utc'];
            if ($id === $this->e['fromRow'] && $this->kind !== 'newrule') {
                $rows[$id] = ['img' => $this->landedDn, 'inside' => $inside($this->landedDn), 'acted' => true];
                continue;
            }
            if (!array_key_exists($key, $images)) {
                continue; // not a raw occurrence before the edit (invariant h says so)
            }
            $img = $images[$key];
            $rows[$id] = ['img' => $img, 'inside' => $inside($img), 'acted' => false];
        }
        return [
            'empty' => $days === [] || ($gen !== [] && count($gen) === count($days)),
            'start' => $days[0] ?? $earliest,
            'days' => $days,
            'bounded' => $bound['kind'] !== 'none',
            'hi' => $hi,
            'gen' => $gen,
            'edge' => array_keys($edge),
            'skips' => $skips,
            'rows' => $rows,
            // Under a new pattern the day acted on has no special place (Every Kind of Edit): nothing is kept for it.
            'actedInside' => $isNewRule || $actedImg === null || $inside($actedImg),
            'images' => $images,
            'latest' => $latest,
        ];
    }

    /** The bound the new series has: the sent one, or the old one under the bound choice. */
    private function boundRule(array $choices): array
    {
        $o = $this->o;
        $sent = $this->e['boundSent'];
        $oldCount = $this->s0->count;
        if ($this->kind === 'newrule' && $sent === null) {
            $p = Shape::parts((string) ($this->e['ruleText'] ?? ''));
            $sent = isset($p['COUNT']) ? ['count' => (int) $p['COUNT']] : (isset($p['UNTIL']) ? ['until' => $p['UNTIL']] : ['none' => true]); // the bound the new rule was sent with
        }
        if ($sent !== null) {
            if (isset($sent['count'])) {
                return ['kind' => 'count', 'n' => (int) $sent['count']];
            }
            if (isset($sent['until'])) {
                return $this->untilBound((string) $sent['until']);
            }
            return ['kind' => 'none'];
        }
        if (!$o['bounded']) {
            return ['kind' => 'none'];
        }
        $choice = $choices['bound'] ?? null;
        if ($oldCount !== null) {
            return $choice === 'end' ? ['kind' => 'end'] : ['kind' => 'count', 'n' => $oldCount];
        }
        return $choice === 'count' ? ['kind' => 'count', 'n' => count($o['raw'])] : ['kind' => 'end'];
    }

    private function untilBound(string $u): array
    {
        if (strlen($u) === 8) {
            return ['kind' => 'until', 'date' => true, 'dn' => Days::ofDate(substr($u, 0, 4) . '-' . substr($u, 4, 2) . '-' . substr($u, 6, 2))];
        }
        $t = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $u, Clock::utc());
        return ['kind' => 'until', 'date' => false, 'at' => $t, 'dn' => Clock::dnOf($t->setTimezone(Clock::zone($this->tz1)))];
    }

    private function beforeUntil(int $d, array $bound): bool
    {
        if ($this->allDay1 || $bound['date']) {
            return $d <= $bound['dn'];
        }
        return Clock::at($d, $this->clock1, $this->tz1) <= $bound['at'];
    }

    /** The bound group's options give different plans (so the group is offered). */
    public function boundDiffers(array $reading, array $choices): bool
    {
        if ($this->e['boundSent'] !== null || !$this->o['bounded'] || $this->kind === 'newrule') {
            return false;
        }
        $a = $this->outcome($reading, ['bound' => 'count'] + $choices);
        $b = $this->outcome($reading, ['bound' => 'end'] + $choices);
        return self::planKey($a) !== self::planKey($b);
    }

    /** What makes two outcomes the same plan (Finding the Readings): days, start, skips and every exception's image. */
    public static function planKey(array $out): string
    {
        if (!empty($out['empty'])) {
            return 'empty';
        }
        $rows = [];
        foreach ($out['rows'] as $id => $r) {
            // An edited day outside the series has the same outcome wherever its image would have been.
            $rows[$id] = [$r['inside'] ? $r['img'] : null, $r['inside']];
        }
        return json_encode([$out['days'], $out['skips'], $rows, $out['actedInside']]);
    }

    /**
     * Whether two readings' plans are the same for as long as the rule
     * could run (Finding the Readings): their series as bounded, and their
     * patterns walked on past the bound, up to 1,200 periods.
     */
    public function samePlans(array $a, array $b, array $choices): bool
    {
        $oa = $this->outcome($a, $choices);
        $ob = $this->outcome($b, $choices);
        if (self::planKey($oa) !== self::planKey($ob)) {
            return false;
        }
        // The same occurrences for as long as the rule could run: each walk
        // from its own start on, past the bound (Finding the Readings). A day
        // before a reading's start is no occurrence of it.
        $lo = $this->o['startDn'] - 400;
        $hi = $this->o['startDn'] + min(1200 * $this->s0->periodSpan() * $this->s0->interval, 146097);
        $from = static fn(array $walk, array $out): array => array_values(array_filter(array_keys($walk), static fn(int $d): bool => empty($out['empty']) && $d >= $out['start']));
        return $from($this->walk($a['T'], $lo, $hi), $oa) === $from($this->walk($b['T'], $lo, $hi), $ob);
    }

    /** The readings' preselection (open decision 1): the design's order, the first enabled one. */
    public static function preselected(array $readings): ?string
    {
        foreach ($readings as $r) {
            if ($r['enabled']) {
                return $r['id'];
            }
        }
        return null;
    }
}
