<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Infra\Db;
use BetterCal\Infra\HttpClient;
use BetterCal\Infra\LlmGateway;
use BetterCal\Support\Time;

/**
 * The sites this handles on purpose, and when each was last checked, are
 * listed in docs/quick-add.md (Event Links): keep that list in step when a
 * site reader is added here or a site changes.
 *
 * An event page's link in quick add (#105): shared from a phone or pasted,
 * a Luma, Eventbrite or Meetup page becomes a filled-in draft. The page is
 * read the way email ingest reads a booking mail (MailIngest): schema.org
 * event markup first, then an "Add to Google Calendar" link on the page,
 * then the model over the page's text, only when one is set up and the
 * text has a date in it. The draft fills quick add's strip to be checked;
 * nothing here saves it.
 *
 * The page is fetched with the feeds' policied client: http(s) only,
 * resolved before connecting, private and metadata addresses refused, no
 * cookies or credentials, size and time capped. A page behind a sign-in
 * yields nothing, and quick add says so.
 */
final class EventPage
{
    /** Words allowed around the link: a shared page often comes with its title. */
    public const EXTRA_WORDS = 30;
    private const MAX_BYTES = 3 * 1024 * 1024;
    private const MODEL_CHARS = 4000;
    /**
     * Quick add's preview reads the text again after every pause in typing;
     * the page is read once and the result kept this long: the draft (with
     * the event's own link) under a hash of the address, never the page.
     * Expired ones are deleted as the cache is used.
     */
    private const CACHE_SECONDS = 900;
    private const CACHE_PREFIX = 'event-page:';

    /** @param ?\Closure(string):array{status:int,body:string} $fetch for tests */
    public function __construct(
        private readonly Db $db,
        private readonly LlmGateway $llm,
        private readonly ?\Closure $fetch = null,
    ) {
    }

    /**
     * The page a quick add text is about: one http(s) link, with at most a
     * few words around it. Google Calendar template links and .ics files keep
     * their own routes.
     */
    public static function urlIn(string $text): ?string
    {
        if (preg_match_all('~\bhttps?://[^\s<>"\'`]+~i', $text, $m) !== 1) {
            return null;
        }
        $raw = $m[0][0];
        $url = rtrim($raw, '.,;:!?)]}');
        if (GcalLink::isTemplateUrl($url) || preg_match('~\.ics(?:[?#]|$)~i', $url) === 1) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || strlen($url) > 2048) {
            return null;
        }
        $rest = trim(str_replace($raw, ' ', $text));
        if ($rest !== '' && count(preg_split('/\s+/u', $rest) ?: []) > self::EXTRA_WORDS) {
            return null;
        }
        return $url;
    }

    /**
     * The event a page's HTML describes, without the model: its markup, else
     * an "Add to Google Calendar" link on it. With several (a group's page of
     * meetups), the first that hasn't ended.
     *
     * @return ?array the quick add draft shape (GcalLink::parse), plus url
     */
    public static function fromHtml(string $html, string $pageUrl, string $tz, \DateTimeImmutable $now): ?array
    {
        $candidates = [];
        foreach (MailIngest::extractLdJsonEvents($html) as $ld) {
            $candidates[] = $ld + ['source' => 'page-markup'];
        }
        if ($candidates === []) {
            foreach (MailIngest::extractGcalLinks($html) as $link) {
                $g = GcalLink::parse($link, $tz);
                if ($g !== null) {
                    $candidates[] = [
                        'title' => $g['title'], 'start' => $g['start'], 'end' => $g['end'], 'allDay' => $g['allDay'],
                        'location' => $g['location'], 'tzid' => $g['tzid'] ?? null, 'url' => null, 'source' => 'page-gcal',
                    ];
                }
            }
        }
        $first = null;
        foreach ($candidates as $c) {
            $draft = self::draft($c, $pageUrl, $tz);
            if ($draft === null) {
                continue;
            }
            $first ??= $draft;
            if (new \DateTimeImmutable($draft['end']) > $now) {
                return $draft;
            }
        }
        return $first;
    }

    /**
     * Sites whose pages draw the event in the browser, so their HTML has no
     * date, but which serve it from a public address of their own: that
     * address, and the reader for its answer. Pie's plan pages are one.
     *
     * @return ?array{0:string,1:callable(array,string):?array}
     */
    public static function siteSource(string $url): ?array
    {
        if (preg_match('~^https://(?:plan\.|www\.)?getpie\.app/plans/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:[/?#]|$)~i', $url, $m) === 1) {
            return ['https://plans-service.getpie.app/api/public/v2/plans/details/' . strtolower($m[1]), [self::class, 'fromPie']];
        }
        return null;
    }

    /** A Pie plan's public details as a draft: its times, its own zone, the venue and address. */
    public static function fromPie(array $j, string $pageUrl): ?array
    {
        $title = is_string($j['planTitle'] ?? null) ? $j['planTitle'] : '';
        $start = is_string($j['startAt'] ?? null) ? $j['startAt'] : '';
        if (trim($title) === '' || $start === '') {
            return null;
        }
        $zone = $j['analytics']['metadata']['timezone'] ?? null;
        $place = implode(', ', array_filter([
            is_string($j['locationString'] ?? null) ? trim($j['locationString']) : '',
            is_string($j['addressString'] ?? null) ? trim($j['addressString']) : '',
        ]));
        return self::draft([
            'title' => $title,
            'start' => $start,
            'end' => is_string($j['endAt'] ?? null) ? $j['endAt'] : null,
            'allDay' => false,
            'location' => $place !== '' ? $place : null,
            'tzid' => is_string($zone) ? $zone : null,
            'url' => $pageUrl,
            'source' => 'page-site',
        ], $pageUrl, 'UTC');
    }

    /** The page as text for the model: its title, then what a reader sees. */
    public static function textForModel(string $html): string
    {
        $title = '';
        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) === 1) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        }
        $body = preg_replace('~<head\b.*?</head>|<title\b.*?</title>~is', ' ', $html) ?? $html;
        $text = MailIngest::flattenHtml($body);
        return mb_substr(trim($title . "\n\n" . $text), 0, self::MODEL_CHARS);
    }

    /**
     * Read the page for quick add. `note` says why there's no draft, in words
     * for the owner, or null when there is one.
     *
     * @return array{draft: ?array, note: ?string}
     */
    public function read(int $userId, string $url, string $tz, \DateTimeImmutable $now, bool $modelAllowed, ?ModelAdmission $admission = null, ?int $tokenId = null): array
    {
        $key = hash('sha256', self::CACHE_PREFIX . $userId . '|' . $tz . '|' . $url);
        $cached = $this->cached($key);
        if ($cached !== null) {
            return $cached;
        }
        // A site with its own public address for the event: ask there first,
        // and read the page as usual if that doesn't answer.
        $site = self::siteSource($url);
        if ($site !== null) {
            try {
                $r = $this->get($site[0], 'application/json');
                $j = (int) ($r['status'] ?? 0) === 200 ? json_decode((string) ($r['body'] ?? ''), true) : null;
                $draft = is_array($j) ? ($site[1])($j, $url) : null;
                if ($draft !== null) {
                    return $this->keep($key, ['draft' => $draft, 'note' => null]);
                }
            } catch (\RuntimeException) {
                // fall back to the page
            }
        }
        try {
            $r = $this->get($url, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
        } catch (\RuntimeException) {
            return ['draft' => null, 'note' => 'Couldn\'t open that page.'];
        }
        $status = (int) ($r['status'] ?? 0);
        $html = (string) ($r['body'] ?? '');
        if ($status === 401 || $status === 403) {
            return $this->keep($key, ['draft' => null, 'note' => 'That page needs a sign-in, so there was nothing to read.']);
        }
        if ($status < 200 || $status >= 300 || stripos($html, '<') === false) {
            return ['draft' => null, 'note' => 'Couldn\'t open that page.'];
        }

        $draft = self::fromHtml($html, $url, $tz, $now);
        $note = null;
        if ($draft === null && $modelAllowed && $this->llm->isConfigured()) {
            $text = self::textForModel($html);
            if (MailIngest::hasDateEvidence($text)) {
                $admitter = $admission ?? new ModelAdmission($this->db);
                $reserved = $admitter->reserveQuickAdd($userId, $tokenId, $now);
                if ($reserved['allowed']) {
                    try {
                        $parsed = $this->llm->parseEvent($text, $now, $tz, true);
                        if ($parsed !== null && !empty($parsed['title']) && $parsed['title'] !== 'New event') {
                            $draft = self::draft([
                                'title' => $parsed['title'], 'start' => $parsed['start'], 'end' => $parsed['end'],
                                'allDay' => (bool) $parsed['allDay'], 'location' => $parsed['location'] ?? null,
                                'tzid' => $parsed['tzid'] ?? null, 'url' => null, 'source' => 'page-llm',
                            ], $url, $tz);
                        }
                    } catch (\Throwable $e) {
                        error_log('event page model failure: ' . $e->getMessage());
                    } finally {
                        $admitter->finish($reserved['reservationId']);
                    }
                } else {
                    // Out of model capacity is temporary: not kept.
                    return ['draft' => null, 'note' => (string) ($reserved['message'] ?? 'AI reading is paused for now.')];
                }
            }
        }
        if ($draft === null) {
            $note = 'No event found on that page.';
        }
        return $this->keep($key, ['draft' => $draft, 'note' => $note]);
    }

    /** One candidate as a quick add draft, or null when its date can't be read. */
    private static function draft(array $c, string $pageUrl, string $tz): ?array
    {
        $when = MailIngest::draftWhen($c, $tz);
        if ($when === null) {
            return null;
        }
        [$start, $end, $allDay, $zone] = $when;
        $z = Time::zone($zone);
        $clean = static fn(?string $s, int $max): ?string => ($s = trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5))) !== '' ? mb_substr($s, 0, $max) : null;
        $own = is_string($c['url'] ?? null) && preg_match('~^https?://~i', $c['url']) === 1 ? $c['url'] : $pageUrl;
        return [
            'title' => $clean($c['title'] ?? null, 300) ?? 'New event',
            'start' => Time::iso($start->setTimezone($z)),
            'end' => Time::iso($end->setTimezone($z)),
            'allDay' => $allDay,
            'location' => self::tidyPlace($clean($c['location'] ?? null, 500)),
            'description' => null,
            'rrule' => null,
            'personNames' => [],
            'confidence' => 0.95,
            'source' => (string) $c['source'],
            'tzid' => $zone,
            'url' => mb_substr($own, 0, 2048),
        ];
    }

    /**
     * Pages often name the place's parts twice (a venue called after its
     * city, then the city again): each comma-separated part is kept once,
     * and one already said in what comes before it is dropped.
     * "Example Hall, Exampleton, CA, Exampleton, CA" -> "Example Hall, Exampleton, CA".
     */
    public static function tidyPlace(?string $place): ?string
    {
        if ($place === null) {
            return null;
        }
        $kept = [];
        $said = '';
        foreach (preg_split('/\s*,\s*/u', $place) ?: [] as $part) {
            $norm = mb_strtolower(trim($part));
            if ($norm === '' || ($said !== '' && preg_match('/(?:^|\W)' . preg_quote($norm, '/') . '(?:\W|$)/u', $said) === 1)) {
                continue;
            }
            $kept[] = trim($part);
            $said .= ' ' . $norm;
        }
        return $kept !== [] ? implode(', ', $kept) : null;
    }

    /** @return array{status:int,body:string} */
    private function get(string $url, string $accept): array
    {
        return $this->fetch !== null ? ($this->fetch)($url) : $this->http()->get($url, ['Accept: ' . $accept]);
    }

    private function http(): HttpClient
    {
        return new HttpClient(
            requestBudget: 6,
            userAgent: HttpClient::userAgentFor('event page'),
            maxBytes: self::MAX_BYTES,
            maxRedirects: 5,
            totalTimeoutMs: 12_000,
        );
    }

    /** @return ?array{draft: ?array, note: ?string} */
    private function cached(string $key): ?array
    {
        try {
            // This cache's expired rows go as it's used: nothing else prunes them.
            $this->db->run('DELETE FROM http_cache WHERE url LIKE ? AND expires_at <= ?', [self::CACHE_PREFIX . '%', Time::nowDb()]);
            $body = $this->db->scalar('SELECT body FROM http_cache WHERE url_hash = ? AND expires_at > ?', [$key, Time::nowDb()]);
        } catch (\PDOException) {
            return null;
        }
        $data = is_string($body) ? json_decode($body, true) : null;
        return is_array($data) && array_key_exists('draft', $data) ? ['draft' => $data['draft'], 'note' => $data['note'] ?? null] : null;
    }

    /** @param array{draft: ?array, note: ?string} $result */
    private function keep(string $key, array $result): array
    {
        try {
            $now = Time::nowDb();
            $expires = Time::toDb(Time::nowUtc()->modify('+' . self::CACHE_SECONDS . ' seconds'));
            $this->db->run(
                'INSERT INTO http_cache (url_hash, url, status, body, fetched_at, expires_at) VALUES (?, ?, 200, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE body = VALUES(body), fetched_at = VALUES(fetched_at), expires_at = VALUES(expires_at)',
                [$key, self::CACHE_PREFIX . $key, json_encode($result), $now, $expires]
            );
        } catch (\PDOException) {
            // A missing cache only costs another read.
        }
        return $result;
    }
}
