<?php

declare(strict_types=1);

// From the outside read of 5f60dd8b..14a17871 (its probes, made regular tests), sentence 3 and AGENTS.md on stored forms: an install upgrades with a
// half-done change waiting. The pending row is written by 1.5.5's own code (5f60dd8b's server/src, in a process of
// its own, google-upgrade-from-155-old.php) against this tree's stand-in, which answers as the probe of 2026-11-13 found
// Google; then this tree's code runs Finish, and apart Put back, on that row. google_pending has no format number,
// so nothing tells this code the row is older. The rule the expectations come from: Finish leaves the calendar as
// the change would have left it; Put back leaves it as it was before, or is refused and leaves the row for Finish.
// By date, here and at the stand-in. Invented data.

use BetterCal\EditHarness\GoogleStand;
use BetterCal\Infra\Db;

require_once __DIR__ . '/google-stops-lib.php';

return static function (Db $db): void {
    $la = 'America/Los_Angeles';
    try {
        $source = oldSource('5f60dd8b');
    } catch (\Throwable $e) {
        check('upgrade probe: 1.5.5 source from the history', false, $e->getMessage());
        return;
    }
    /** Run the older version to its stop; attach this tree's code and a stand-in holding the same state. */
    $old = static function (string $scenario, string $also = '') use ($db, $source): array {
        $outFile = $source . '/out-' . bin2hex(random_bytes(4));
        $p = proc_open([PHP_BINARY, __DIR__ . '/google-upgrade-from-155-old.php', $source . '/server/src', $scenario, $outFile, $also], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $said = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $status = proc_close($p);
        if ($status !== 0 || !is_file($outFile)) {
            throw new \RuntimeException('the older version stopped: ' . trim($said));
        }
        $o = unserialize((string) file_get_contents($outFile));
        unlink($outFile);
        $u = (int) $o['user'];
        // This process's stand for the same account: its writer and Events, its stand-in given the older run's state.
        $db->run('UPDATE google_accounts SET email = ? WHERE user_id = ?', ['owner-before@example.test', $u]);
        $d = domains($db);
        $s = GoogleStand::make($db, $d->undo, $u);
        $db->run('DELETE FROM calendars WHERE id = ?', [$s->calendarId]);
        $db->run('DELETE FROM google_accounts WHERE user_id = ? AND email = ?', [$u, 'owner@example.test']);
        $db->run('UPDATE google_accounts SET email = ? WHERE user_id = ?', ['owner@example.test', $u]);
        foreach ($o['fake'] as $name => $value) {
            $prop = new ReflectionProperty($s->fake, $name);
            if (!$prop->isReadOnly()) {
                $prop->setValue($s->fake, $value);
            }
        }
        $calendarId = (int) $o['calendar'];
        $proxy = new class($s, $calendarId, $db) {
            public $fake;
            public $knobs;
            public $events;
            public $writer;
            public function __construct(private GoogleStand $s, public int $calendarId, private Db $db)
            {
                $this->fake = $s->fake;
                $this->knobs = $s->knobs;
                $this->events = $s->events;
                $this->writer = $s->writer;
            }
            public function poll(): void
            {
                $this->s->sync->poll($this->db->one('SELECT * FROM calendars WHERE id = ?', [$this->calendarId]));
            }
            public function idProblems(array $master, array $rows): array
            {
                return $this->s->idProblems($master, $rows);
            }
            public function held(string $id): ?array
            {
                return $this->s->held($id);
            }
        };
        $t = (object) ['db' => $db, 'u' => $u, 'd' => $d, 's' => $proxy, 'e' => $s->events, 'id' => (int) $o['series'], 'gid' => (string) $o['gid']];
        return [$t, $o];
    };
    /** What the change leaves when this tree's code makes it uninterrupted on the same series: the calendar by date. */
    $wantOf = static function (string $what) use ($db, $la): array {
        $create = ['start' => '2027-01-04T10:00:00-08:00', 'end' => '2027-01-04T11:00:00-08:00', 'tzid' => $la, 'rrule' => 'FREQ=WEEKLY;COUNT=6'];
        $at = '2027-01-04T10:00:00-08:00';
        $changes = [
            'clock' => ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04T11:00:00-08:00', 'end' => '2027-01-04T12:00:00-08:00'],
            'allday' => ['scope' => 'all', 'instanceStart' => $at, 'start' => '2027-01-04', 'end' => '2027-01-05', 'allDay' => true],
            'rename' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
            'rename-exdate' => ['scope' => 'all', 'title' => 'Sample series, renamed'],
        ];
        $t = H::make($db, $what === 'rename-exdate' ? $create + ['exdates' => ['2027-02-01 18:00:00']] : $create);
        try {
            H::this($t, '2027-01-11T10:00:00-08:00', ['title' => 'Sample series (own)']);
            H::skip($t, '2027-01-18T10:00:00-08:00');
            H::change($t, $changes[$what]);
            return H::hereView($t, $la, '2027-01-01', '2027-03-01', true);
        } finally {
            H::done($t);
        }
    };
    $short = static fn(array $view): string => implode(' / ', array_map(static fn(string $l): string => substr($l, 5), $view));

    scenario("upgrade probe, the comparison: 1.5.5 leaves 'rename-exdate:net2' half done and 1.5.5 itself runs Finish", function () use ($old, $short): void {
        [$t, $o] = $old('rename-exdate:net2', 'finish');
        try {
            echo "    [1.5.5 'rename-exdate:net2', then 1.5.5's own Finish] " . ($o['oldFinish']['refused'] ?? 'ran') . ' => here: ' . $short($o['oldFinish']['here']) . "\n      google: " . $short($o['oldFinish']['google']) . "\n";
            // The comparison: 1.5.5's own Finish brings 1 Feb back (issue 252, the fault this release fixes).
            check('1.5.5 own Finish brings 1 Feb (deleted before the change) back: the fault this release fixes', array_filter($o['oldFinish']['here'], static fn(string $l): bool => str_starts_with($l, '2027-02-01')) !== [], $short($o['oldFinish']['here']));
        } finally {
            H::done($t);
        }
    });
    try {
        foreach (['clock:', 'allday:', 'clock:net2', 'clock:net3', 'allday:net2', 'rename:net2', 'rename-exdate:net2', 'rename-exdate:net1'] as $scenario) {
            [$what] = explode(':', $scenario);
            foreach (['finish', 'putback'] as $how) {
                scenario("upgrade probe: 1.5.5 leaves '$scenario' half done; this code runs " . ($how === 'finish' ? 'Finish' : 'Put back'), function () use ($old, $wantOf, $short, $scenario, $what, $how, $la, $source): void {
                    [$t, $o] = $old($scenario);
                    $name = "1.5.5 '$scenario', then " . ($how === 'finish' ? 'Finish' : 'Put back');
                    try {
                        check("$name: the older run was 1.5.5's code and stopped half done", str_starts_with((string) $o['from'], $source) && $o['stopped'] !== null && count($o['half']) === 1,
                            json_encode([$o['from'], $o['stopped'], count($o['half'])]));
                        if ($how === 'finish') {
                            echo "  [1.5.5 '$scenario'] stopped: " . mb_substr((string) $o['stopped'], 0, 200) . "\n    writes: " . implode(' | ', array_map(static fn(string $w): string => mb_substr($w, 0, 60), $o['writes']))
                                . "\n    steps: " . implode(', ', array_map(static fn(array $s): string => $s['kind'] . ':' . ($s['state'] ?? 'done') . (isset($s['expect']) ? '' : ':no-expect'), (array) $o['steps'])) . "\n";
                        }
                        checkEq("$name: this code's Review lists the change", 1, count(H::halfDone($t)));
                        [$out, $refused] = H::recover($t, $how);
                        $did = $how;
                        if ($how === 'putback' && $refused !== null) {
                            echo "    note: $name: Put back refused with nothing changed at Google ($refused)\n";
                            check("$name: a refused Put back leaves the change in Review", count(H::halfDone($t)) === 1, json_encode(H::halfDone($t)));
                            [$out, $refused] = H::recover($t, 'finish');
                            $did = 'finish';
                        }
                        check("$name: it completes", $refused === null, (string) $refused);
                        if ($refused !== null) {
                            return;
                        }
                        checkEq("$name: nothing left half done", [], H::halfDone($t));
                        $now = H::settled($t, $name, $la, '2027-01-01', '2027-03-01');
                        $target = $did === 'finish' ? $wantOf($what) : $o['before'];
                        // What the upgrade loses (CHANGELOG, 1.5.6): a change 1.5.5 left half done after its master write had
                        // already brought a deleted day back keeps it back after Finish; Put back restores it.
                        $lost = $did === 'finish' && $scenario === 'rename-exdate:net2';
                        if ($lost) {
                            $target = array_values(array_merge($target, array_filter($now, static fn(string $l): bool => str_starts_with($l, '2027-02-01'))));
                            sort($target);
                            check("$name: 1 Feb, brought back by 1.5.5 before the stop, stays back (what the upgrade loses)", array_filter($now, static fn(string $l): bool => str_starts_with($l, '2027-02-01')) !== [], $short($now));
                        }
                        check("$name: the calendar is " . ($did === 'finish' ? 'as the change leaves it' : 'as it was before the change') . ', by date', $now === $target,
                            "\n   want: " . $short($target) . "\n   got:  " . $short($now) . "\n   differences named: " . json_encode($out['differences'] ?? null) . "\n   google:\n     " . implode("\n     ", H::stored($t)));
                        if ($did === 'putback') {
                            checkEq("$name: Google is as it was before the change", $o['beforeGoogle'], H::googleView($t, $la));
                        }
                        echo "    [$name] " . ($did === $how ? 'ran' : 'refused, then Finish ran') . ' => ' . $short($now) . "\n";
                    } finally {
                        H::done($t);
                    }
                });
            }
        }
    } finally {
        removeTree($source);
    }
};
