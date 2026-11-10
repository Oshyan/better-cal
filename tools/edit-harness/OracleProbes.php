<?php
// The interleaving oracle's own validation (README, Interleaving): for each x-check, a fixed case that passes, and damage
// injected after one of its steps into what that step's checks read (the rows and states, the requests the stand-in took,
// the answer, the Activity entries, the Review queue), damage the check's contract says it catches. A probe passes when
// the check fails on the damaged run more often than on the clean one. A check with no probe that passes is unvalidated:
// its clean runs say nothing. Run with run.sh --interleave --oracle-probes. Invented data only.
declare(strict_types=1);

namespace BetterCal\EditHarness;

final class OracleProbes
{
    /**
     * Each probe: the check it validates, what damage it injects, the fixed case (cases/interleave/) and the step after
     * which it applies, and the damage as a closure run in the case's own scope (Interleave), given the step's kind,
     * answer and snapshots and returning them.
     *
     * @return list<array{check:string, what:string, case:string, step:int, fn:\Closure}>
     */
    public static function all(): array
    {
        // The first live row a predicate picks, by id; null when none.
        $row = static fn(array $s, callable $pick): ?int => array_key_first(array_filter($s['rows'], $pick));
        $day = static fn(array $r): bool => $r['recurrence_parent_id'] !== null;
        $master = static fn(array $r): bool => $r['recurrence_parent_id'] === null && $r['rrule'] !== null;
        $owns = static fn(string $f): \Closure => static fn(array $r): bool => $r['recurrence_parent_id'] !== null && in_array($f, $r['own'], true);
        // An app write the stand-in took during the step, appended to its request log.
        $appWrite = static function (object $fake, array $s1, array $body, array $headers = ['If-Match: "1"']): array {
            $fake->requests[] = ['method' => 'PATCH', 'path' => '/calendars/x/events/probe', 'query' => [], 'headers' => $headers, 'body' => $body];
            $s1['req'] = count($fake->requests);
            return $s1;
        };
        return [
            ['check' => 'x-alone', 'what' => 'a day loses its own tag in a step that asked nothing of it (a remote change)', 'case' => 'tag-on-an-edited-day-then-the-clock-moved-at-google', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $id = $row($s1, $owns('tags'));
                    $s1['rows'][$id]['tags'] = ['sample'];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-dropped', 'what' => "a poll drops a day's coordinates though the day stays", 'case' => 'coordinates-alone-on-a-day-then-a-whole-read', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row): array {
                    $id = $row($s1, static fn(array $r): bool => $r['location_lat'] !== null);
                    $s1['rows'][$id]['location_lat'] = $s1['rows'][$id]['location_lng'] = null;
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-sync-silent', 'what' => 'a poll sends Google a write', 'case' => 'coordinates-alone-on-a-day-then-a-whole-read', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($appWrite): array {
                    return [$out, $s0, $appWrite($this->stand->fake, $s1, ['summary' => 'Sample probe'])];
                }],
            ['check' => 'x-local-silent', 'what' => 'a tag given here alone sends Google a write', 'case' => 'a-tag-on-a-day-undone-sends-google-nothing', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($appWrite): array {
                    return [$out, $s0, $appWrite($this->stand->fake, $s1, ['summary' => 'Sample probe'])];
                }],
            ['check' => 'x-write-asked', 'what' => 'a write carries colorId, a field only Google holds', 'case' => 'series-and-day-writes-send-only-the-writers-keys', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($appWrite): array {
                    return [$out, $s0, $appWrite($this->stand->fake, $s1, ['summary' => 'Sample probe', 'colorId' => '3'])];
                }],
            ['check' => 'x-conditional', 'what' => 'a PATCH goes without If-Match', 'case' => 'series-and-day-writes-send-only-the-writers-keys', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($appWrite): array {
                    return [$out, $s0, $appWrite($this->stand->fake, $s1, ['location' => 'Sample room'], [])];
                }],
            ['check' => 'x-agree', 'what' => "the series' title here differs from Google's after a poll", 'case' => 'a-tag-on-a-day-undone-sends-google-nothing', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $s1['rows'][$row($s1, $master)]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-agree', 'what' => 'a skipped day kept here that Google no longer has', 'case' => 'a-tag-on-a-day-undone-sends-google-nothing', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $id = $row($s1, $master);
                    $s1['rows'][$id]['exdates_json'] = json_encode([(string) $s1['rows'][$id]['start_utc']]);
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-follow', 'what' => "a day holding only a tag shows another title than its series'", 'case' => 'an-empty-whole-read-deletes-nothing', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $s1['rows'][$row($s1, $owns('tags'))]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-own', 'what' => "an edited day's notes, which it doesn't own, differ from its series'", 'case' => 'tag-on-an-edited-day-then-the-clock-moved-at-google', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $s1['rows'][$row($s1, $owns('title'))]['description'] = 'Sample probe notes';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-own', 'what' => "an edited day that doesn't own its tags holds one its series doesn't (the link rule)", 'case' => 'two-days-titled-then-one-undone', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $id = $row($s1, static fn(array $r): bool => $owns('title')($r) && !in_array('tags', $r['own'], true));
                    $tags = [...$s1['rows'][$id]['tags'], 'sample-probe'];
                    sort($tags);
                    $s1['rows'][$id]['tags'] = $tags;
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-structure', 'what' => "an edited day's key is no occurrence of its series", 'case' => 'tag-on-an-edited-day-then-the-clock-moved-at-google', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $id = $row($s1, $owns('title'));
                    $s1['rows'][$id]['recurrence_instance_utc'] = Clock::db(Clock::parse((string) $s1['rows'][$id]['recurrence_instance_utc'])->modify('+37 minutes'));
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-identity', 'what' => "a day row's stored Google id is the other form's id of its key (all-day for a timed series, timed for an all-day one)", 'case' => 'tag-on-an-edited-day-then-the-clock-moved-at-google', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $day): array {
                    $id = $row($s1, static fn(array $r): bool => $day($r) && !empty($r['google_event_id']));
                    $gid = (string) $s1['rows'][$id]['google_event_id'];
                    $cut = strrpos($gid, '_');
                    $suffix = substr($gid, $cut + 1);
                    $s1['rows'][$id]['google_event_id'] = substr($gid, 0, $cut + 1) . (strlen($suffix) === 8 ? $suffix . 'T000000Z' : substr($suffix, 0, 8));
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-conflict', 'what' => 'a write over a change at Google not yet read goes through', 'case' => 'an-ordinary-day-edited-after-a-rename-at-google-is-conditional', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null] + $out, $s0, $s1];
                }],
            ['check' => 'x-conflict', 'what' => 'a refused write leaves a changed etag here', 'case' => 'an-ordinary-day-edited-after-a-rename-at-google-is-conditional', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $id = array_key_first($s1['here']['etags']);
                    $s1['here']['etags'][$id] = '"probe"';
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-pending', 'what' => 'a write to a series whose change is half done goes through', 'case' => 'rename-answer-lost-then-a-tag-while-pending-then-finish', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null] + $out, $s0, $s1];
                }],
            ['check' => 'x-finish', 'what' => "Finish leaves the series without the change's title", 'case' => 'rename-answer-lost-then-a-tag-while-pending-then-finish', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $s1['rows'][$row($s1, $master)]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-putback', 'what' => 'Put back goes ahead though the series changed at Google since', 'case' => 'rename-answer-lost-then-a-color-at-google-then-put-back', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null, 'answer' => ['left' => []]] + $out, $s0, $s1];
                }],
            ['check' => 'x-undo-fields', 'what' => 'an undo renames a day its entry did not touch', 'case' => 'two-days-titled-then-one-undone', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $s1['rows'][$row($s1, $owns('title'))]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-undo-fields', 'what' => 'an undo deletes a day its entry did not touch', 'case' => 'two-days-titled-then-one-undone', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    unset($s1['rows'][$row($s1, $owns('title'))]);
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-undo-fields', 'what' => 'an undo strips the own list of a day its entry did not touch', 'case' => 'two-days-titled-then-one-undone', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $s1['rows'][$row($s1, $owns('title'))]['own'] = [];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-undo-fields', 'what' => 'an undo leaves the title its entry set', 'case' => 'a-series-rename-undone-redone-undone-redone', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $s1['rows'][$row($s1, $master)]['title'] = $s0['rows'][$row($s0, $master)]['title'];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-undo-fields', 'what' => "an undo gives a day back the series' former title, which it only followed, instead of the series' title now", 'case' => 'an-undo-of-a-title-given-to-a-day-with-its-own-icon-after-a-rename-follows-the-new-title', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $day): array {
                    // The title the day showed before the entry, read from the entry's own snapshot: the series' title then.
                    $id = $row($s1, $day);
                    foreach ($this->entryStates as $st) {
                        foreach ($st['before'] as $ident => $was) {
                            if (str_starts_with($ident, 'day:') && $was !== null && !in_array('title', (array) ($was['own'] ?? []), true) && isset($was['title'])) {
                                $s1['rows'][$id]['title'] = $was['title'];
                                return [$out, $s0, $this->reshape($s1)];
                            }
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-undo-fields', 'what' => "a redo leaves a day following the series' title instead of giving it back the edit's title as its own", 'case' => 'an-undo-of-a-title-given-to-a-day-with-its-own-icon-after-a-rename-follows-the-new-title', 'step' => 5,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $day, $master): array {
                    $id = $row($s1, $day);
                    $s1['rows'][$id]['title'] = $s1['rows'][$row($s1, $master)]['title'];
                    $s1['rows'][$id]['own'] = array_values(array_diff($s1['rows'][$id]['own'], ['title']));
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-undo-fields', 'what' => "an undo of the notes that made a day's row, kept for its place, takes away the series' tags it shows without owning them", 'case' => 'a-day-made-by-notes-kept-by-a-place-keeps-the-series-tags-on-undo-and-redo', 'step' => 6,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $id = $row($s1, $owns('location'));
                    $s1['rows'][$id]['tags'] = [];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-undo-fields', 'what' => 'an undo goes through though a poll brought a newer change of its field', 'case' => 'rename-then-a-rename-at-google-polled-then-undo', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null] + $out, $s0, $s1];
                }],
            ['check' => 'x-undo-fields', 'what' => 'an undo of an entry nothing has changed since is refused with another reason', 'case' => 'two-days-titled-then-one-undone', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'google_write_failed', 'message' => 'Sample refusal'] + $out, $s0, $s0];
                }],
            ['check' => 'x-undo-bare', 'what' => 'the bare Undo leaves the entry it should take not undone', 'case' => 'two-changes-then-the-bare-undo-twice', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $this->db->run('UPDATE mutations SET undone = 0 WHERE id = ?', [(int) $out['entry']]);
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-honest', 'what' => 'a refusal changed a row here', 'case' => 'an-ordinary-day-edited-after-a-rename-at-google-is-conditional', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $s1['rows'][$row($s1, $master)]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-empty', 'what' => 'an empty whole read removed a row', 'case' => 'an-empty-whole-read-deletes-nothing', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $day): array {
                    unset($s1['rows'][$row($s1, $day)]);
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-empty', 'what' => 'an empty whole read advanced the sync token', 'case' => 'an-empty-whole-read-deletes-nothing', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1['here']['token'] = 'fake-sync-99';
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-empty', 'what' => 'an empty whole read left no Review notice', 'case' => 'an-empty-whole-read-deletes-nothing', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $this->db->run("UPDATE review_items SET status = 'dismissed' WHERE user_id = ? AND kind = 'google_empty'", [$this->userId]);
                    return [$out, $s0, $s1];
                }],
            // The same rule with a write the step scheduled during the fetch (p-overlap over an empty listing): that write is
            // the only change allowed, so damage to any other row, or to the written day beyond the write, still fails.
            ['check' => 'x-empty', 'what' => 'an empty whole read with a tag given during its fetch removed another row', 'case' => 'a-tag-given-during-an-empty-whole-read-is-its-only-change', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row): array {
                    $id = $row($s1, static fn(array $r): bool => $r['recurrence_parent_id'] !== null && !array_filter($r['tags'], static fn(string $t): bool => str_starts_with($t, 'overlap-')));
                    unset($s1['rows'][$id]);
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-empty', 'what' => 'an empty whole read with a tag given during its fetch renamed the series', 'case' => 'a-tag-given-during-an-empty-whole-read-is-its-only-change', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $s1['rows'][$row($s1, $master)]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-empty', 'what' => 'an empty whole read with a tag given during its fetch left the tagged day with another title too', 'case' => 'a-tag-given-during-an-empty-whole-read-is-its-only-change', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row): array {
                    $id = $row($s1, static fn(array $r): bool => array_filter($r['tags'], static fn(string $t): bool => str_starts_with($t, 'overlap-')) !== []);
                    $s1['rows'][$id]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-overlap', 'what' => "the tag given during a poll's fetch is gone after it", 'case' => 'a-tag-given-while-a-poll-fetches-survives-it', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ($s1['rows'] as $id => $r) {
                        $s1['rows'][$id]['tags'] = array_values(array_filter($r['tags'], static fn(string $t): bool => !str_starts_with($t, 'overlap-')));
                    }
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-budget', 'what' => 'a whole read stopped by its page budget changed a row', 'case' => 'a-whole-read-stopped-by-its-page-budget-applies-nothing', 'step' => 5,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    if ($this->budgetMid !== null) {
                        $id = array_key_first($this->budgetMid['l']);
                        $this->budgetMid['l'][$id]['title'] = 'Sample probe title';
                    }
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-readback', 'what' => 'a day renamed at Google during the write is put back to the series without a word', 'case' => 'a-day-renamed-at-google-during-a-series-write-is-kept', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    if ($this->raced !== null) {
                        [$g, $k] = $this->raced;
                        unset($s1['g']['digest'][$g]['records'][$k]);
                        $s1['differs'] = $s0['differs'];
                    }
                    return [$out, $s0, $s1];
                }],
            // x-day: the day a redo acted on moved to its neighbour (the undo fault's own symptom), and the change made at Google
            // while a flip was stopped carried by Finish onto a neighbouring day.
            // The gate removed: a day with no row and no skip when its series was made all-day, its redo answered as done.
            ['check' => 'x-day', 'what' => 'the redo of a change to a day with no recorded place goes through', 'case' => 'a-day-notes-redone-after-a-daily-19-new-york-series-made-all-day', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null] + $out, $s0, $s1];
                }],
            ['check' => 'x-day', 'what' => "a redone day's notes are on the next day instead of its own", 'case' => 'a-tagged-day-notes-redone-after-a-daily-19-new-york-series-made-all-day', 'step' => 5,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ($s1['rows'] as $id => $r) {
                        if ($r['recurrence_parent_id'] !== null && str_starts_with((string) $r['description'], 'Sample notes')) {
                            foreach (['start_utc', 'end_utc', 'recurrence_instance_utc'] as $c) {
                                $s1['rows'][$id][$c] = Clock::db(Clock::plus(Clock::parse((string) $r[$c]), 86400));
                            }
                        }
                    }
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-day', 'what' => "Finish carries the day's change made at Google onto the next day", 'case' => 'a-daily-10-los-angeles-series-made-all-day-its-master-write-not-taken-the-edited-day-notes-changed-at-google-then-finished', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ($s1['rows'] as $id => $r) {
                        if ($r['recurrence_parent_id'] !== null) {
                            $s1['rows'][$id]['description'] = 'Sample remote notes probe';
                            foreach (['start_utc', 'end_utc', 'recurrence_instance_utc'] as $c) {
                                $s1['rows'][$id][$c] = Clock::db(Clock::plus(Clock::parse((string) $r[$c]), 86400));
                            }
                            break;
                        }
                    }
                    return [$out, $s0, $this->reshape($s1)];
                }],
        ];
    }

    /** The checks some probe validates. @return list<string> */
    public static function checks(): array
    {
        return array_values(array_unique(array_column(self::all(), 'check')));
    }
}
