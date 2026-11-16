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
            // Free or busy (1.5.8, #303): a field the rows here hold and Google holds, compared, owned and asked like status.
            ['check' => 'x-agree', 'what' => "the series here is free where Google holds it busy, after a poll", 'case' => 'a-tag-on-a-day-undone-sends-google-nothing', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $master): array {
                    $s1['rows'][$row($s1, $master)]['show_as'] = 'free';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-own', 'what' => "a day that doesn't own its marking is busy under a free series", 'case' => 'the-series-made-free-here-then-a-day-titled-follows-its-marking', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $s1['rows'][$row($s1, $owns('title'))]['show_as'] = 'busy';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            // #318's exemption is as narrow as stated: a marking a change here left wrong, and any other field, still fail.
            ['check' => 'x-own', 'what' => "#318's case: a day given its own title here holds another marking than its series' before anything changed in Google Calendar", 'case' => 'a-titled-day-then-the-series-marked-free-at-google-and-polled-is-not-checked-318', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $id = $row($s1, $owns('title'));
                    $s1['rows'][$id]['show_as'] = (string) $s1['rows'][$id]['show_as'] === 'free' ? 'busy' : 'free';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-own', 'what' => "#318's case: the day Google keeps busy under a series marked free there shows other notes than its series'", 'case' => 'a-titled-day-then-the-series-marked-free-at-google-and-polled-is-not-checked-318', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    $s1['rows'][$row($s1, $owns('title'))]['description'] = 'Sample probe notes';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-conflict', 'what' => 'a rename over a free or busy change at Google not yet read goes through', 'case' => 'free-or-busy-changed-at-google-then-a-rename-here-is-refused', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null] + $out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => 'the marking Google changed is not kept here after a change of the title', 'case' => 'an-event-in-no-series-made-free-at-google-then-renamed-here-keeps-its-marking', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ($this->singles as $id) {
                        if (isset($s1['rows'][$id])) {
                            $s1['rows'][$id]['show_as'] = 'busy';
                        }
                    }
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-write-asked', 'what' => 'a new timed event made here is stored free', 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    if ($this->made !== null && isset($s1['rows'][$this->made[0]])) {
                        $s1['rows'][$this->made[0]]['show_as'] = 'free';
                    }
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-write-asked', 'what' => 'the create of a new timed event sends transparency', 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $this->stand->fake->requests[] = ['method' => 'POST', 'path' => '/calendars/x/events', 'query' => [], 'headers' => [], 'body' => ['summary' => 'Sample probe', 'transparency' => 'transparent']];
                    $s1['req'] = count($this->stand->fake->requests);
                    return [$out, $s0, $s1];
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
            ['check' => 'x-undo-fields', 'what' => 'an undo refused as one Google would hide days with, where no day would be hidden', 'case' => 'two-days-titled-then-one-undone', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'google_hidden_days', 'message' => 'Sample refusal'] + $out, $s0, $s0];
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
            ['check' => 'x-readback', 'what' => 'a day renamed at Google during the write keeps that title with nothing said', 'case' => 'a-day-renamed-at-google-during-a-series-write-is-the-changes-again', 'step' => 1,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    if ($this->raced !== null) {
                        [$g, $k, $title] = $this->raced;
                        $rec = $s1['g']['digest'][$g]['records'][$k] ?? [null];
                        $rec[0] = $title;
                        $s1['g']['digest'][$g]['records'][$k] = $rec;
                        $s1['differs'] = $s0['differs'];
                    }
                    return [$out, $s0, $s1];
                }],
            // x-day: the day a redo acted on moved to its neighbour (the undo fault's own symptom), and the change made at Google
            // while a flip was stopped carried by Finish onto a neighbouring day.
            // The gate removed: a day with no row and no skip when its series was made all-day, its redo answered as done.
            ['check' => 'x-day', 'what' => 'the redo of a change to a day with no recorded place goes through', 'case' => 'a-day-notes-redone-after-a-daily-20-new-york-series-made-all-day', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null] + $out, $s0, $s1];
                }],
            ['check' => 'x-day', 'what' => "a redone day's notes are on the next day instead of its own", 'case' => 'a-tagged-day-notes-redone-after-a-daily-20-new-york-series-made-all-day', 'step' => 5,
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
            // The hidden-days rule (decisions.md, "A switch between timed and all-day that Google would hide days with is refused";
            // interleaving-invariants.md, x-day, x-finish, x-undo-fields). A switch refused for hidden days re-places nothing, so a
            // redo after it is judged as a placed day's: refused, it fails. (A switch that goes through is still judged by the
            // gate: the probe above, at 20:00 New York.)
            ['check' => 'x-day', 'what' => 'the redo after a switch refused for hidden days is refused though nothing was re-placed', 'case' => 'a-day-notes-redone-after-a-switch-refused-for-hidden-days-at-19-new-york', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'not_undoable', 'message' => 'Sample probe refusal'] + $out, $s0, $s1];
                }],
            // Finish refused for hidden days is accepted only where the harness's own reading says the switch would hide a day.
            ['check' => 'x-finish', 'what' => 'Finish refused for hidden days where the switch hides none', 'case' => 'a-daily-10-los-angeles-series-made-all-day-its-master-write-not-taken-the-edited-day-notes-changed-at-google-then-finished', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'google_hidden_days', 'message' => 'Sample probe refusal'] + $out, $s0, $s1];
                }],
            // A switch made at Google where no day is hidden (10:00 Los Angeles): the day is judged as before, by x-day and
            // x-undo-fields, so a redo refused as deleted at Google fails both.
            ['check' => 'x-day', 'what' => 'a redo refused as deleted at Google after a switch made there that hid no day', 'case' => 'a-day-notes-redone-after-a-switch-made-at-google-at-10-los-angeles', 'step' => 6,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'google_deleted', 'message' => 'Sample probe refusal'] + $out, $s0, $s1];
                }],
            ['check' => 'x-undo-fields', 'what' => 'a redo refused as deleted at Google after a switch made there that hid no day, nothing changed since', 'case' => 'a-day-notes-redone-after-a-switch-made-at-google-at-10-los-angeles', 'step' => 6,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'google_deleted', 'message' => 'Sample probe refusal'] + $out, $s0, $s1];
                }],
            // The retention rule after an undo: a row its entry only changed goes only when what the undo leaves owns nothing.
            ['check' => 'x-undo-fields', 'what' => 'an undo of a day\'s notes removes the row, which still owns its tag', 'case' => 'a-tagged-day-given-notes-then-the-notes-undone-keeps-the-row-for-its-tag', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1) use ($row, $owns): array {
                    unset($s1['rows'][$row($s1, $owns('tags'))]);
                    return [$out, $s0, $this->reshape($s1)];
                }],
            // A4 by the stand-in's state when Finish runs: a remote title Finish replaces is said, with what Google held.
            ['check' => 'x-finish', 'what' => 'Finish replaces a title given at Google since with nothing said', 'case' => 'a-split-stopped-then-a-day-given-an-exception-at-google-then-finished-is-replaced-and-named', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $out['answer']['differences'] = [];
                    return [$out, $s0, $s1];
                }],
            // Changed since counts from the failure: a change at Google after it still refuses Put back.
            ['check' => 'x-putback', 'what' => 'Put back goes ahead though Google changed after the failure (a guest added before it changes nothing alone)', 'case' => 'a-guest-added-at-google-then-notes-for-all-stopped-then-a-colour-at-google-then-put-back-is-refused', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null, 'answer' => ['left' => []]] + $out, $s0, $s1];
                }],
            // "Writes nothing at Google" is judged by what the stand-in holds and the writes it took, not by requests it
            // refused: a refused undo after which the stand-in holds something else still fails.
            ['check' => 'x-undo-fields', 'what' => 'a refused undo leaves Google holding another series title', 'case' => 'two-days-titled-then-one-undone', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1 = $s0;
                    $gid = array_key_first(array_filter($s1['g']['digest'], static fn($d): bool => $d !== null));
                    $s1['g']['digest'][$gid]['title'] = 'Sample probe title';
                    return [['ok' => false, 'error' => 'google_write_failed', 'message' => 'Sample refusal'] + $out, $s0, $s1];
                }],
            // With google_series_undo off (the product's default in 1.5.5), an undo of a whole-series change on a Google
            // calendar is refused not_undoable: the case runs with it on, so its undo goes through, and the damage turns the
            // account's setting off after the undo, which reads as the release gate removed.
            ['check' => 'x-undo-fields', 'what' => 'a whole-series undo on a Google calendar goes through with google_series_undo off', 'case' => 'a-series-rename-undone-redone-undone-redone', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    \seriesUndo($this->db, $this->userId, false);
                    return [$out, $s0, $s1];
                }],
            // x-choice (the conflict choice on, Product::$conflictChoice): one probe per statement, each on the case that
            // shows it. A field Google changed that the change did not set is kept at Google, here and named in `kept`; a
            // field the change set is sent; one read; a question when both changed one field, the read stored, nothing of
            // the request written, the question naming the field with what Google holds; a delete over a change asks;
            // Overwrite lands the person's value.
            ['check' => 'x-choice', 'what' => 'a title Google changed, which the change did not set, is put back at Google', 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $gid = (string) $this->single;
                    $s1['g']['digest'][$gid]['title'] = trim((string) $s0['rows'][$this->singles[$gid]]['title']);
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => "the answer's kept leaves out the title Google changed", 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $out['answer']['kept'] = [];
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => 'the place the change set is not written here', 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $id = $this->singles[(string) $this->single];
                    $s1['rows'][$id]['location'] = $s0['rows'][$id]['location'];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-choice', 'what' => 'the change reads the event from Google twice', 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $this->stand->fake->requests[] = ['method' => 'GET', 'path' => '/calendars/x/events/probe', 'query' => [], 'headers' => [], 'body' => null];
                    $s1['req'] = count($this->stand->fake->requests);
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => 'a title changed at Google and here to different values goes through without a question', 'case' => 'an-event-in-no-series-renamed-at-google-and-here-asks-then-overwrite-lands', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null, 'answer' => ['kept' => []]] + $out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => 'after the question the event here keeps the title it had, not what was read from Google', 'case' => 'an-event-in-no-series-renamed-at-google-and-here-asks-then-overwrite-lands', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $id = $this->singles[(string) $this->single];
                    $s1['rows'][$id]['title'] = $s0['rows'][$id]['title'];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-choice', 'what' => 'the tags in the request that asked are written here', 'case' => 'an-event-in-no-series-renamed-at-google-and-here-asks-then-overwrite-lands', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $id = $this->singles[(string) $this->single];
                    $s1['rows'][$id]['tags'] = ['single', 'single-own-3'];
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-choice', 'what' => "the question gives another title as Google's now", 'case' => 'an-event-in-no-series-renamed-at-google-and-here-asks-then-overwrite-lands', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        $out['extra']['fields'][$i]['now'] = 'Sample probe title';
                    }
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => "Overwrite leaves Google's title here instead of the person's", 'case' => 'an-event-in-no-series-renamed-at-google-and-here-asks-then-overwrite-lands', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $id = $this->singles[(string) $this->single];
                    $s1['rows'][$id]['title'] = 'Sample remote single title 2';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-choice', 'what' => 'Overwrite asks again though Google did not change since the question', 'case' => 'an-event-in-no-series-renamed-at-google-and-here-asks-then-overwrite-lands', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'google_changed', 'message' => 'Sample probe question', 'extra' => []] + $out, $s0, $s0];
                }],
            // x-undo-fields after the conflict choice: the change's own entry starts from the read stored, so its undo leaves a
            // field Google changed (to the value the change also set) as Google has it.
            ['check' => 'x-undo-fields', 'what' => 'the undo of a change made over a stored read puts back the link from before Google changed it', 'case' => 'an-event-in-no-series-given-its-new-link-at-google-and-here-then-undone-keeps-googles-link', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1['rows'][$this->singles[(string) $this->single]]['url'] = 'https://example.test/single-event';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            // x-agree over the case's events in no series: the event here and at Google hold the same six fields.
            ['check' => 'x-agree', 'what' => "an event in no series here holds another title than Google's after a change that went through", 'case' => 'an-event-in-no-series-renamed-at-google-then-its-place-changed-here-keeps-the-title', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1['rows'][$this->singles[(string) $this->single]]['title'] = 'Sample probe title';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-choice', 'what' => 'the question about a delete leaves out the place Google changed', 'case' => 'an-event-in-no-series-whose-place-changed-at-google-deleted-here-asks-then-delete-deletes', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $out['extra']['fields'] = [];
                    return [$out, $s0, $s1];
                }],
            // x-choice, a time set in part (the review of 2026-11-14, finding 3): the request is read against what the person
            // was shown, never against the row a poll refreshed (google-conflict-choice.md, (b) and (c)). Each probe is on a
            // case the product answers right before and after that fix, so it shows the check, not the product.
            ['check' => 'x-choice', 'what' => "a drag of an all-day event shown all-day, made timed at Google and polled, is asked about with its dates read as times against the row (yours midnight to midnight, timed)", 'case' => 'an-all-day-event-in-no-series-made-timed-at-google-polled-then-dragged-as-shown-asks-then-overwrite-lands', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $sent = (array) ($this->lastSingle['body'] ?? []);
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        if (($f['field'] ?? '') === 'time') {
                            $out['extra']['fields'][$i]['yours'] = ['start' => substr((string) $sent['start'], 0, 10) . 'T00:00:00+00:00', 'end' => substr((string) $sent['end'], 0, 10) . 'T00:00:00+00:00', 'allDay' => false];
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => 'Overwrite lands a time other than the yours the question showed (the event left timed here)', 'case' => 'an-all-day-event-in-no-series-made-timed-at-google-polled-then-dragged-as-shown-asks-then-overwrite-lands', 'step' => 5,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1['rows'][$this->singles[(string) $this->single]]['all_day'] = 0;
                    return [$out, $s0, $this->reshape($s1)];
                }],
            // x-choice under the rule of 2026-11-14 (a time is one value, T1 to T5; the second outside read, R1 and R2).
            // R2: what the change sets is the body's whole time, nothing of it taken from what was shown (T4), so a yours
            // whose end keeps the shown length from the new start, instead of the end the request sent, is caught.
            ['check' => 'x-choice', 'what' => 'a start moved here, sent whole with the shown end, is asked about with an end that keeps the shown length instead of the end sent (R2)', 'case' => 'an-event-in-no-series-whose-start-moved-at-google-polled-then-its-start-moved-here-whole-asks', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $shown = (array) ($this->lastSingle['body']['over'] ?? []);
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        if (($f['field'] ?? '') === 'time' && is_array($f['yours'] ?? null)) {
                            $length = (new \DateTimeImmutable((string) $shown['end']))->getTimestamp() - (new \DateTimeImmutable((string) $shown['start']))->getTimestamp();
                            $out['extra']['fields'][$i]['yours']['end'] = (new \DateTimeImmutable((string) $f['yours']['start']))->modify("+$length seconds")->format('Y-m-d\TH:i:sP');
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            // R1: an all-day value is its dates, never read through a zone; a yours whose start moved a day earlier, as an
            // all-day base read through a zone west of UTC moves it, is caught.
            ['check' => 'x-choice', 'what' => 'an all-day event made timed in New York at Google and polled, its end moved here whole: the yours start a day earlier, read through the zone west of UTC (R1)', 'case' => 'an-all-day-event-in-no-series-made-timed-in-new-york-at-google-polled-then-its-end-moved-here-whole-asks-then-overwrite-lands', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        if (($f['field'] ?? '') === 'time' && is_array($f['yours'] ?? null)) {
                            $out['extra']['fields'][$i]['yours']['start'] = (new \DateTimeImmutable(substr((string) $f['yours']['start'], 0, 10)))->modify('-1 day')->format('Y-m-d');
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            // T1: the 409 gives a time whole, and a timed one in its zone.
            ['check' => 'x-choice', 'what' => "the question's yours leaves out the time's zone (tzid)", 'case' => 'an-event-in-no-series-whose-start-moved-at-google-polled-then-its-start-moved-here-whole-asks', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        if (($f['field'] ?? '') === 'time' && is_array($f['yours'] ?? null)) {
                            unset($out['extra']['fields'][$i]['yours']['tzid']);
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => "the question's timed yours names another zone than the one the change sent, at the same instants", 'case' => 'an-event-in-no-series-whose-start-moved-at-google-polled-then-its-start-moved-here-whole-asks', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        if (($f['field'] ?? '') === 'time' && is_array($f['yours'] ?? null)) {
                            $out['extra']['fields'][$i]['yours']['tzid'] = 'Pacific/Honolulu';
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => "the question's all-day yours names a zone other than UTC", 'case' => 'an-all-day-event-in-no-series-made-timed-in-new-york-at-google-polled-then-its-end-moved-here-whole-asks-then-overwrite-lands', 'step' => 4,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    foreach ((array) ($out['extra']['fields'] ?? []) as $i => $f) {
                        if (($f['field'] ?? '') === 'time' && is_array($f['yours'] ?? null)) {
                            $out['extra']['fields'][$i]['yours']['tzid'] = 'America/New_York';
                        }
                    }
                    return [$out, $s0, $s1];
                }],
            // T4: on a request carrying `over`, a timed time whose end is not after its start is no state, so a moment sent
            // whole is refused like any other.
            // T4, narrowed: an all-day boundary is read as its date whatever clock it carries, so such a time is valid.
            ['check' => 'x-choice', 'what' => 'an all-day time sent whole with clocks on its boundaries, in the body and in over, is refused as an invalid time (invalid_request)', 'case' => 'an-all-day-event-in-no-series-dragged-here-with-clocks-on-its-boundaries-goes-through', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'invalid_request', 'status' => 400, 'message' => 'Sample probe refusal', 'extra' => []] + $out, $s0, $s0];
                }],
            // T4, narrowed: the one validity check is on the resolved time, typed clocks included.
            ['check' => 'x-choice', 'what' => 'a whole time whose typed start clock resolves it to a moment is accepted', 'case' => 'an-event-in-no-series-given-a-typed-start-at-its-end-is-refused', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null, 'status' => null, 'answer' => ['kept' => []]] + $out, $s0, $s1];
                }],
            // Issue 302: an all-day end on its start is read as one day long, a valid whole time, never refused.
            ['check' => 'x-choice', 'what' => 'an all-day time sent whole with its end on its start is refused as an invalid time (invalid_request)', 'case' => 'an-all-day-event-in-no-series-moved-here-with-its-end-on-its-start-is-one-day-long', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'invalid_request', 'status' => 400, 'message' => 'Sample probe refusal', 'extra' => []] + $out, $s0, $s0];
                }],
            // T4: a whole time whose end is not after its start is an invalid time, 400 invalid_request, not an out-of-date
            // client.
            ['check' => 'x-choice', 'what' => 'a timed moment sent whole with over is refused as an out-of-date client (client_outdated), not as an invalid time', 'case' => 'an-event-in-no-series-made-a-moment-here-is-refused', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'client_outdated', 'status' => 400, 'message' => 'Sample probe refusal', 'extra' => []] + $out, $s0, $s0];
                }],
            ['check' => 'x-choice', 'what' => 'a timed moment (its end at its start), sent whole with over, is accepted', 'case' => 'an-event-in-no-series-made-a-moment-here-is-refused', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => true, 'error' => null, 'status' => null, 'answer' => ['kept' => []]] + $out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => 'Overwrite lands the yours instants in another zone here', 'case' => 'an-event-in-no-series-whose-start-moved-at-google-polled-then-its-start-moved-here-whole-asks', 'step' => 5,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1['rows'][$this->singles[(string) $this->single]]['tzid'] = 'Pacific/Honolulu';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            ['check' => 'x-choice', 'what' => 'a timed event moved to another zone in the editor goes through with the old zone kept here', 'case' => 'an-event-in-no-series-in-tokyo-moved-to-new-york-clocks-here-lands-its-zone', 'step' => 2,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1['rows'][$this->singles[(string) $this->single]]['tzid'] = 'Asia/Tokyo';
                    return [$out, $s0, $this->reshape($s1)];
                }],
            // T2: a request carrying `over` whose time is in parts is refused, 400 client_outdated, nothing read and nothing
            // written. Each damage starts from the right refusal (the snapshots before the step, nothing taken) and breaks one
            // part of it, so the probe shows the check whatever the product answered.
            ['check' => 'x-choice', 'what' => 'a drag sent by a client from before the rule (start and end, over whole) is refused as an invalid time (invalid_request), not as an out-of-date client', 'case' => 'an-event-in-no-series-dragged-here-by-a-client-from-before-the-rule-is-refused', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    return [['ok' => false, 'error' => 'invalid_request', 'status' => 400, 'message' => 'Sample probe refusal', 'extra' => []] + $out, $s0, $s0];
                }],
            ['check' => 'x-choice', 'what' => 'the refusal of a time not whole reads the event from Google first', 'case' => 'an-event-in-no-series-dragged-here-by-a-client-from-before-the-rule-is-refused', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    array_splice($this->stand->fake->requests, $s0['req'], 0, [['method' => 'GET', 'path' => '/calendars/x/events/probe', 'query' => [], 'headers' => [], 'body' => null]]);
                    $s1 = $s0;
                    $s1['req'] = $s0['req'] + 1;
                    return [['ok' => false, 'error' => 'client_outdated', 'status' => 400, 'message' => 'Sample probe refusal', 'extra' => []] + $out, $s0, $s1];
                }],
            ['check' => 'x-choice', 'what' => "the refusal of a time not whole writes the request's time here", 'case' => 'an-event-in-no-series-dragged-here-by-a-client-from-before-the-rule-is-refused', 'step' => 3,
                'fn' => function (string $do, array $out, array $s0, array $s1): array {
                    $s1 = $s0;
                    $s1['rows'][$this->singles[(string) $this->single]]['start_utc'] = '2027-01-01 00:00:00';
                    return [['ok' => false, 'error' => 'client_outdated', 'status' => 400, 'message' => 'Sample probe refusal', 'extra' => []] + $out, $s0, $this->reshape($s1)];
                }],
        ];
    }

    /** The checks some probe validates. @return list<string> */
    public static function checks(): array
    {
        return array_values(array_unique(array_column(self::all(), 'check')));
    }
}
