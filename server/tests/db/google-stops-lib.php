<?php

declare(strict_types=1);

// Helpers for the tests that began as the outside read's probes of 5f60dd8b..14a17871 (google-series-stops, google-exdate-kept-through-changes, google-day-other-form-paths, google-upgrade-from-155). Nothing here computes an expectation
// from server/src: a stopped change is judged by the calendar by date, here and at the stand-in, against the same
// change run uninterrupted (Finish) or the calendar before it (Put back). Invented data.

require_once __DIR__ . '/google-hunt-lib.php';

final class PF
{
    /** @var list<string> one line per stopped run, for the report */
    public static array $lines = [];

    /**
     * One change, uninterrupted, then stopped at each of its REQUESTS to Google (reads included, which H::matrix does
     * not reach): 'down' has the network down from request k until the change has ended (so the read a stop makes
     * fails too), 'lost' has request k alone taken and its answer lost. Each followed by Finish and, apart, by Put back.
     * Finish must leave what the uninterrupted change leaves; Put back must leave the calendar as it was, or be refused
     * and leave the change in Review with Finish completing it. A refused Put back with nothing changed at Google is
     * counted (it tells the person "changed at Google since" when nothing did).
     */
    public static function stops(string $name, callable $build, callable $act, string $zone, string $from, string $to, array $modes = ['down', 'lost']): void
    {
        $t = $build();
        $before = H::hereView($t, $zone, $from, $to, true);
        $beforeGoogle = H::googleView($t, $zone);
        $base = count($t->s->fake->requests);
        $error = null;
        try {
            $act($t);
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }
        check("$name: the change goes through uninterrupted", $error === null, (string) $error);
        $total = count($t->s->fake->requests) - $base;
        $log = array_map(static fn(array $r): string => $r['method'] . ' ' . rawurldecode(basename($r['path'])), array_slice($t->s->fake->requests, $base));
        $want = H::settled($t, $name, $zone, $from, $to);
        echo "  [$name] requests of the uninterrupted change: $total (" . count(array_filter($log, static fn(string $l): bool => !str_starts_with($l, 'GET'))) . " writes)\n";
        H::done($t);
        if ($error !== null) {
            return;
        }
        foreach ($modes as $mode) {
            for ($k = 1; $k <= $total; $k++) {
                foreach (['finish', 'putback'] as $how) {
                    $label = "$name, request $k of $total ({$log[$k - 1]}) $mode, then " . ($how === 'finish' ? 'Finish' : 'Put back');
                    scenario($label, function () use ($label, $build, $act, $zone, $from, $to, $want, $before, $beforeGoogle, $k, $mode, $how): void {
                        $t = $build();
                        try {
                            $n = count($t->s->fake->requests);
                            if ($mode === 'down') {
                                $t->s->fake->failAfter = $n + $k - 1;
                            } else {
                                $t->s->fake->loseAnswerAt = $n + $k;
                            }
                            $stopped = null;
                            try {
                                $act($t);
                            } catch (\Throwable $e) {
                                $stopped = get_class($e) . ': ' . $e->getMessage();
                            }
                            $t->s->fake->failAfter = null;
                            $t->s->fake->loseAnswerAt = null;
                            $half = H::halfDone($t);
                            if ($stopped === null) {
                                checkEq("$label: the change went through all the same; nothing half done", [], $half);
                                checkEq("$label: and the calendar is as the uninterrupted change leaves it", $want, H::settled($t, $label, $zone, $from, $to));
                                PF::$lines[] = "$label => went through";
                                return;
                            }
                            if ($half === []) {
                                checkEq("$label: nothing recorded, so the calendar is as it was", $before, H::hereView($t, $zone, $from, $to, true));
                                checkEq("$label: nothing recorded, so Google is as it was", $beforeGoogle, H::googleView($t, $zone));
                                PF::$lines[] = "$label => nothing sent";
                                return;
                            }
                            $said = (string) ($half[0]['error'] ?? $half[0]['words'] ?? '');
                            [$out, $refused] = H::recover($t, $how);
                            $did = $how;
                            if ($how === 'putback' && $refused !== null) {
                                PF::$lines[] = "$label => PUT BACK REFUSED with nothing changed at Google: $refused";
                                echo "    note: $label: refused ($refused)\n";
                                check("$label: a refused Put back leaves the change in Review", count(H::halfDone($t)) === 1, json_encode(H::halfDone($t)));
                                [$out, $refused] = H::recover($t, 'finish');
                                $did = 'finish';
                            } else {
                                PF::$lines[] = "$label => stopped (" . mb_substr($stopped, 0, 90) . "); $how " . ($refused === null ? 'ran' : 'FAILED: ' . $refused);
                            }
                            check("$label: it completes", $refused === null, (string) $refused);
                            if ($refused !== null) {
                                return;
                            }
                            checkEq("$label: Review lists nothing half done after it", [], H::halfDone($t));
                            $now = H::settled($t, $label, $zone, $from, $to);
                            $target = $did === 'finish' ? $want : $before;
                            check("$label: the calendar is " . ($did === 'finish' ? 'as the uninterrupted change leaves it' : 'as it was before the change') . ', by date', $now === $target,
                                "\n   missing: " . implode("\n            ", array_diff($target, $now)) . "\n   extra:   " . implode("\n            ", array_diff($now, $target))
                                . "\n   differences named: " . json_encode($out['differences'] ?? null) . "\n   rows:\n     " . implode("\n     ", H::rows($t)) . "\n   google:\n     " . implode("\n     ", H::stored($t)));
                            if ($did === 'putback') {
                                checkEq("$label: Google is as it was before the change", $beforeGoogle, H::googleView($t, $zone));
                            }
                        } finally {
                            $t->s->fake->failAfter = null;
                            $t->s->fake->loseAnswerAt = null;
                            H::done($t);
                        }
                    });
                }
            }
        }
    }
}
