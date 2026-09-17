<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Proposals;
use BetterCal\Domain\ReviewQueue;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

/**
 * The Review queue: everything waiting on the owner's decision, in one list.
 *
 * Three kinds, one shape. Each item says what it is, what it would do, and
 * carries its own `actions` ({name, label, method, path, body?}), so a client
 * (or an agent over the API) can act on any item without knowing the kinds:
 *
 *   invite_change  an emailed change to an invitation already on the calendar,
 *                  held instead of applied (ReviewQueue; BC-07)
 *   rsvp           an invitation not answered yet (events.invite_json)
 *   proposal       a plan a plugin suggests (plugin_proposals)
 *
 * Only invite_change is stored by the queue itself; the other two live where
 * they always did and keep their own endpoints, which the actions point at.
 */
final class ReviewController
{
    public function __construct(private readonly ReviewQueue $queue, private readonly Proposals $proposals)
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
            $items[] = [
                'key' => 'invite_change:' . $c['id'],
                'kind' => 'invite_change',
                'status' => $c['status'],
                'title' => $c['title'],
                'summary' => $c['summary'],
                'createdAt' => $c['createdAt'],
                'eventId' => $c['eventId'],
                'detail' => ['method' => $c['method'], 'from' => $c['from'], 'organizer' => $c['organizer'], 'diff' => $c['diff'], 'decidedAt' => $c['decidedAt']],
                'actions' => $open ? [
                    self::action('accept', $c['method'] === 'CANCEL' ? 'Accept cancellation' : 'Accept change', "/review/invite-changes/{$c['id']}/accept"),
                    self::action('dismiss', 'Dismiss', "/review/invite-changes/{$c['id']}/dismiss"),
                ] : [],
            ];
        }
        foreach ($this->queue->invitationsAwaitingReply($userId) as $inv) {
            $who = (string) (($inv['organizer']['name'] ?? '') ?: ($inv['organizer']['email'] ?? ''));
            $items[] = [
                'key' => 'rsvp:' . $inv['eventId'],
                'kind' => 'rsvp',
                'status' => 'open',
                'title' => $inv['title'],
                'summary' => $who !== '' ? "Invitation from $who. You have not replied." : 'You have not replied to this invitation.',
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
        foreach ($this->proposals->listFor($userId, $openOnly ? 'open' : 'all') as $p) {
            $n = count($p['plan']['events'] ?? []);
            $actions = [];
            if ($p['status'] === 'open') {
                $actions = [
                    self::action('accept', 'Add ' . $n . ' event' . ($n === 1 ? '' : 's') . (!empty($p['plan']['trip']) ? ' as a trip' : ''), "/proposals/{$p['id']}/accept"),
                    self::action('dismiss', 'Dismiss', "/proposals/{$p['id']}/reject"),
                ];
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
            'invite_change' => $this->queue->openCount($userId),
            'rsvp' => count($this->queue->invitationsAwaitingReply($userId)),
            'proposal' => count($this->proposals->listFor($userId, 'open')),
        ];
        return Response::json(['count' => array_sum($byKind), 'byKind' => $byKind]);
    }

    public function acceptInviteChange(Request $req, array $params): Response
    {
        return Response::json($this->queue->accept((int) $req->user['id'], (int) $params['id']));
    }

    public function dismissInviteChange(Request $req, array $params): Response
    {
        return Response::json(['item' => $this->queue->dismiss((int) $req->user['id'], (int) $params['id'])]);
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
