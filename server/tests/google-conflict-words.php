<?php

declare(strict_types=1);

// The words of the conflict choice (docs/design/google-conflict-choice.md): the server's 409 says what the app's
// dialog says for the same fields, from one table both suites read (fixtures/google-changed-words.json, also read by
// web/tests/google-changed.mjs), so the two cannot drift. Included by run.php. Invented events only.

use BetterCal\Domain\GoogleConflict;

if (!function_exists('check')) {
    fwrite(STDERR, "Run through server/tests/run.php\n");
    exit(1);
}

// In a function of its own: run.php is one global scope, and a name set here ($now) would change the tests after it.
(static function (): void {
    $table = json_decode((string) file_get_contents(__DIR__ . '/fixtures/google-changed-words.json'), true);
    $now = new DateTimeImmutable($table['now'] . 'T12:00:00+00:00');
    $fields = [];
    foreach ($table['rows'] as $row) {
        checkEq('Conflict words, the app\'s table: ' . $row['case'], ['body' => $row['body'], 'lines' => $row['lines']],
            GoogleConflict::question($row['name'], $row['fields'], $row['remove'], $now));
        foreach ($row['fields'] as $f) {
            $fields[$f['field'] . ($row['remove'] ? ', a delete' : ', a change')] = true;
        }
    }
    // Every field, for a change and for a delete, is in the table.
    $want = [];
    foreach (GoogleConflict::FIELDS as $f) {
        $want[] = "$f, a change";
        $want[] = "$f, a delete";
    }
    checkEq('Conflict words: the table has every field for a change and a delete', [], array_values(array_diff($want, array_keys($fields))));

    // The 409's message: the question's words, then what was not done.
    $row = $table['rows'][0];
    checkEq('Conflict words: the message of a change is the question, then what was not done',
        $row['body'] . " It wasn't changed; open the event to see it and make the change again.",
        GoogleConflict::message($row['name'], $row['fields'], false, $now));
    $several = array_values(array_filter($table['rows'], static fn(array $r): bool => $r['remove'] && count($r['lines']) > 1))[0];
    checkEq('Conflict words: the message of a delete over several fields has a line each',
        $several['body'] . ' ' . implode(' ', $several['lines']) . " It wasn't deleted; open the event to see it, and delete it again if you still mean to.",
        GoogleConflict::message($several['name'], $several['fields'], true, $now));
    checkEq('Conflict words: an empty title is the untitled event',
        "Since 'Untitled event' was last shown to you, its title was added in Google Calendar; it is now 'Standup'. It wasn't deleted; open the event to see it, and delete it again if you still mean to.",
        GoogleConflict::message('', [['field' => 'title', 'was' => '', 'now' => 'Standup', 'yours' => null]], true, $now));
})();
