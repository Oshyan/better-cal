<?php
declare(strict_types=1);

use BetterCal\Plugin\PluginHost;
use BetterCal\Plugin\PluginInterface;

/**
 * Trip Planner
 *
 * Finds stretches in the calendar where the user is genuinely free for a longer
 * trip, scores them against real climate normals for the destination on those
 * exact dates, draws the best few as overlay bands, and offers the single best
 * one as a proposal. Nothing is written to the calendar by this plugin.
 *
 * Every number a user sees about the weather comes from Open-Meteo's ERA5
 * archive (free, keyless). The model is only ever asked for prose and for
 * reading the user's sentence; it is never asked for a temperature.
 */
return new class implements PluginInterface {

    private const DEFAULTS = [
        'idea'              => '',
        'destination'       => null,
        'minDays'           => 14,
        'maxDays'           => 21,
        'horizonDays'       => 120,
        'earliestDays'      => 10,
        'bufferDays'        => 1,
        'shortEventMinutes' => 240,
        'recurringBlocks'   => false,
        'units'             => 'F',
        'candidates'        => 3,
        'bandColor'         => '#3f9d6b',
        'makeProposal'      => true,
        'notifyOnNew'       => true,
        'debug'             => false,
    ];

    private const NORMALS_YEARS   = 10;
    private const NORMALS_TTL     = 2592000;   // 30 days
    private const SMOOTH_DAYS     = 5;         // +/- days pooled per calendar day
    private const WET_DAY_MM      = 1.0;
    private const MAX_CANDIDATES  = 6000;

    /** @var string[] diagnostic lines, surfaced as info warnings when debug is on */
    private array $diag = [];

    /** Set when a destination had to be inferred from a region name rather than found outright. */
    private ?string $regionGuess = null;

    /** The current year in the USER's zone, not the server's. */
    private string $thisYear = '1970';

    // ------------------------------------------------------------------ //
    // Settings validation (runs synchronously on save; no host available) //
    // ------------------------------------------------------------------ //

    /**
     * NOTE (discovered by probing the live host, not from the docs): $values
     * contains ONLY the keys being saved, not the plugin's effective settings.
     * So every cross-field rule has to be guarded on both keys being present,
     * and a "this field is required" rule is impossible here - it would fire on
     * every unrelated single-field save and make the plugin unconfigurable.
     * The required-destination check therefore lives in runJob, as a warning.
     *
     * The host already enforces `type`, `min`, `max` and `options` from the
     * manifest before this runs, so only the things the schema cannot say
     * belong here.
     */
    public function validateSettings(array $values): array
    {
        $errors = [];
        $has = static fn(string $k): bool => array_key_exists($k, $values);

        if ($has('minDays') && $has('maxDays') && (int)$values['minDays'] > (int)$values['maxDays']) {
            $errors['minDays'] = 'Shortest trip cannot be longer than the longest trip.';
        }
        if ($has('horizonDays') && $has('earliestDays')
            && (int)$values['earliestDays'] >= (int)$values['horizonDays']) {
            $errors['earliestDays'] = 'Earliest departure must be inside the horizon.';
        }
        if ($has('horizonDays') && $has('minDays')
            && (int)$values['minDays'] > (int)$values['horizonDays']) {
            $errors['minDays'] = 'A trip that long does not fit in the horizon.';
        }
        if ($has('bandColor') && (string)$values['bandColor'] !== ''
            && !preg_match('/^#[0-9a-fA-F]{6}$/', (string)$values['bandColor'])) {
            $errors['bandColor'] = 'Use a #rrggbb hex colour, e.g. #3f9d6b.';
        }
        if ($has('destination') && $values['destination'] !== null
            && !$this->isPlace($values['destination'])) {
            $errors['destination'] = 'That is not a point on Earth - latitude must be -90..90 and longitude -180..180.';
        }
        if ($has('idea')) {
            $idea = trim((string)$values['idea']);
            if ($idea !== '' && strlen($idea) < 8) {
                $errors['idea'] = 'Give me a bit more than that, or leave it blank and set a destination below.';
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------ //
    // The job                                                            //
    // ------------------------------------------------------------------ //

    public function runJob(PluginHost $host, string $jobId): void
    {
        if ($jobId !== 'plan') {
            $host->log("unknown job '{$jobId}', nothing to do");
            return;
        }

        $cfg = $this->cfg($host->settings());
        $tz  = $host->timezone();
        $now = new DateTimeImmutable('now', $tz);
        $this->thisYear = $now->format('Y');

        $warnings    = [];
        $proposeNote = '';
        $modelUsed   = null;   // 'parse' | 'narrative' | null

        // ---- 1. What are we planning? --------------------------------- //
        [$intent, $intentSrc] = $this->resolveIntent($host, $cfg, $modelUsed);
        $place = $this->resolvePlace($host, $cfg, $intent);

        if ($place === null) {
            $host->replaceRanges([]);
            $host->replaceWarnings(array_merge($warnings, [[
                'message'  => 'Trip Planner has no destination. Set one in the plugin settings, or write the trip out in the "Trip idea" box.',
                'severity' => 'warn',
                'fix'      => 'Manage -> Plugins -> Trip Planner -> Destination',
            ]], $this->diagWarnings($cfg)));
            $host->log('no destination configured; nothing to plan');
            return;
        }

        $minDays  = max(1, (int)$intent['minDays']);
        $maxDays  = max($minDays, (int)$intent['maxDays']);
        $horizon  = max($minDays + 1, (int)$intent['horizonDays']);
        $earliest = max(0, min((int)$cfg['earliestDays'], $horizon - $minDays));

        $host->log(sprintf(
            '%s: %d-%d days somewhere in the next %d days (intent from %s)',
            $place['name'], $minDays, $maxDays, $horizon, $intentSrc
        ));

        if ($this->regionGuess !== null) {
            $warnings[] = [
                'message'  => sprintf(
                    'You said "%s", which is a region rather than a place with a weather station, so the climate figures below are for %s - the largest town found inside it. Pick a specific spot in the Destination field if that is not close enough.',
                    $this->regionGuess, $place['name']
                ),
                'severity' => 'info',
                'fix'      => 'Manage -> Plugins -> Trip Planner -> Destination',
            ];
        }

        // ---- 2. What is actually on the calendar? --------------------- //
        $today       = $now->format('Y-m-d');
        $searchStart = $this->addDays($today, $earliest);
        $searchEnd   = $this->addDays($today, $horizon);

        $policies = $this->calendarPolicies($host);
        $occ      = $this->readWindow($host, $tz, $today, $this->addDays($searchEnd, 2));

        $load = $this->classifyDays($occ, $policies, $tz, $cfg, $searchStart, $searchEnd);
        $this->autoQuietNoisyCalendars($load, $searchStart, $searchEnd, $warnings);

        $host->log(sprintf(
            'window %s..%s: %d occurrences, %d hard-blocked days, %d days with movable items',
            $searchStart, $searchEnd, count($occ),
            count($load['hard']), count($load['soft'])
        ));

        // ---- 3. Climate normals for the destination ------------------- //
        $normals = $this->normals($host, $place, $warnings);

        // ---- 4. Candidate windows ------------------------------------- //
        $dates = $this->dateRange($searchStart, $searchEnd);
        $runs  = $this->freeRuns($dates, $load['hard']);

        $buffer    = (int)$cfg['bufferDays'];
        $relaxed   = false;
        $shortfall = 0;
        $cands     = $this->candidates($dates, $runs, $load, $normals, $minDays, $maxDays, $buffer, $cfg);
        if (!$cands && $buffer > 0) {
            $cands   = $this->candidates($dates, $runs, $load, $normals, $minDays, $maxDays, 0, $cfg);
            $relaxed = $cands ? true : false;
            $buffer  = 0;
        }

        // Nothing of the length asked for. Rather than shrug, say what the best
        // you could actually do is - but never quietly pretend it is what was
        // asked for, and only offer it if it is not a token amount of time.
        if (!$cands && $runs) {
            $longest = $runs[0]['len'];
            if ($longest >= 3) {
                $shortfall = $longest;
                $cands = $this->candidates($dates, $runs, $load, $normals, $longest, $longest, 0, $cfg);
                $relaxed = false;
            }
        }

        if (!$cands) {
            $host->replaceRanges([]);
            $warnings[] = [
                'message'  => sprintf(
                    'No stretch of %d+ free days for %s in the next %d days. The longest clear run is %d days (%s).',
                    $minDays, $place['name'], $horizon,
                    $runs ? $runs[0]['len'] : 0,
                    $runs ? ($runs[0]['start'] . ' to ' . $runs[0]['end']) : 'none'
                ),
                'severity' => 'warn',
                'fix'      => 'Widen the horizon, shorten the trip, or mark a calendar as "Ignore" for travel.',
            ];
            $host->replaceWarnings(array_merge($warnings, $this->diagWarnings($cfg)));
            $host->log('no viable window found');
            return;
        }

        usort($cands, static function (array $a, array $b) {
            if ($a['score'] === $b['score']) return strcmp($a['start'], $b['start']);
            return $b['score'] <=> $a['score'];
        });
        $picked = $this->pickDistinct($cands, max(1, (int)$cfg['candidates']));
        $best   = $picked[0];

        // ---- 5. What have I already offered, and what did you decide? -- //
        $decided = $this->decidedKeys($host);
        $slug    = $this->slug($place['short']);

        $state = $this->generation($host, $decided, $slug, $today);

        // If you accepted a trip to this destination and it has not happened
        // yet, the question is answered. Stand down rather than nagging with a
        // second trip to the same place.
        $live = $state['accepted'];
        if ($live !== null) {
            $host->replaceRanges([]);
            $warnings[] = [
                'message'  => sprintf(
                    'You already accepted %s (%s), so Trip Planner is standing down. Change the destination or dates - or undo that trip - and it will look again.',
                    $place['name'], $this->prettySpan($live['start'], $live['end'])
                ),
                'severity' => 'info',
            ];
            $host->replaceWarnings(array_merge($warnings, $this->diagWarnings($cfg)));
            $host->log('standing down: ' . $live['key'] . ' is accepted and still ahead of us');
            return;
        }

        // Skip windows you have already turned down. propose() silently no-ops
        // on a decided sourceKey, so without this a rejected window would
        // quietly stop producing any proposal at all. Matching on the exact key
        // is not enough either: nudge the horizon by a day and "Oct 12-Nov 1"
        // becomes "Oct 15-Nov 4", which is the same trip as far as a person is
        // concerned. So compare the actual spans, which live in kv because
        // myProposals() deliberately returns no content.
        $offerCand = null;
        foreach ($picked as $c) {
            if ($this->overlapsRejected($c, $state['rejected'])) {
                $this->diag[] = 'skipping ' . $c['start'] . '+' . $c['len'] . 'd: substantially a window you already rejected';
                continue;
            }
            $offerCand = $c;
            break;
        }
        if ($offerCand === null) {
            $proposeNote = 'Every window found is one you already turned down.';
            $cfg['makeProposal'] = false;
            $warnings[] = [
                'message'  => 'All ' . count($picked) . ' windows found are ones you have already rejected. Widen the horizon or change the trip length to see something new.',
                'severity' => 'info',
            ];
        } elseif ($offerCand !== $best) {
            $warnings[] = [
                'message'  => sprintf('The best window (%s) is one you rejected, so the proposal is the next one down: %s.',
                    $this->prettySpan($best['start'], $best['end']),
                    $this->prettySpan($offerCand['start'], $offerCand['end'])),
                'severity' => 'info',
            ];
        }

        // ---- 6. Narrative (model optional) ---------------------------- //
        $story = null;
        // "llmJson() allows up to 45 s against the 60 s run budget, so two model
        // calls cannot fit in one job." Gate on the clock rather than on whether
        // the first call succeeded: a parse call that returned null instantly
        // (model unconfigured) has cost nothing and leaves room for this one,
        // but a parse call that burned 40 s has not.
        $room = $host->budgetRemaining();
        if ($room < 47.0) {
            $this->diag[] = sprintf('skipping narrative: only %.1f s of the run budget left, a model call needs 45', $room);
        } else {
            $story = $this->narrate($host, $place, $offerCand ?? $best, $picked, $normals, $cfg, $intent);
            if ($story !== null) {
                $modelUsed = $modelUsed === null ? 'narrative' : $modelUsed . '+narrative';
            }
        }

        // ---- 7. Output ------------------------------------------------ //
        $cfg['_shortfall'] = $shortfall;
        $cfg['_askedMin']  = $minDays;

        $bands = [];
        foreach ($picked as $i => $c) {
            $bands[] = [
                'sourceKey'  => 'win-' . $c['start'] . '-' . $c['len'],
                'start'      => $this->localInstant($c['start'], '00:00:00', $tz),
                'end'        => $this->localInstant($this->addDays($c['end'], 1), '00:00:00', $tz),
                'label'      => sprintf('#%d %s · %d days%s', $i + 1, $place['short'], $c['len'],
                                        $this->overlapsRejected($c, $state['rejected']) ? ' (turned down)' : ''),
                'color'      => $this->shade((string)$cfg['bandColor'], $i),
                'detailHtml' => $this->bandHtml($place, $c, $i + 1, $normals, $cfg, $load, $relaxed)
                                . ($this->overlapsRejected($c, $state['rejected'])
                                   ? '<p><strong>You already turned this window down</strong>, so it is drawn for reference but will not be proposed again.</p>' : ''),
            ];
        }
        $nBands = $host->replaceRanges($bands);

        $offerCand   = $offerCand ?? $best;
        $proposalKey = $this->keyFor($state['gen']);
        $proposed    = false;

        if ($shortfall > 0) {
            $warnings[] = [
                'message'  => sprintf(
                    'Nothing %d days long is free in the next %d days. The best you could do for %s is %d days (%s); shown on the calendar, %s.',
                    $minDays, $horizon, $place['name'], $shortfall,
                    $this->prettySpan($best['start'], $best['end']),
                    $shortfall >= 0.6 * $minDays ? 'and offered as a proposal' : 'but not offered as a proposal - it is well short of what you asked for'
                ),
                'severity' => 'warn',
                'fix'      => 'Widen the horizon, or mark a calendar as "Soft conflict only" if its events would not really stop you travelling.',
            ];
            if ($shortfall < 0.6 * $minDays) {
                $cfg['makeProposal'] = false;
                $proposeNote = 'Not proposed: only ' . $shortfall . ' of the ' . $minDays . ' days you asked for.';
            }
        }

        if ($cfg['makeProposal']) {
            [$proposed, $proposeNote] = $this->offer(
                $host, $proposalKey, $place, $offerCand, $picked, $normals, $cfg, $load, $tz, $story, $relaxed
            );
        }

        $warnings[] = [
            'message'  => sprintf(
                'Best window for %s: %s (%d days). Typical %s. %s',
                $place['name'],
                $this->prettySpan($offerCand['start'], $offerCand['end']),
                $offerCand['len'],
                $this->weatherPhrase($offerCand['weather'], $cfg),
                $proposed ? 'Offered as a proposal - accept it to put it on the calendar.' : $proposeNote
            ),
            'severity' => 'info',
        ];
        if ($relaxed) {
            $warnings[] = [
                'message'  => 'No window had a completely clear day on both sides, so the buffer requirement was dropped for this run.',
                'severity' => 'info',
            ];
        }
        if ($story === null && $modelUsed !== 'parse') {
            $warnings[] = [
                'message'  => 'The language model was unavailable, so the write-up is the plain deterministic one. The dates, conflicts and weather numbers are unaffected - none of them come from the model.',
                'severity' => 'info',
            ];
        }

        // ---- 8. Notify once per changed answer ------------------------ //
        // notify() does not dedupe (docs), so the last key we shouted about
        // lives in kv and we stay quiet until the answer actually changes.
        if ($cfg['notifyOnNew']) {
            $stamp = $proposalKey . '@' . $offerCand['start'] . '+' . $offerCand['len'];
            $seen  = $this->kv($host, 'notified');
            if ($seen !== $stamp) {
                $host->notify(
                    'Trip window: ' . $place['short'],
                    sprintf('%s — %d days. Typical %s.',
                        $this->prettySpan($offerCand['start'], $offerCand['end']),
                        $offerCand['len'],
                        $this->weatherPhrase($offerCand['weather'], $cfg)
                    ),
                    '/'
                );
                $this->kvPut($host, 'notified', $stamp);
                $this->diag[] = 'notified (answer changed to ' . $stamp . ')';
            } else {
                $this->diag[] = 'notify suppressed: same best window as last run';
            }
        }

        $nWarn = $host->replaceWarnings(array_merge($warnings, $this->diagWarnings($cfg)));

        $host->log(sprintf(
            'best %s (%d days, score %.3f) · %d bands, %d warnings, proposal=%s, model=%s, budget left %s',
            $best['start'], $best['len'], $best['score'], $nBands, $nWarn,
            $proposed ? 'yes' : 'no',
            $modelUsed ?? 'unused',
            var_export($host->budgetRemaining(), true)
        ));
    }

    // ================================================================== //
    // Intent                                                             //
    // ================================================================== //

    /** @return array{0:array,1:string} */
    private function resolveIntent(PluginHost $host, array $cfg, ?string &$modelUsed): array
    {
        $fallback = [
            'destName'    => null,
            'minDays'     => (int)$cfg['minDays'],
            'maxDays'     => (int)$cfg['maxDays'],
            'horizonDays' => (int)$cfg['horizonDays'],
        ];

        $idea = trim((string)$cfg['idea']);
        if ($idea === '') {
            return [$fallback, 'settings'];
        }

        $heur = $this->parseIdea($idea, $fallback);
        $key  = 'intent:' . substr(sha1($idea), 0, 16);
        $hit  = $this->kv($host, $key);
        if (is_string($hit) && $hit !== '') {
            $decoded = json_decode($hit, true);
            if (is_array($decoded)) {
                return [$this->mergeIntent($fallback, $heur, $decoded), 'cached model read of your sentence'];
            }
        }

        $parsed = null;
        try {
            $parsed = $host->llmJson(
                "Read this sentence about a trip someone wants to take and turn it into structured fields.\n"
                . "Sentence: \"" . $idea . "\"\n\n"
                . "Return ONLY JSON with these keys:\n"
                . '{"destName": string|null, "minDays": integer|null, "maxDays": integer|null, "horizonDays": integer|null}' . "\n\n"
                . "destName is the place to travel to, as a geocodable place name (\"Hawaii\" -> \"Kailua-Kona, Hawaii\" is fine, "
                . "but do not invent a place that is not implied).\n"
                . "minDays/maxDays are the trip length in DAYS (a week is 7). \"2-3 weeks\" -> 14 and 21.\n"
                . "horizonDays is how far ahead to search, in days. \"the next month or two\" -> 60. \"this fall\" -> 150.\n"
                . "Use null for anything the sentence does not say. Do not guess.",
                [
                    'type'       => 'object',
                    'properties' => [
                        'destName'    => ['type' => ['string', 'null']],
                        'minDays'     => ['type' => ['integer', 'null']],
                        'maxDays'     => ['type' => ['integer', 'null']],
                        'horizonDays' => ['type' => ['integer', 'null']],
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->diag[] = 'llmJson(parse) threw: ' . get_class($e) . ': ' . $e->getMessage();
        }

        if (is_array($parsed)) {
            $modelUsed = 'parse';
            $this->kvPut($host, $key, json_encode($parsed));
            $this->diag[] = 'model parsed the sentence: ' . json_encode($parsed);
            return [$this->mergeIntent($fallback, $heur, $parsed), 'model read of your sentence'];
        }

        $this->diag[] = 'llmJson(parse) returned null; using the built-in text parser';
        return [$this->mergeIntent($fallback, $heur, []), 'built-in text parser (model unavailable)'];
    }

    private function mergeIntent(array $fallback, array $heur, array $model): array
    {
        $out = $fallback;
        foreach (['destName', 'minDays', 'maxDays', 'horizonDays'] as $k) {
            if (isset($heur[$k]) && $heur[$k] !== null)  { $out[$k] = $heur[$k]; }
            if (isset($model[$k]) && $model[$k] !== null) { $out[$k] = $model[$k]; }
        }
        $out['minDays']     = max(1, min(180, (int)$out['minDays']));
        $out['maxDays']     = max($out['minDays'], min(180, (int)$out['maxDays']));
        $out['horizonDays'] = max($out['minDays'] + 1, min(400, (int)$out['horizonDays']));
        if (isset($out['destName'])) {
            $out['destName'] = is_string($out['destName']) ? trim($out['destName']) : null;
            if ($out['destName'] === '') { $out['destName'] = null; }
        }
        return $out;
    }

    /** Deterministic reading of the sentence, used when the model is not there. */
    private function parseIdea(string $idea, array $fallback): array
    {
        $out = ['destName' => null, 'minDays' => null, 'maxDays' => null, 'horizonDays' => null];
        $s   = ' ' . strtolower($idea) . ' ';
        $words = ['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6',
                  'seven' => '7', 'eight' => '8', 'nine' => '9', 'ten' => '10', 'twelve' => '12',
                  'fourteen' => '14', 'twenty' => '20', 'thirty' => '30', 'a couple of' => '2', 'a couple' => '2'];
        foreach ($words as $w => $n) { $s = str_replace(' ' . $w . ' ', ' ' . $n . ' ', $s); }

        $unit = static function (string $u): int {
            if (str_starts_with($u, 'week')) return 7;
            if (str_starts_with($u, 'month')) return 30;
            if (str_starts_with($u, 'night')) return 1;
            return 1;
        };

        if (preg_match('/(\d+)\s*(?:-|–|to|or)\s*(\d+)\s*(day|night|week|month)/', $s, $m)) {
            $out['minDays'] = (int)$m[1] * $unit($m[3]);
            $out['maxDays'] = (int)$m[2] * $unit($m[3]);
        } elseif (preg_match('/(?:for|stay(?:ing)?(?:\s+for)?)\s+(?:about\s+)?(\d+)\s*(day|night|week|month)/', $s, $m)) {
            $out['minDays'] = $out['maxDays'] = (int)$m[1] * $unit($m[2]);
        } elseif (preg_match('/\b(a|one)\s+(week|month)\b/', $s, $m)) {
            $out['minDays'] = $out['maxDays'] = $unit($m[2]);
        }

        if (preg_match('/next\s+(\d+)\s*(day|week|month)/', $s, $m)) {
            $out['horizonDays'] = (int)$m[1] * $unit($m[2]);
        } elseif (preg_match('/next\s+(month|week)\s+or\s+(two|three|2|3)/', $s, $m)) {
            $n = in_array($m[2], ['three', '3'], true) ? 3 : 2;
            $out['horizonDays'] = $unit($m[1]) * $n;
        } elseif (preg_match('/(?:next|coming)\s+(?:couple|few)\s+(?:of\s+)?(day|week|month)/', $s, $m)) {
            $out['horizonDays'] = $unit($m[1]) * 3;
        } elseif (preg_match('/\bnext\s+(month|week)\b/', $s, $m)) {
            $out['horizonDays'] = $unit($m[1]);
        }

        if ($out['minDays'] === null && preg_match('/\b(\d+)\s*(day|night|week|month)s?\b/', $s, $m)) {
            $out['minDays'] = $out['maxDays'] = (int)$m[1] * $unit($m[2]);
        }

        // "visit my sister in Chicago" - the place is after "in", not after "visit"
        $stop = '(?:[,.;]|\s+(?:in|for|next|this|during|over|around|sometime|and)\b|$)';
        if (preg_match('/\b(?:visit(?:ing)?|see(?:ing)?)\s+(?:my|his|her|their|our|the)\s+\w+\s+in\s+(.+?)' . $stop . '/', $s, $m)) {
            $out['destName'] = trim($m[1]);
        } elseif (preg_match('/\b(?:go(?:ing)?|travel(?:ing|ling)?|fly(?:ing)?|trip|head(?:ing)?)\s+to\s+(.+?)' . $stop . '/', $s, $m)) {
            $out['destName'] = trim($m[1]);
        } elseif (preg_match('/\bvisit(?:ing)?\s+(.+?)' . $stop . '/', $s, $m)) {
            $out['destName'] = trim($m[1]);
        } elseif (preg_match('/^\s*([a-z][a-z .\'-]{1,40}(?:,\s*[a-z .\'-]{2,40})?)\s*$/', $s, $m)) {
            // Just a place name typed on its own.
            $out['destName'] = trim($m[1]);
        }
        if ($out['destName'] !== null) {
            $out['destName'] = ucwords(preg_replace('/[^a-z0-9 ,\'-]/i', '', $out['destName']) ?? '');
            if (strlen($out['destName']) < 2) { $out['destName'] = null; }
        }

        return $out;
    }

    // ================================================================== //
    // Place                                                              //
    // ================================================================== //

    /**
     * The host shape-checks a `location` setting but NOT its range - a PATCH
     * with lat 991 is accepted - so range-check it here before it becomes a URL.
     */
    private function isPlace($p): bool
    {
        if (!is_array($p) || !isset($p['lat'], $p['lng'])) { return false; }
        if (!is_numeric($p['lat']) || !is_numeric($p['lng'])) { return false; }
        $lat = (float)$p['lat']; $lng = (float)$p['lng'];
        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
    }

    private function resolvePlace(PluginHost $host, array $cfg, array $intent): ?array
    {
        // The typed sentence wins if it names somewhere we can find.
        if (!empty($intent['destName'])) {
            $hit = $this->geocode($host, (string)$intent['destName']);
            if ($hit !== null) { return $hit; }
            $this->diag[] = 'could not geocode "' . $intent['destName'] . '"; falling back to the destination field';
        }

        $d = $cfg['destination'];
        if ($this->isPlace($d)) {
            $name = (string)($d['name'] ?? 'Destination');
            return ['name' => $name, 'short' => $this->shortName($name),
                    'lat' => (float)$d['lat'], 'lng' => (float)$d['lng'], 'via' => 'settings'];
        }
        return null;
    }

    private function geocode(PluginHost $host, string $q): ?array
    {
        try {
            $r = $host->geocode($q);
            $this->diag[] = 'host geocode(' . $q . ') -> ' . substr(json_encode($r) ?: 'null', 0, 200);
            if ($this->isPlace($r)) {
                $name = (string)($r['name'] ?? $q);
                return ['name' => $name, 'short' => $this->shortName($name),
                        'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'], 'via' => 'host geocoder'];
            }
        } catch (Throwable $e) {
            $this->diag[] = 'host geocode threw: ' . get_class($e) . ': ' . $e->getMessage();
        }

        try {
            $j = $host->http()->getJson(
                'https://geocoding-api.open-meteo.com/v1/search?count=10&language=en&format=json&name='
                . rawurlencode($q)
            );
            $r = $this->bestGeoHit($j['results'] ?? [], $q);
            if ($r !== null) {
                $bits = array_filter([$r['name'] ?? null, $r['admin1'] ?? null, $r['country'] ?? null]);
                $name = implode(', ', $bits);
                return ['name' => $name, 'short' => $this->shortName($name),
                        'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'],
                        'via' => 'Open-Meteo geocoder'];
            }
        } catch (Throwable $e) {
            $this->diag[] = 'open-meteo geocode threw: ' . get_class($e) . ': ' . $e->getMessage();
        }
        return null;
    }

    /**
     * "Hawaii" geocodes first to a village of 1,300 people in Guatemala. Rank by
     * how administratively important the hit is and how many people live there,
     * not by whatever the API happened to put first.
     */
    private function bestGeoHit($results, string $q): ?array
    {
        if (!is_array($results) || !$results) { return null; }

        // "Hawaii" is a state, and this geocoder only holds settlements, so the
        // literal top hit is a village in Guatemala. If the query names a region
        // or country that some results sit inside, plan for the biggest town in
        // it instead.
        $inRegion = [];
        foreach ($results as $r) {
            if (!is_array($r)) { continue; }
            if (strcasecmp(trim((string)($r['admin1'] ?? '')), trim($q)) === 0
                || strcasecmp(trim((string)($r['country'] ?? '')), trim($q)) === 0) {
                $inRegion[] = $r;
            }
        }
        if (count($inRegion) >= 2) {
            $this->diag[] = '"' . $q . '" looks like a region (' . count($inRegion) . ' hits inside it); using its largest town';
            $this->regionGuess = $q;
            $results = $inRegion;
        }

        $rank = ['PCLI' => 6, 'ADM1' => 5, 'ADM2' => 3, 'PPLC' => 5, 'PPLA' => 4, 'PPLA2' => 3, 'PPL' => 1];
        $best = null; $bestScore = -1.0;
        foreach ($results as $r) {
            if (!is_array($r) || !isset($r['latitude'], $r['longitude'])) { continue; }
            $score  = 0.5 * (float)($rank[(string)($r['feature_code'] ?? '')] ?? 0);
            $score += 2.0 * min(6.0, log10(max(1, (int)($r['population'] ?? 1))));
            if (strcasecmp(trim((string)($r['name'] ?? '')), trim($q)) === 0) { $score += 1.0; }
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
        }
        if ($best !== null) {
            $this->diag[] = 'geocode "' . $q . '" -> ' . ($best['name'] ?? '?') . ', ' . ($best['country'] ?? '?')
                . ' (' . ($best['feature_code'] ?? '?') . ', pop ' . ($best['population'] ?? 0) . ')';
        }
        return $best;
    }

    private function shortName(string $n): string
    {
        $first = trim(explode(',', $n)[0]);
        return $first !== '' ? $first : $n;
    }

    // ================================================================== //
    // Calendars and days                                                 //
    // ================================================================== //

    /** @return array<int,array{name:string,policy:string,kind:string,auto:bool}> */
    private function calendarPolicies(PluginHost $host): array
    {
        $out = [];
        foreach ($host->calendars() as $c) {
            $id   = (int)($c['id'] ?? 0);
            if ($id <= 0) { continue; }
            $kind = (string)($c['kind'] ?? 'local');
            $name = (string)($c['name'] ?? ('Calendar ' . $id));

            $chosen = null;
            try {
                $cs = $host->calendarSettings($id);
                $chosen = $cs['travel'] ?? null;
            } catch (Throwable $e) {
                $this->diag[] = 'calendarSettings(' . $id . ') threw: ' . $e->getMessage();
            }

            $policy = $this->policyFor($chosen, $kind);
            $out[$id] = [
                'name'   => $name,
                'policy' => $policy,
                'kind'   => $kind,
                'auto'   => ($chosen === null || $chosen === '' || $chosen === 'Auto'),
            ];
        }
        return $out;
    }

    private function policyFor($chosen, string $kind): string
    {
        $c = is_string($chosen) ? strtolower(trim($chosen)) : '';
        if ($c === 'ignore')             return 'ignore';
        if ($c === 'blocks travel')      return 'block';
        if ($c === 'soft conflict only') return 'soft';
        // Auto. Plugin calendars must never look like commitments (the Weather
        // plugin writes one all-day event per day). Subscribed feeds are other
        // people's information, not the user's obligations.
        if ($kind === 'plugin')     return 'ignore';
        if ($kind === 'subscribed') return 'soft';
        return 'block';
    }

    private function readWindow(PluginHost $host, DateTimeZone $tz, string $from, string $to): array
    {
        $a = $this->localInstant($from, '00:00:00', $tz);
        $b = $this->localInstant($to, '23:59:59', $tz);
        $occ = $host->eventsWindow($a, $b);
        if (!is_array($occ)) { return []; }
        if ($occ) {
            $first = $occ[array_key_first($occ)];
            $this->diag[] = 'eventsWindow sample: ' . substr(json_encode($first) ?: '', 0, 240);
        }
        return $occ;
    }

    /**
     * Split the horizon into hard blocks (a real, specific commitment) and soft
     * ones (recurring things you would move or skip for a real trip).
     */
    private function classifyDays(array $occ, array $policies, DateTimeZone $tz, array $cfg,
                                  string $from, string $to): array
    {
        $hard = [];      // date => [ ['t'=>title,'c'=>calName], ... ]
        $soft = [];
        $byCal = [];     // calId => [date => true]  (soft coverage, for auto-quiet)
        $seen  = [];     // calId => occurrence count inside the horizon
        $skipped = 0;

        foreach ($occ as $o) {
            if (!is_array($o)) { continue; }
            $calId = (int)($o['calendarId'] ?? 0);
            $seen[$calId] = ($seen[$calId] ?? 0) + 1;
            $pol   = $policies[$calId]['policy'] ?? 'block';
            if ($pol === 'ignore') { $skipped++; continue; }

            $span = $this->spanOf($o, $tz);
            if ($span === null) { continue; }
            [$sd, $ed, $mins] = $span;

            $isRecurring = !empty($o['recurring']);
            $isContainer = !empty($o['isContainer']);
            $allDay      = !empty($o['allDay']);

            // Hard means "a specific, unmovable commitment": a trip container, a
            // multi-day or all-day booking, or a long timed event. Everything
            // else - a recurring standup, a two-hour dinner - is something you
            // would reschedule for a real trip, so it is soft.
            $hardness = 'hard'; $why = 'fixed commitment';
            $spansDays = ($sd !== $ed);
            if ($pol === 'soft') {
                $hardness = 'soft';
                $why = ($policies[$calId]['auto'] ?? false)
                    ? 'informational calendar, not a commitment'
                    : 'you marked this calendar "soft conflict only"';
            } elseif ($isContainer) {
                $hardness = 'hard'; $why = 'trip';
            } elseif ($isRecurring && !$cfg['recurringBlocks']) {
                $hardness = 'soft'; $why = 'recurring, so movable';
            } elseif ($allDay || $spansDays) {
                $hardness = 'hard'; $why = $allDay ? 'all-day booking' : 'runs across days';
            } elseif ($mins > 0 && $mins < (int)$cfg['shortEventMinutes']) {
                $hardness = 'soft'; $why = 'short (' . $this->dur($mins) . '), so reschedulable';
            }

            $entry = [
                't' => (string)($o['title'] ?? 'Untitled'),
                'c' => $policies[$calId]['name'] ?? ('Calendar ' . $calId),
                'r' => $isRecurring,
                'k' => $isContainer,
                'w' => $why,
            ];

            $guard = 0;
            for ($d = $sd; strcmp($d, $ed) <= 0; $d = $this->addDays($d, 1)) {
                if (++$guard > 500) { break; }
                if (strcmp($d, $from) < 0 || strcmp($d, $to) > 0) { continue; }
                if ($hardness === 'hard') {
                    $hard[$d][] = $entry;
                } else {
                    $soft[$d][] = $entry;
                    $byCal[$calId][$d] = true;
                }
            }
        }

        return ['hard' => $hard, 'soft' => $soft, 'byCal' => $byCal, 'from' => $from, 'to' => $to,
                'seen' => $seen, 'policies' => $policies, 'skipped' => $skipped, 'quiet' => []];
    }

    /**
     * A task calendar with an all-day chore on every single day is not signal.
     * Detect it, stop scoring against it, and tell the user which switch to flip.
     */
    private function autoQuietNoisyCalendars(array &$load, string $from, string $to, array &$warnings): void
    {
        $total = max(1, $this->daysBetween($from, $to) + 1);
        foreach ($load['byCal'] as $calId => $days) {
            $n = count($days);
            if ($n / $total < 0.7) { continue; }
            $name = $load['policies'][$calId]['name'] ?? ('Calendar ' . $calId);
            $load['quiet'][$calId] = $name;
            $warnings[] = [
                'message'  => sprintf(
                    '"%s" has something on %d of the next %d days, so it tells us nothing about which weeks are free. It is being ignored when scoring windows.',
                    $name, $n, $total
                ),
                'severity' => 'info',
                'fix'      => 'Set that calendar to "Ignore" in its gear panel to make this permanent.',
            ];
        }
        if ($load['quiet']) {
            foreach ($load['soft'] as $d => $rows) {
                $keep = [];
                foreach ($rows as $r) {
                    $isQuiet = false;
                    foreach ($load['quiet'] as $qn) { if ($r['c'] === $qn) { $isQuiet = true; break; } }
                    if (!$isQuiet) { $keep[] = $r; }
                }
                if ($keep) { $load['soft'][$d] = $keep; } else { unset($load['soft'][$d]); }
            }
        }
    }

    /** @return array{0:string,1:string,2:int}|null  [localStartDate, localEndDateInclusive, durationMinutes] */
    private function spanOf(array $o, DateTimeZone $tz): ?array
    {
        $s = (string)($o['start'] ?? '');
        $e = (string)($o['end'] ?? '');
        if ($s === '') { return null; }

        if (!empty($o['allDay'])) {
            // Documented as YYYY-MM-DDT00:00:00+00:00 - a calendar date, NOT an
            // instant. Slice the date out; converting it would move it a day.
            $sd = substr($s, 0, 10);
            $ed = $e !== '' ? substr($e, 0, 10) : $sd;
            $ed = strcmp($ed, $sd) > 0 ? $this->addDays($ed, -1) : $sd;   // end exclusive
            return [$sd, $ed, 0];
        }

        try {
            $st = new DateTimeImmutable($s);
            $en = $e !== '' ? new DateTimeImmutable($e) : $st;
        } catch (Throwable $ex) {
            return null;
        }
        $mins = (int)round(($en->getTimestamp() - $st->getTimestamp()) / 60);
        $sd   = $st->setTimezone($tz)->format('Y-m-d');
        $ed   = $mins > 0
            ? $en->modify('-1 second')->setTimezone($tz)->format('Y-m-d')
            : $sd;
        if (strcmp($ed, $sd) < 0) { $ed = $sd; }
        return [$sd, $ed, max(0, $mins)];
    }

    // ================================================================== //
    // Climate normals                                                    //
    // ================================================================== //

    private function normals(PluginHost $host, array $place, array &$warnings): ?array
    {
        $key    = sprintf('normals:%.2f,%.2f', $place['lat'], $place['lng']);
        $cached = $this->kv($host, $key);
        if (is_string($cached) && $cached !== '') {
            $d = json_decode($cached, true);
            if (is_array($d) && isset($d['at'], $d['md']) && (time() - (int)$d['at']) < self::NORMALS_TTL) {
                $this->diag[] = 'normals: cache hit (' . count($d['md']) . ' calendar days)';
                return $d;
            }
        }

        $endY   = (int)gmdate('Y') - 1;
        $startY = $endY - self::NORMALS_YEARS + 1;
        $url = sprintf(
            'https://archive-api.open-meteo.com/v1/archive?latitude=%.4f&longitude=%.4f'
            . '&start_date=%d-01-01&end_date=%d-12-31'
            . '&daily=temperature_2m_max,temperature_2m_min,precipitation_sum'
            . '&timezone=UTC&temperature_unit=celsius&precipitation_unit=mm',
            $place['lat'], $place['lng'], $startY, $endY
        );

        try {
            $j = $host->http()->getJson($url);
        } catch (Throwable $e) {
            $this->diag[] = 'archive fetch threw: ' . get_class($e) . ': ' . $e->getMessage();
            $warnings[] = [
                'message'  => 'Could not reach the Open-Meteo climate archive, so these windows are ranked on your calendar alone (' . substr($e->getMessage(), 0, 160) . ').',
                'severity' => 'warn',
            ];
            return null;
        }

        $t  = $j['daily']['time'] ?? null;
        $hx = $j['daily']['temperature_2m_max'] ?? null;
        $ln = $j['daily']['temperature_2m_min'] ?? null;
        $pr = $j['daily']['precipitation_sum'] ?? null;
        if (!is_array($t) || !is_array($hx) || !is_array($ln) || !is_array($pr)) {
            $warnings[] = ['message' => 'The climate archive returned an unexpected shape; ranking on the calendar alone.', 'severity' => 'warn'];
            return null;
        }

        $bucket = [];   // 'MM-DD' => [hi[], lo[], wet[], mm[]]
        $n = count($t);
        for ($i = 0; $i < $n; $i++) {
            $md = substr((string)$t[$i], 5, 5);
            if ($md === '' || $hx[$i] === null || $ln[$i] === null) { continue; }
            $bucket[$md][0][] = (float)$hx[$i];
            $bucket[$md][1][] = (float)$ln[$i];
            $p = $pr[$i] === null ? 0.0 : (float)$pr[$i];
            $bucket[$md][2][] = $p >= self::WET_DAY_MM ? 1.0 : 0.0;
            $bucket[$md][3][] = $p;
        }

        $order = [];
        $cur   = new DateTimeImmutable('2024-01-01', new DateTimeZone('UTC'));
        for ($i = 0; $i < 366; $i++) { $order[] = $cur->format('m-d'); $cur = $cur->modify('+1 day'); }

        $md = [];
        $L  = count($order);
        for ($i = 0; $i < $L; $i++) {
            $hi = $lo = $wet = $mm = [];
            for ($k = -self::SMOOTH_DAYS; $k <= self::SMOOTH_DAYS; $k++) {
                $key2 = $order[(($i + $k) % $L + $L) % $L];
                if (!isset($bucket[$key2])) { continue; }
                $hi  = array_merge($hi,  $bucket[$key2][0]);
                $lo  = array_merge($lo,  $bucket[$key2][1]);
                $wet = array_merge($wet, $bucket[$key2][2]);
                $mm  = array_merge($mm,  $bucket[$key2][3]);
            }
            if (!$hi) { continue; }
            $md[$order[$i]] = [
                round($this->mean($hi), 1),
                round($this->mean($lo), 1),
                round($this->mean($wet), 3),
                round($this->mean($mm), 2),
                count($hi),
            ];
        }

        $out = ['at' => time(), 'years' => [$startY, $endY], 'md' => $md,
                'src' => 'Open-Meteo ERA5 archive'];
        $this->kvPut($host, $key, json_encode($out));
        $this->diag[] = sprintf('normals: fetched %d daily rows %d-%d, %d calendar days derived', $n, $startY, $endY, count($md));
        return $out;
    }

    private function mean(array $a): float
    {
        return $a ? array_sum($a) / count($a) : 0.0;
    }

    /** @return array{hi:float,lo:float,wet:float,mm:float,n:int}|null */
    private function windowWeather(?array $normals, array $dates): ?array
    {
        if ($normals === null) { return null; }
        $hi = $lo = $wet = $mm = []; $n = 0;
        foreach ($dates as $d) {
            $k = substr($d, 5, 5);
            if (!isset($normals['md'][$k])) { continue; }
            [$a, $b, $c, $e, $cnt] = $normals['md'][$k];
            $hi[] = $a; $lo[] = $b; $wet[] = $c; $mm[] = $e; $n += (int)$cnt;
        }
        if (!$hi) { return null; }
        return ['hi' => $this->mean($hi), 'lo' => $this->mean($lo),
                'wet' => $this->mean($wet), 'mm' => $this->mean($mm), 'n' => $n];
    }

    private function weatherScore(?array $w): float
    {
        if ($w === null) { return 0.5; }
        $pen = 0.0;
        if ($w['hi'] > 30) { $pen += ($w['hi'] - 30) / 8.0; }
        if ($w['hi'] < 20) { $pen += (20 - $w['hi']) / 12.0; }
        if ($w['lo'] < 8)  { $pen += (8 - $w['lo']) / 12.0; }
        $temp = max(0.0, min(1.0, 1.0 - $pen));
        $dry  = max(0.0, min(1.0, 1.0 - ($w['wet'] / 0.55)));
        return 0.62 * $temp + 0.38 * $dry;
    }

    // ================================================================== //
    // Windows                                                            //
    // ================================================================== //

    private function dateRange(string $a, string $b): array
    {
        $out = [];
        for ($d = $a; strcmp($d, $b) <= 0; $d = $this->addDays($d, 1)) { $out[] = $d; }
        return $out;
    }

    /** Maximal runs of consecutive days with no hard conflict, longest first. */
    private function freeRuns(array $dates, array $hard): array
    {
        $runs = []; $a = null;
        foreach ($dates as $i => $d) {
            $blocked = isset($hard[$d]);
            if (!$blocked && $a === null) { $a = $i; }
            if ($blocked && $a !== null)  { $runs[] = [$a, $i - 1]; $a = null; }
        }
        if ($a !== null) { $runs[] = [$a, count($dates) - 1]; }

        $out = [];
        foreach ($runs as [$i, $j]) {
            $out[] = ['i' => $i, 'j' => $j, 'len' => $j - $i + 1,
                      'start' => $dates[$i], 'end' => $dates[$j]];
        }
        usort($out, static fn(array $x, array $y) => $y['len'] <=> $x['len']);
        return $out;
    }

    private function candidates(array $dates, array $runs, array $load, ?array $normals,
                                int $minDays, int $maxDays, int $buffer, array $cfg): array
    {
        $cands = []; $seen = 0;
        foreach ($runs as $r) {
            $room = $r['len'] - 2 * $buffer;
            if ($room < $minDays) { continue; }
            $top = min($maxDays, $room);
            for ($len = $top; $len >= $minDays; $len--) {
                $lastStart = $r['j'] - $buffer - $len + 1;
                for ($s = $r['i'] + $buffer; $s <= $lastStart; $s++) {
                    if (++$seen > self::MAX_CANDIDATES) { break 3; }
                    $win  = array_slice($dates, $s, $len);
                    $w    = $this->windowWeather($normals, $win);
                    $soft = 0; $softList = []; $softWhy = [];
                    foreach ($win as $d) {
                        if (!empty($load['soft'][$d])) {
                            $soft++;
                            foreach ($load['soft'][$d] as $row) {
                                $softList[$row['t']] = ($softList[$row['t']] ?? 0) + 1;
                                $softWhy[$row['t']]  = $row['w'] ?? '';
                            }
                        }
                    }
                    $ws    = $this->weatherScore($w);
                    $free  = 1.0 - ($len > 0 ? $soft / $len : 0.0);
                    $lenSc = $maxDays > $minDays ? ($len - $minDays) / ($maxDays - $minDays) : 1.0;
                    $slack = min(1.0, ($r['len'] - $len) / 7.0);
                    $score = 0.50 * $ws + 0.25 * $free + 0.15 * $lenSc + 0.10 * $slack;

                    $cands[] = [
                        'start' => $win[0], 'end' => $win[$len - 1], 'len' => $len,
                        'score' => $score, 'weather' => $w, 'wScore' => $ws,
                        'softDays' => $soft, 'softList' => $softList, 'softWhy' => $softWhy, 'freeScore' => $free,
                        'slack' => $slack, 'run' => $r, 'buffer' => $buffer,
                    ];
                }
            }
        }
        return $cands;
    }

    /** Take the best N candidates that do not overlap each other. */
    private function pickDistinct(array $sorted, int $n): array
    {
        $out = [];
        foreach ($sorted as $c) {
            $clash = false;
            foreach ($out as $o) {
                if (strcmp($c['start'], $o['end']) <= 0 && strcmp($o['start'], $c['end']) <= 0) { $clash = true; break; }
            }
            if (!$clash) { $out[] = $c; }
            if (count($out) >= $n) { break; }
        }
        return $out;
    }

    // ================================================================== //
    // Narrative (optional model)                                         //
    // ================================================================== //

    private function narrate(PluginHost $host, array $place, array $best, array $picked,
                             ?array $normals, array $cfg, array $intent): ?array
    {
        $w = $best['weather'];
        $facts = [
            'destination' => $place['name'],
            'window'      => $best['start'] . ' to ' . $best['end'] . ' (' . $best['len'] . ' days)',
            'climate_normals_for_those_dates' => $w === null ? 'unavailable' : [
                'average_daily_high' => $this->temp($w['hi'], $cfg),
                'average_daily_low'  => $this->temp($w['lo'], $cfg),
                'share_of_days_with_measurable_rain' => round($w['wet'] * 100) . '%',
                'source' => ($normals['src'] ?? 'Open-Meteo') . ' ' . ($normals['years'][0] ?? '') . '-' . ($normals['years'][1] ?? ''),
            ],
            'things_still_on_the_calendar_inside_the_window' => array_slice(array_keys($best['softList']), 0, 8),
            'runner_up_windows' => array_map(
                static fn(array $c) => $c['start'] . ' to ' . $c['end'] . ' (' . $c['len'] . ' days)',
                array_slice($picked, 1, 3)
            ),
        ];

        // The docs say myProposals() "deliberately returns no content, so 'has
        // anything actually changed since I last proposed?' is yours to answer -
        // keep a fingerprint of your inputs in kvSet". This is that: the prose
        // is keyed to the facts it describes, so an unchanged answer never costs
        // a second model call.
        $fp    = 'story:' . substr(sha1(json_encode($facts) ?: ''), 0, 20);
        $cache = $this->kv($host, $fp);
        if (is_string($cache) && $cache !== '') {
            $d = json_decode($cache, true);
            if (is_array($d) && isset($d['headline'])) {
                $this->diag[] = 'narrative: cache hit on unchanged facts, no model call spent';
                return $d;
            }
        }

        try {
            $r = $host->llmJson(
                "You are writing a short note for someone deciding whether to take this trip.\n"
                . "Here are the ONLY facts you may use. Do not invent temperatures, rainfall, prices, flight times or events.\n\n"
                . json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
                . "Return ONLY JSON:\n"
                . '{"headline": string, "why": string, "watchOut": string, "activities": [string, ...]}' . "\n\n"
                . "headline: under 90 characters, no dates repeated verbatim.\n"
                . "why: 1-2 sentences on why this stretch suits the trip, referring to the weather in words, not new numbers.\n"
                . "watchOut: 1 sentence naming a real caveat from the facts above (or the season generally). No invented facts.\n"
                . "activities: 3-5 short suggestions of things to do at that destination in that season. Generic-but-true is fine.",
                [
                    'type' => 'object',
                    'properties' => [
                        'headline'   => ['type' => 'string'],
                        'why'        => ['type' => 'string'],
                        'watchOut'   => ['type' => 'string'],
                        'activities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ]
            );
        } catch (Throwable $e) {
            $this->diag[] = 'llmJson(narrative) threw: ' . get_class($e) . ': ' . $e->getMessage();
            return null;
        }

        if (!is_array($r) || !isset($r['headline'])) {
            $this->diag[] = 'llmJson(narrative) returned ' . gettype($r) . '; using deterministic prose';
            return null;
        }
        $r['activities'] = array_slice(array_filter(array_map(
            static fn($x) => is_string($x) ? trim($x) : '',
            (array)($r['activities'] ?? [])
        )), 0, 5);
        $this->kvPut($host, $fp, json_encode($r));
        return $r;
    }

    // ================================================================== //
    // Output                                                             //
    // ================================================================== //

    private function bandHtml(array $place, array $c, int $rank, ?array $normals,
                              array $cfg, array $load, bool $relaxed): string
    {
        $h = [];
        $h[] = '<p><strong>#' . $rank . ' · ' . $this->esc($place['name']) . '</strong> — '
             . $this->esc($this->prettySpan($c['start'], $c['end'])) . ', ' . $c['len'] . ' days.</p>';

        if ($c['weather'] !== null) {
            $h[] = '<p>Typical for these dates: high <strong>' . $this->esc($this->temp($c['weather']['hi'], $cfg))
                 . '</strong>, low <strong>' . $this->esc($this->temp($c['weather']['lo'], $cfg)) . '</strong>, rain on <strong>'
                 . round($c['weather']['wet'] * 100) . '%</strong> of days ('
                 . $this->esc(($normals['src'] ?? 'archive') . ', ' . ($normals['years'][0] ?? '?') . '–' . ($normals['years'][1] ?? '?'))
                 . ').</p>';
        } else {
            $h[] = '<p>No climate data available for this destination; ranked on your calendar alone.</p>';
        }

        $bounds = $this->boundsOf($c, $load);
        if ($bounds) {
            $h[] = '<p><strong>Clear run bounded by:</strong> ' . $this->esc(implode(' · ', $bounds)) . '</p>';
        }

        if ($c['softDays'] > 0) {
            $bits = [];
            foreach (array_slice($c['softList'], 0, 6, true) as $t => $n) {
                $bits[] = $this->esc($t) . ($n > 1 ? ' ×' . $n : '');
            }
            $h[] = '<p><strong>Still on the calendar inside it:</strong> ' . implode(', ', $bits)
                 . ' — recurring, so treated as movable (' . $c['softDays'] . ' of ' . $c['len'] . ' days).</p>';
        } else {
            $h[] = '<p>Nothing at all on the calendar inside this window.</p>';
        }

        $h[] = '<p>score ' . number_format($c['score'], 3)
             . ' = weather ' . number_format($c['wScore'], 2)
             . ' / freedom ' . number_format($c['freeScore'], 2)
             . ' / slack ' . number_format($c['slack'], 2)
             . ($relaxed ? ' — buffer relaxed' : '') . '</p>';

        return implode('', $h);
    }

    /** @return string[] what hems this free run in on each side */
    private function boundsOf(array $c, array $load): array
    {
        $out = [];
        $before = $this->addDays($c['run']['start'], -1);
        $after  = $this->addDays($c['run']['end'], 1);
        foreach ([[$before, 'before'], [$after, 'after']] as [$d, $side]) {
            if (empty($load['hard'][$d])) {
                if (strcmp($d, $load['to']) > 0)   { $out[] = 'After it: nothing - the horizon simply ends here'; }
                if (strcmp($d, $load['from']) < 0) { $out[] = 'Before it: nothing - this is as early as you said you would leave'; }
                continue;
            }
            $titles = [];
            foreach ($load['hard'][$d] as $r) { $titles[$r['t']] = true; }
            $out[] = ucfirst($side) . ' it: ' . implode(', ', array_slice(array_keys($titles), 0, 3))
                   . ' (' . $this->pretty($d) . ')';
        }
        return $out;
    }

    /** @return array{0:bool,1:string} */
    private function offer(PluginHost $host, string $key, array $place, array $best, array $picked,
                           ?array $normals, array $cfg, array $load, DateTimeZone $tz,
                           ?array $story, bool $relaxed): array
    {
        $w = $best['weather'];
        $desc = $this->planNote($place, $best, $picked, $normals, $cfg, $load, $story);

        $plan = [
            'trip' => [
                'title'       => $place['short'] . ' — ' . $best['len'] . ' days',
                'start'       => $best['start'],
                'end'         => $this->addDays($best['end'], 1),   // exclusive
                'location'    => $place['name'],
                'description' => $desc,
            ],
            'events' => [
                [
                    'title'       => 'Travel day → ' . $place['short'],
                    'start'       => $this->localInstant($best['start'], '09:00:00', $tz),
                    'end'         => $this->localInstant($best['start'], '17:00:00', $tz),
                    'location'    => $place['name'],
                    'description' => $desc,
                ],
                [
                    'title'    => 'Travel day → home from ' . $place['short'],
                    'start'    => $this->localInstant($best['end'], '12:00:00', $tz),
                    'end'      => $this->localInstant($best['end'], '20:00:00', $tz),
                    'location' => $place['name'],
                ],
            ],
        ];

        $summaryBits = [
            $best['len'] . ' days with nothing fixed on them',
            $w ? ('typical ' . $this->weatherPhrase($w, $cfg)) : 'no climate data',
        ];
        if ($best['softDays'] > 0) { $summaryBits[] = $best['softDays'] . ' days with movable recurring items'; }

        $proposal = [
            'sourceKey'     => $key,
            'title'         => (empty($cfg['_shortfall']) ? '' : 'Best available (' . $best['len'] . ' of ' . (int)$cfg['_askedMin'] . ' days): ')
                               . ($story['headline'] ?? ($place['short'] . ': ' . $this->prettySpan($best['start'], $best['end']))),
            'summary'       => implode(' · ', $summaryBits),
            'rationaleHtml' => $this->rationaleHtml($place, $best, $picked, $normals, $cfg, $load, $story, $relaxed),
            'plan'          => $plan,
        ];

        try {
            $r = $host->propose($proposal);
            $this->rememberOffer($host, $key, $this->slug($place['short']), $best);
            $this->diag[] = 'propose() -> ' . substr(json_encode($r) ?: '', 0, 300);
            return [true, ''];
        } catch (Throwable $e) {
            $this->diag[] = 'propose() threw: ' . get_class($e) . ': ' . $e->getMessage();
            throw $e;
        }
    }

    /**
     * The plain-text plan that rides along on the accepted trip's description.
     *
     * The original request asked for "a markdown note/plan that's downloadable".
     * A plugin cannot serve a file - there is no request-path hook and no static
     * asset route - so this is the closest honest thing: the whole plan as
     * Markdown, on the trip itself, where it can be read and copied out.
     */
    private function planNote(array $place, array $best, array $picked, ?array $normals,
                              array $cfg, array $load, ?array $story): string
    {
        $w = $best['weather'];
        $L = [];
        $L[] = '# ' . $place['name'] . ' — ' . $best['len'] . ' days';
        $L[] = $this->prettySpan($best['start'], $best['end']) . ' ' . substr($best['start'], 0, 4);
        $L[] = '';
        if ($story !== null && !empty($story['why'])) { $L[] = (string)$story['why']; $L[] = ''; }

        $L[] = '## Typical weather for these dates';
        if ($w !== null) {
            $L[] = '- High ' . $this->temp($w['hi'], $cfg) . ' / low ' . $this->temp($w['lo'], $cfg);
            $L[] = '- Measurable rain on ' . round($w['wet'] * 100) . '% of days (' . number_format($w['mm'], 1) . ' mm/day mean)';
            $L[] = '- Source: ' . ($normals['src'] ?? 'n/a') . ', ' . ($normals['years'][0] ?? '?') . '-' . ($normals['years'][1] ?? '?')
                 . ', same calendar dates, +/-' . self::SMOOTH_DAYS . ' days pooled';
        } else {
            $L[] = '- Unavailable for this destination';
        }
        $L[] = '';

        $L[] = '## Why this window';
        $L[] = '- Clear run: ' . $this->prettySpan($best['run']['start'], $best['run']['end'])
             . ' (' . $best['run']['len'] . ' days with nothing fixed)';
        foreach ($this->boundsOf($best, $load) as $b) { $L[] = '- ' . $b; }
        if ($best['softDays'] > 0) {
            $L[] = '- Still to move or skip (' . $best['softDays'] . ' of ' . $best['len'] . ' days):';
            foreach (array_slice($best['softList'], 0, 10, true) as $t => $n) {
                $L[] = '  - ' . $t . ($n > 1 ? ' x' . $n : '') . ' — ' . ($best['softWhy'][$t] ?? '');
            }
        } else {
            $L[] = '- Nothing at all is on the calendar inside this window';
        }
        $L[] = '';

        if (count($picked) > 1) {
            $L[] = '## Runners-up';
            foreach (array_slice($picked, 1) as $c) {
                $L[] = '- ' . $this->prettySpan($c['start'], $c['end']) . ' (' . $c['len'] . ' days'
                     . ($c['weather'] !== null ? ', ' . $this->weatherPhrase($c['weather'], $cfg) : '') . ')';
            }
            $L[] = '';
        }

        if ($story !== null && !empty($story['activities'])) {
            $L[] = '## While you are there';
            foreach ($story['activities'] as $a) { $L[] = '- ' . $a; }
            if (!empty($story['watchOut'])) { $L[] = ''; $L[] = 'Watch out: ' . $story['watchOut']; }
            $L[] = '';
        }

        $L[] = '---';
        $L[] = 'Planned by the Trip Planner plugin. Every date and weather figure above is computed'
             . ($story !== null ? '; only the wording is from the language model.' : '.');
        $L[] = 'Times on the two travel days are placeholders — change them when you book.';

        return implode("\n", $L);
    }

    private function rationaleHtml(array $place, array $best, array $picked, ?array $normals,
                                   array $cfg, array $load, ?array $story, bool $relaxed): string
    {
        $h = [];
        $w = $best['weather'];

        $h[] = '<p><strong>' . $this->esc($place['name']) . '</strong>, '
             . $this->esc($this->prettySpan($best['start'], $best['end'])) . ' — '
             . $best['len'] . ' days.</p>';

        if ($story !== null && !empty($story['why'])) {
            $h[] = '<p>' . $this->esc((string)$story['why']) . '</p>';
        } else {
            $h[] = '<p>This is the longest genuinely clear stretch in the horizon that also lands in decent weather. '
                 . 'Nothing here is a guess: the dates come from your calendar and the weather from the climate archive.</p>';
        }

        // --- why this window, in numbers
        $h[] = '<p><strong>Why this window</strong></p><ul>';
        $h[] = '<li><strong>Free run:</strong> ' . $this->esc($this->prettySpan($best['run']['start'], $best['run']['end']))
             . ' (' . $best['run']['len'] . ' days with no fixed commitment), and the trip sits inside it'
             . ($best['buffer'] > 0 ? ' with ' . $best['buffer'] . ' clear day' . ($best['buffer'] === 1 ? '' : 's') . ' either side' : '') . '.</li>';
        $bnd = $this->boundsOf($best, $load);
        if ($bnd) {
            $h[] = '<li><strong>Bounded by:</strong> ' . $this->esc(implode('; ', $bnd)) . '</li>';
        }
        if ($w !== null) {
            $h[] = '<li><strong>Weather:</strong> average high ' . $this->esc($this->temp($w['hi'], $cfg))
                 . ', average low ' . $this->esc($this->temp($w['lo'], $cfg))
                 . ', measurable rain on ' . round($w['wet'] * 100) . '% of days, '
                 . number_format($w['mm'], 1) . ' mm/day mean. Source: '
                 . $this->esc((string)($normals['src'] ?? 'archive')) . ', '
                 . $this->esc((string)($normals['years'][0] ?? '?')) . '–'
                 . $this->esc((string)($normals['years'][1] ?? '?')) . ' for the same calendar dates (±'
                 . self::SMOOTH_DAYS . ' days pooled).</li>';
        } else {
            $h[] = '<li><strong>Weather:</strong> unavailable for this destination — ranked on the calendar alone.</li>';
        }
        if ($best['softDays'] > 0) {
            $bits = [];
            foreach (array_slice($best['softList'], 0, 8, true) as $t => $n) {
                $w2 = $best['softWhy'][$t] ?? '';
                $bits[] = '<li>' . $this->esc($t) . ($n > 1 ? ' ×' . $n : '')
                        . ($w2 !== '' ? ' — ' . $this->esc($w2) . '' : '') . '</li>';
            }
            $h[] = '<li><strong>You would still be moving or skipping</strong> things on '
                 . $best['softDays'] . ' of the ' . $best['len'] . ' days:<ul>' . implode('', $bits) . '</ul></li>';
        } else {
            $h[] = '<li><strong>Nothing at all</strong> is on the calendar inside this window.</li>';
        }
        if (!empty($cfg['_shortfall'])) {
            $h[] = '<li><strong>This is shorter than you asked for.</strong> You wanted '
                 . (int)$cfg['_askedMin'] . ' days; ' . $best['len']
                 . ' is the longest clear run anywhere in the horizon.</li>';
        }
        if ($relaxed) {
            $h[] = '<li>No window had a completely clear day on both sides, so that requirement was dropped.</li>';
        }
        $h[] = '</ul>';

        // --- the runners-up, and why they lost
        if (count($picked) > 1) {
            $h[] = '<p><strong>The other windows, and why they came second</strong></p><ul>';
            foreach (array_slice($picked, 1) as $c) {
                $why = [];
                if ($c['wScore'] < $best['wScore'] - 0.02) {
                    $why[] = $c['weather'] !== null
                        ? 'wetter/less comfortable (' . round($c['weather']['wet'] * 100) . '% rain days vs '
                          . round(($w['wet'] ?? 0) * 100) . '%)'
                        : 'no weather data';
                }
                if ($c['len'] < $best['len']) { $why[] = $c['len'] . ' days instead of ' . $best['len']; }
                if ($c['softDays'] > $best['softDays']) { $why[] = 'more to reschedule (' . $c['softDays'] . ' days)'; }
                if (!$why) { $why[] = 'very close; the tiebreak went to the earlier start'; }
                $h[] = '<li>' . $this->esc($this->prettySpan($c['start'], $c['end'])) . ' (' . $c['len'] . ' days'
                     . ($c['weather'] !== null ? ', ' . $this->esc($this->weatherPhrase($c['weather'], $cfg)) : '')
                     . ') — ' . $this->esc(implode('; ', $why)) . '.</li>';
            }
            $h[] = '</ul>';
        }

        // --- what got in the way everywhere else
        $blocks = $this->hardSpans($load);
        if ($blocks) {
            $h[] = '<p><strong>What ruled the rest of the horizon out</strong></p><ul>';
            foreach (array_slice($blocks, 0, 6) as $b) {
                $h[] = '<li>' . $this->esc($this->prettySpan($b['start'], $b['end'])) . ' — '
                     . $this->esc(implode(', ', array_slice($b['titles'], 0, 3)))
                     . (count($b['titles']) > 3 ? ' and ' . (count($b['titles']) - 3) . ' more' : '') . '</li>';
            }
            $h[] = '</ul>';
        }

        if ($story !== null) {
            if (!empty($story['activities'])) {
                $h[] = '<p><strong>While you are there</strong></p><ul>';
                foreach ($story['activities'] as $a) { $h[] = '<li>' . $this->esc((string)$a) . '</li>'; }
                $h[] = '</ul>';
            }
            if (!empty($story['watchOut'])) {
                $h[] = '<p><strong>Watch out:</strong> ' . $this->esc((string)$story['watchOut']) . '</p>';
            }
            $h[] = '<p>Wording from the language model; every date and number above is computed, not generated.</p>';
        } else {
            $h[] = '<p>Written without the language model (it was unavailable or not configured). '
                 . 'All dates and numbers are computed either way.</p>';
        }

        // --- how the calendars were treated
        $rows = [];
        foreach ($load['policies'] as $id => $p) {
            $seen = (int)($load['seen'][$id] ?? 0);
            if ($seen === 0 && $p['auto']) { continue; }   // empty and untouched: nothing to say
            $label = ['block' => 'blocks travel', 'soft' => 'counted but not blocking', 'ignore' => 'ignored'][$p['policy']] ?? $p['policy'];
            if (isset($load['quiet'][$id])) { $label .= ', auto-quieted because it has something on nearly every day'; }
            $rows[] = '<li>' . $this->esc($p['name']) . ' (' . $seen . ' occurrence' . ($seen === 1 ? '' : 's') . ') — ' . $this->esc($label)
                 . ($p['auto'] ? ' — automatic, because it is a ' . $this->esc($p['kind']) . ' calendar' : ' — your setting')
                 . '</li>';
        }
        if ($rows) {
            $h[] = '<p><strong>How your calendars were read</strong></p><ul>' . implode('', $rows) . '</ul>';
        }
        $h[] = '<p><strong>Nothing is on your calendar yet.</strong> Accepting creates the trip container and two placeholder travel days; you can undo it in one step.</p>';

        return implode('', $h);
    }

    /** Contiguous hard-blocked spans across the horizon, biggest first. */
    private function hardSpans(array $load): array
    {
        $days = array_keys($load['hard']);
        sort($days);
        $out = []; $cur = null;
        foreach ($days as $d) {
            if ($cur !== null && $this->addDays($cur['end'], 1) === $d) {
                $cur['end'] = $d;
            } else {
                if ($cur !== null) { $out[] = $cur; }
                $cur = ['start' => $d, 'end' => $d, 'titles' => []];
            }
            foreach ($load['hard'][$d] as $r) { $cur['titles'][$r['t']] = true; }
        }
        if ($cur !== null) { $out[] = $cur; }
        foreach ($out as &$o) { $o['titles'] = array_keys($o['titles']); $o['len'] = $this->daysBetween($o['start'], $o['end']) + 1; }
        unset($o);
        usort($out, static fn(array $a, array $b) => $b['len'] <=> $a['len']);
        return $out;
    }

    // ================================================================== //
    // Small helpers                                                      //
    // ================================================================== //

    private function cfg(array $stored): array
    {
        $this->diag[] = 'settings() returned keys: ' . implode(',', array_keys($stored));
        $out = self::DEFAULTS;
        foreach ($stored as $k => $v) {
            if ($v === null || $v === '') {
                if (!array_key_exists($k, $out) || $out[$k] === null || $out[$k] === '') { $out[$k] = $v; }
                continue;
            }
            $out[$k] = $v;
        }
        foreach (['minDays', 'maxDays', 'horizonDays', 'earliestDays', 'bufferDays', 'shortEventMinutes', 'candidates'] as $k) {
            $out[$k] = (int)$out[$k];
        }
        foreach (['recurringBlocks', 'makeProposal', 'notifyOnNew', 'debug'] as $k) {
            $out[$k] = filter_var($out[$k], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$out[$k];
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$out['bandColor'])) { $out['bandColor'] = '#3f9d6b'; }
        return $out;
    }

    private function diagWarnings(array $cfg): array
    {
        if (empty($cfg['debug'])) { return []; }
        $out = [];
        foreach (array_slice($this->diag, 0, 40) as $i => $d) {
            $out[] = ['message' => 'DIAG ' . ($i + 1) . ': ' . substr($d, 0, 460), 'severity' => 'info'];
        }
        return $out;
    }

    private function kv(PluginHost $host, string $k)
    {
        try { return $host->kvGet($k); } catch (Throwable $e) {
            $this->diag[] = 'kvGet(' . $k . ') threw: ' . $e->getMessage();
            return null;
        }
    }

    private function kvPut(PluginHost $host, string $k, $v): void
    {
        try { $host->kvSet($k, $v); } catch (Throwable $e) {
            $this->diag[] = 'kvSet(' . $k . ') threw: ' . $e->getMessage();
        }
    }

    private function addDays(string $ymd, int $n): string
    {
        $t = mktime(12, 0, 0, (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2) + $n, (int)substr($ymd, 0, 4));
        return date('Y-m-d', $t ?: time());
    }

    private function daysBetween(string $a, string $b): int
    {
        $ta = mktime(12, 0, 0, (int)substr($a, 5, 2), (int)substr($a, 8, 2), (int)substr($a, 0, 4));
        $tb = mktime(12, 0, 0, (int)substr($b, 5, 2), (int)substr($b, 8, 2), (int)substr($b, 0, 4));
        return (int)round((($tb ?: 0) - ($ta ?: 0)) / 86400);
    }

    private function localInstant(string $ymd, string $his, DateTimeZone $tz): string
    {
        $d = new DateTimeImmutable($ymd . ' ' . $his, $tz);
        return $d->format('Y-m-d\TH:i:sP');
    }

    private function temp(float $c, array $cfg): string
    {
        return ($cfg['units'] === 'C')
            ? round($c) . '°C'
            : round($c * 9 / 5 + 32) . '°F';
    }

    private function weatherPhrase(?array $w, array $cfg): string
    {
        if ($w === null) { return 'weather unknown'; }
        return sprintf('%s / %s, rain %d%% of days',
            $this->temp($w['hi'], $cfg), $this->temp($w['lo'], $cfg), round($w['wet'] * 100));
    }

    private function pretty(string $ymd): string
    {
        return date('D j M', (int)mktime(12, 0, 0, (int)substr($ymd, 5, 2), (int)substr($ymd, 8, 2), (int)substr($ymd, 0, 4)));
    }

    /**
     * Show the year only when it is not obvious. `date('Y')` would be the
     * SERVER's year (Berlin here), which is the same class of mistake the docs
     * warn about for `date_default_timezone_get()`, so the current year comes
     * from the user's clock via runJob.
     */
    private function prettySpan(string $a, string $b): string
    {
        $ya = substr($a, 0, 4);
        $yb = substr($b, 0, 4);
        if ($ya !== $yb) {
            return $this->pretty($a) . ' ' . $ya . ' – ' . $this->pretty($b) . ' ' . $yb;
        }
        return $this->pretty($a) . ' – ' . $this->pretty($b)
             . ($ya !== $this->thisYear ? ' ' . $yb : '');
    }

    private function slug(string $s): string
    {
        $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s) ?? '');
        return trim($s, '-') ?: 'trip';
    }

    /**
     * There is no host API to withdraw a proposal. `propose()` replaces an OPEN
     * proposal with the same sourceKey and permanently no-ops on a decided one,
     * so a key that encodes the window (…-2026-10-12-21) leaves a stale open
     * proposal behind every time the answer moves, and there is no way to clean
     * them up. So this plugin uses ONE key at a time - a generation counter -
     * which means there is never more than one open Trip Planner proposal. A
     * rejection burns that generation and the next run offers the next-best
     * window under the next one.
     */
    private function keyFor(int $gen): string
    {
        return 'trip-plan-g' . $gen;
    }

    /** sourceKey => {slug,start,end,len} for every window I have offered. */
    private function offers(PluginHost $host): array
    {
        $raw = $this->kv($host, 'offers');
        $d   = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }

    private function rememberOffer(PluginHost $host, string $key, string $slug, array $c): void
    {
        $all = $this->offers($host);
        $all[$key] = ['slug' => $slug, 'start' => $c['start'], 'end' => $c['end'], 'len' => $c['len']];
        if (count($all) > 60) { $all = array_slice($all, -60, null, true); }
        $this->kvPut($host, 'offers', json_encode($all));
    }

    /**
     * The lowest generation whose key is not already decided, plus anything the
     * user has rejected for this destination.
     *
     * @return array{gen:int,accepted:?array,rejected:array<int,array{0:string,1:string}>}
     */
    private function generation(PluginHost $host, array $decided, string $slug, string $today): array
    {
        $offers   = $this->offers($host);
        $rejected = [];
        $accepted = null;

        foreach ($decided as $k => $status) {
            $o = $offers[$k] ?? null;
            if (!is_array($o) || ($o['slug'] ?? null) !== $slug) { continue; }
            if ($status === 'rejected') {
                $rejected[] = [(string)$o['start'], (string)$o['end']];
            } elseif ($status === 'accepted' && strcmp((string)$o['end'], $today) >= 0) {
                $accepted = ['key' => $k, 'start' => (string)$o['start'], 'end' => (string)$o['end']];
            }
        }

        $gen = (int)($this->kv($host, 'gen') ?? 0);
        $spin = 0;
        while (($decided[$this->keyFor($gen)] ?? 'open') !== 'open' && ++$spin < 500) {
            $gen++;
        }
        if ($gen !== (int)($this->kv($host, 'gen') ?? 0)) {
            $this->kvPut($host, 'gen', (string)$gen);
            $this->diag[] = 'generation advanced to g' . $gen . ' (previous ones were decided)';
        }

        return ['gen' => $gen, 'accepted' => $accepted, 'rejected' => $rejected];
    }

    /** More than half of this window sits inside something you already said no to. */
    private function overlapsRejected(array $c, array $rejected): bool
    {
        foreach ($rejected as [$rs, $re]) {
            $a = strcmp($c['start'], $rs) > 0 ? $c['start'] : $rs;
            $b = strcmp($c['end'], $re) < 0 ? $c['end'] : $re;
            if (strcmp($a, $b) > 0) { continue; }
            $shared = $this->daysBetween($a, $b) + 1;
            if ($shared >= 0.5 * $c['len']) { return true; }
        }
        return false;
    }

    /** @return array<string,string> sourceKey => status, for every proposal I have ever made */
    private function decidedKeys(PluginHost $host): array
    {
        try {
            $mine = $host->myProposals(null);
        } catch (Throwable $e) {
            $this->diag[] = 'myProposals threw: ' . get_class($e) . ': ' . $e->getMessage();
            return [];
        }
        $this->diag[] = 'myProposals(null) -> ' . substr(json_encode($mine) ?: '', 0, 400);
        $out = [];
        foreach ((array)$mine as $p) {
            if (is_array($p) && isset($p['sourceKey'])) {
                $out[(string)$p['sourceKey']] = (string)($p['status'] ?? 'open');
            }
        }
        return $out;
    }

    private function dur(int $mins): string
    {
        if ($mins < 60) { return $mins . ' min'; }
        $h = $mins / 60;
        return (fmod($h, 1.0) === 0.0 ? (string)(int)$h : number_format($h, 1)) . ' h';
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Slightly step the band colour down for lower-ranked windows. */
    private function shade(string $hex, int $rank): string
    {
        if ($rank === 0) { return $hex; }
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $f = 1.0 - min(0.55, 0.22 * $rank);
        $mix = static fn(int $c): int => (int)round($c * $f + 255 * (1 - $f) * 0.55);
        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }
};
