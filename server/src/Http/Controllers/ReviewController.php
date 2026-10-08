<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Calendars;
use BetterCal\Domain\Duplicates;
use BetterCal\Domain\Proposals;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Support\Time;

/**
 * The Review queue: everything waiting on the owner's decision, in one list.
 *
 * Eight kinds, one shape. Each item says what it is, what it would do, and
 * carries its own `actions` ({name, label, method, path, body?}), so a client
 * can act without hard-coding each kind's endpoints. A subscription claim is
 * intentionally session-only and therefore omitted for bearer-token callers:
 *
 *   invite_new     a first-time emailed invitation, held before event creation
 *   invite_change  an emailed change to an invitation already on the calendar,
 *                  held instead of applied (ReviewQueue; BC-07)
 *   mail_limit     a bounded notice that public-mail automation hit a capacity
 *   model_limit    a bounded prompt-filter capacity notice for one calendar
 *   rsvp           an invitation not answered yet (events.invite_json)
 *   proposal       a plan a plugin suggests (plugin_proposals)
 *   duplicate      two events that may be one, arriving by two routes (#9)
 *   subscription   an ICS feed whose updates need an owner decision
 *
 * invite_new, invite_change, mail_limit and model_limit are stored by the queue itself;
 * the other four live where they always did and keep their own endpoints,
 * which the actions point at.
 */
final class ReviewController
{
    public function __construct(
        private readonly ReviewQueue $queue,
        private readonly Proposals $proposals,
        private readonly Calendars $calendars,
        private readonly ?Duplicates $duplicates = null,
    )
    {
    }

    /** GET /review?status=open|all */
    public function index(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $openOnly = (string) ($req->query['status'] ?? 'open') !== 'all';
        $this->queue->closeOrphans($userId);

        $items = [];
        foreach ($this->queue->listFor($userId, $openOnly) as $c) {
            $open = $c['status'] === 'open';
            if ($c['kind'] === ReviewQueue::KIND_MAIL_LIMIT || $c['kind'] === ReviewQueue::KIND_MODEL_LIMIT) {
                $isModel = $c['kind'] === ReviewQueue::KIND_MODEL_LIMIT;
                $items[] = [
                    'key' => $c['kind'] . ':' . $c['id'],
                    'kind' => $c['kind'],
                    'status' => $c['status'],
                    'title' => $c['title'],
                    'summary' => $c['summary'],
                    'createdAt' => $c['createdAt'],
                    'eventId' => null,
                    'detail' => $c['detail'],
                    'actions' => $open ? [
                        self::action('dismiss', 'Dismiss', $isModel
                            ? "/review/model-limits/{$c['id']}/dismiss"
                            : "/review/mail-limits/{$c['id']}/dismiss"),
                    ] : [],
                ];
                continue;
            }
            $isNew = $c['kind'] === ReviewQueue::KIND_INVITE_NEW;
            $fields = is_array($c['detail']['fields'] ?? null) ? $c['detail']['fields'] : [];
            $items[] = [
                'key' => $c['kind'] . ':' . $c['id'],
                'kind' => $c['kind'],
                'status' => $c['status'],
                'title' => $c['title'],
                'summary' => $c['summary'],
                'createdAt' => $c['createdAt'],
                'eventId' => $c['eventId'],
                'detail' => [
                    'method' => $c['method'],
                    'from' => $c['from'],
                    'organizer' => $c['organizer'],
                    'sequence' => (int) ($c['detail']['sequence'] ?? $c['detail']['invite']['sequence'] ?? 0),
                    'diff' => $c['diff'],
                    'start' => $fields['start'] ?? null,
                    'end' => $fields['end'] ?? null,
                    'allDay' => (bool) ($fields['allDay'] ?? false),
                    'recurring' => !empty($fields['rrule']),
                    'location' => $fields['location'] ?? null,
                    'decidedAt' => $c['decidedAt'],
                ],
                'actions' => $open ? [
                    self::action('accept', $isNew ? 'Add to calendar' : ($c['method'] === 'CANCEL' ? 'Accept cancellation' : 'Accept change'), $isNew
                        ? "/review/invitations/{$c['id']}/accept"
                        : "/review/invite-changes/{$c['id']}/accept"),
                    self::action('dismiss', 'Dismiss', $isNew
                        ? "/review/invitations/{$c['id']}/dismiss"
                        : "/review/invite-changes/{$c['id']}/dismiss"),
                ] : [],
            ];
        }
        // An invitation can be waiting for a reply AND have a change held: two
        // decisions, two items. The reply card says so, because until the change
        // is decided it shows the time and place as they stand on the calendar.
        $changing = [];
        foreach ($items as $held) {
            if ($held['status'] === 'open' && $held['eventId'] !== null) {
                $changing[(int) $held['eventId']] = true;
            }
        }
        foreach ($this->queue->invitationsAwaitingReply($userId) as $inv) {
            $who = (string) (($inv['organizer']['name'] ?? '') ?: ($inv['organizer']['email'] ?? ''));
            $items[] = [
                'key' => 'rsvp:' . $inv['eventId'],
                'kind' => 'rsvp',
                'status' => 'open',
                'title' => $inv['title'],
                'summary' => ($who !== '' ? "Invitation from $who. You have not replied." : 'You have not replied to this invitation.')
                    . (isset($changing[(int) $inv['eventId']]) ? ' The organizer has since sent a change, listed separately; this shows the details as they stand.' : ''),
                'createdAt' => $inv['createdAt'],
                'eventId' => $inv['eventId'],
                'detail' => $inv,
                'actions' => [
                    self::action('accepted', 'Accept', "/events/{$inv['eventId']}/rsvp", ['answer' => 'accepted']),
                    self::action('tentative', 'Maybe', "/events/{$inv['eventId']}/rsvp", ['answer' => 'tentative']),
                    self::action('declined', 'Decline', "/events/{$inv['eventId']}/rsvp", ['answer' => 'declined']),
                ],
            ];
        }
        foreach ($this->calendars->subscriptionsAwaitingReview($userId) as $calendar) {
            $authorization = $calendar['subscriptionAuthorization'];
            $items[] = [
                'key' => 'subscription:' . $calendar['id'],
                'kind' => 'subscription',
                'status' => 'open',
                'title' => $calendar['name'],
                'summary' => $authorization['reason'],
                'createdAt' => null,
                'eventId' => null,
                'detail' => [
                    'calendarId' => $calendar['id'],
                    'origin' => $authorization['origin'],
                ],
                'actions' => $req->authMethod === 'session' ? [
                    self::action('keep_updating', 'Keep updating', "/calendars/{$calendar['id']}/claim-subscription"),
                ] : [],
            ];
        }
        foreach ($this->proposals->listFor($userId, $openOnly ? 'open' : 'all') as $p) {
            $n = count($p['plan']['events'] ?? []);
            $actions = [];
            if ($p['status'] === 'open') {
                $actions = array_values(array_filter([
                    $p['acceptAllowed'] ? self::action(
                        'accept',
                        'Add ' . $n . ' event' . ($n === 1 ? '' : 's') . (!empty($p['plan']['trip']) ? ' as a trip' : ''),
                        "/proposals/{$p['id']}/accept",
                        ['reviewToken' => $p['reviewToken']]
                    ) : null,
                    self::action('dismiss', 'Dismiss', "/proposals/{$p['id']}/reject", ['reviewToken' => $p['reviewToken']]),
                ]));
            } elseif ($p['status'] === 'accepted') {
                $actions = [self::action('undo', 'Undo, remove these again', "/proposals/{$p['id']}/undo")];
            }
            $items[] = [
                'key' => 'proposal:' . $p['id'],
                'kind' => 'proposal',
                'status' => $p['status'] === 'rejected' ? 'dismissed' : $p['status'],
                'title' => $p['title'],
                'summary' => $p['summary'] ?? null,
                'createdAt' => $p['createdAt'] ?? null,
                'eventId' => null,
                'detail' => $p,
                'actions' => $actions,
            ];
        }

        foreach ($this->duplicates?->possible($userId) ?? [] as $p) {
            $copy = static fn(string $s): array => [
                'eventId' => (int) $p[$s . '_id'],
                'title' => (string) $p[$s . '_title'],
                'calendar' => (string) $p[$s . '_cal'],
                'allDay' => (int) $p[$s . '_all_day'] === 1,
                'start' => (int) $p[$s . '_all_day'] === 1
                    ? substr((string) $p[$s . '_start'], 0, 10)
                    : Time::dbToIso((string) $p[$s . '_start'], (string) $p[$s . '_tzid']),
            ];
            $a = $copy('a');
            $b = $copy('b');
            $where = $a['calendar'] === $b['calendar'] ? "twice on {$a['calendar']}" : "on {$a['calendar']} and {$b['calendar']}";
            $items[] = [
                'key' => 'duplicate:' . $p['id'],
                'kind' => 'duplicate',
                'status' => 'open',
                'title' => $a['title'],
                'summary' => "Possibly the same event, $where" . ($a['title'] !== $b['title'] ? " (\"{$a['title']}\" and \"{$b['title']}\")" : '') . '. The same event shows once.',
                'createdAt' => Time::dbToIso((string) $p['created_at'], 'UTC'),
                'eventId' => $a['eventId'],
                'detail' => ['pairId' => (int) $p['id'], 'a' => $a, 'b' => $b],
                'actions' => [
                    self::action('linked', 'Same event', "/duplicates/{$p['id']}", ['status' => 'linked']),
                    self::action('dismissed', 'Not the same', "/duplicates/{$p['id']}", ['status' => 'dismissed']),
                ],
            ];
        }

        // Every item about an event says how to open it.
        foreach ($items as &$item) {
            $item['link'] = $item['eventId'] !== null ? $this->queue->eventLink($userId, (int) $item['eventId']) : null;
        }
        unset($item);

        // Open first, then newest first: what needs a decision is never below
        // what has already had one.
        usort($items, static fn(array $a, array $b): int => [$b['status'] === 'open', (string) $b['createdAt']] <=> [$a['status'] === 'open', (string) $a['createdAt']]);
        return Response::json(['count' => self::countOpen($items), 'items' => $items]);
    }

    /** GET /review/count: the sidebar badge, without the payloads. */
    public function count(Request $req): Response
    {
        $userId = (int) $req->user['id'];
        $byKind = [
            'invite_new' => $this->queue->openCount($userId, ReviewQueue::KIND_INVITE_NEW),
            'invite_change' => $this->queue->openCount($userId, ReviewQueue::KIND_INVITE_CHANGE),
            'mail_limit' => $this->queue->openCount($userId, ReviewQueue::KIND_MAIL_LIMIT),
            'model_limit' => $this->queue->openCount($userId, ReviewQueue::KIND_MODEL_LIMIT),
            'rsvp' => count($this->queue->invitationsAwaitingReply($userId)),
            'proposal' => count($this->proposals->listFor($userId, 'open')),
            'duplicate' => count($this->duplicates?->possible($userId) ?? []),
            'subscription' => count($this->calendars->subscriptionsAwaitingReview($userId)),
        ];
        return Response::json(['count' => array_sum($byKind), 'byKind' => $byKind]);
    }

    /** POST /duplicates/:id {status: linked|dismissed}: the owner's word on a pair. */
    public function decideDuplicate(Request $req, array $params): Response
    {
        if ($this->duplicates === null) {
            return Response::json(['ok' => false], 404);
        }
        $this->duplicates->decide((int) $req->user['id'], (int) $params['id'], (string) ($req->str('status') ?? ''));
        return Response::json(['ok' => true]);
    }

    public function acceptInviteChange(Request $req, array $params): Response
    {
        return Response::json($this->queue->accept((int) $req->user['id'], (int) $params['id']));
    }

    public function dismissInviteChange(Request $req, array $params): Response
    {
        return Response::json(['item' => $this->queue->dismiss((int) $req->user['id'], (int) $params['id'])]);
    }

    public function acceptInvitation(Request $req, array $params): Response
    {
        return Response::json($this->queue->accept((int) $req->user['id'], (int) $params['id']));
    }

    public function dismissInvitation(Request $req, array $params): Response
    {
        return Response::json(['item' => $this->queue->dismiss((int) $req->user['id'], (int) $params['id'])]);
    }

    public function dismissMailLimit(Request $req, array $params): Response
    {
        return Response::json(['item' => $this->queue->dismissMailLimit((int) $req->user['id'], (int) $params['id'])]);
    }

    public function dismissModelLimit(Request $req, array $params): Response
    {
        return Response::json(['item' => $this->queue->dismissModelLimit((int) $req->user['id'], (int) $params['id'])]);
    }

    /** @return array{name:string,label:string,method:string,path:string,body?:array<string,mixed>} */
    private static function action(string $name, string $label, string $path, ?array $body = null): array
    {
        return ['name' => $name, 'label' => $label, 'method' => 'POST', 'path' => $path] + ($body !== null ? ['body' => $body] : []);
    }

    /** @param list<array<string,mixed>> $items */
    private static function countOpen(array $items): int
    {
        return count(array_filter($items, static fn(array $i): bool => $i['status'] === 'open'));
    }
}
