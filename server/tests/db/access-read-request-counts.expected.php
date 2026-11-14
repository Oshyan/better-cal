<?php

declare(strict_types=1);

// What each kind of write sent the stand-in on the fix branch (d32b65b1), measured with access-read-request-counts.test.php
// (PROBE_ACCESS_DUMP), with the two differences the model states (docs/design/google-access-detect-and-inform.md, section 2):
// the first read of an event in no series is "GET list" (by its iCalUID) where the fix branch sent "GET id"; and an undo
// of a day delete reads the series once (the fix branch read the day by its id first: GET id, GET list, PATCH id, GET list).
// Three sequences are the read-back build's, which sends fewer reads than the fix branch did (docs/design/google-series-read-back.md,
// "Request Count": the read back is the last read where no day is sent after the series' write; Put back reuses its read):
// both "following" cases (the fix branch sent 7 and 4 requests) and "half done: putBack" (8). None gains a request, and
// none holds the small list an access refusal asks.

return [
    'single: create' => ['POST list'],
    'single: undo of the create' => ['GET list', 'DELETE id'],
    'single: edit the title' => ['GET list', 'PATCH id'],
    'single: drag to another time' => ['GET list', 'PATCH id'],
    'single: a tag alone (held here)' => [],
    'single: undo of the title edit' => ['GET list', 'PATCH id'],
    'single: delete' => ['GET list', 'DELETE id'],
    'single: undo of the delete' => ['GET list', 'PATCH id'],
    'poll: a delta after changes here' => ['GET list'],
    'poll: a delta with nothing new' => ['GET list'],
    'series: create' => ['POST list'],
    'day: edit a day with no record' => ['GET list', 'GET id', 'PATCH id', 'GET list'],
    'day: edit the same day again' => ['GET list', 'PATCH id', 'GET list'],
    'day: use the series value for its title' => ['GET list', 'PATCH id', 'GET list'],
    'day: delete a day with no record' => ['GET list', 'GET id', 'DELETE id', 'GET list'],
    'day: undo of the day delete' => ['GET list', 'PATCH id', 'GET list'],
    'day: drag a day with no record' => ['GET list', 'GET id', 'PATCH id', 'GET list'],
    'day: undo of the day drag' => ['GET list', 'PATCH id', 'GET list'],
    'all: the title' => ['GET list', 'PATCH id', 'GET list', 'PATCH id', 'GET list'],
    'all: an hour later' => ['GET list', 'PATCH id', 'GET list', 'PATCH id', 'GET list'],
    'following: the title from the fourth day' => ['GET list', 'PATCH id', 'GET list', 'POST list', 'GET list'],
    'following: delete from the third day' => ['GET list', 'PATCH id', 'GET list'],
    'all: delete' => ['GET list', 'DELETE id', 'GET list'],
    'half done: finish' => ['GET list', 'GET list', 'PATCH id', 'GET list'],
    'half done: putBack' => ['GET list', 'GET list', 'GET list', 'PATCH id', 'GET list', 'PATCH id', 'GET list'],
];
