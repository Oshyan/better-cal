<?php

declare(strict_types=1);

namespace BetterCal\Domain;

use BetterCal\Dav\ChangeLog;
use BetterCal\Http\HttpError;
use BetterCal\Infra\Db;
use BetterCal\Infra\EmailSender;
use BetterCal\Support\Time;

/**
 * Answering invitations (#70). An event's invite block (events.invite_json)
 * comes from one of two places:
 *
 * - mail: an iMIP message or a confirmation read from the ingest mailbox
 *   (MailIngest). Only an iMIP REQUEST with an organizer is an invitation
 *   that can be answered, by emailing an iMIP REPLY; a reservation, ticket
 *   or PUBLISH is a booking, which has nothing to answer.
 * - google: an event on a connected Google calendar that lists the account
 *   as a guest (GoogleSync). The answer is set at Google, which passes it to
 *   the organizer itself.
 *
 * describe() tells the sheet which it is and, for an invitation, whether a
 * reply can go and why not; answer() records the answer and sends it,
 * saying exactly what happened.
 */
final class Rsvp
{
    /** SQLite is used only by the single-process domain tests. */
    private static array $testLocks = [];
    /** iMIP methods that make an invitation; anything else read from mail is a booking. */
    private const INVITATION_METHODS = ['REQUEST', 'CANCEL', 'ADD'];
    private const PARTSTATS = ['accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'tentative' => 'TENTATIVE'];
    /** Google's responseStatus words, both ways. */
    public const GOOGLE_STATUS = ['ACCEPTED' => 'accepted', 'DECLINED' => 'declined', 'TENTATIVE' => 'tentative', 'NEEDS-ACTION' => 'needsAction'];

    public function __construct(
        private readonly Db $db,
        private readonly EmailSender $mailer,
        private readonly array $cfg,
        private readonly ?GoogleWriter $google = null,
    ) {
    }

    // ---- What the sheet shows (pure, unit-tested) ------------------------------

    /** invitation | booking. Rows stored before 0.9.5 have no kind; their method says. */
    public static function kind(array $invite): string
    {
        $kind = $invite['kind'] ?? null;
        if ($kind === 'invitation' || $kind === 'booking') {
            return $kind;
        }
        return in_array(strtoupper((string) ($invite['method'] ?? '')), self::INVITATION_METHODS, true) ? 'invitation' : 'booking';
    }

    /** The address mail replies go out from: the RSVP profile when set, else the main one, as EmailSender picks. */
    public static function senderAddress(array $cfg): ?string
    {
        $rsvp = $cfg['rsvp_smtp'] ?? [];
        $smtp = ($rsvp['host'] ?? '') !== '' ? $rsvp : ($cfg['smtp'] ?? []);
        if (($smtp['host'] ?? '') === '') {
            return null;
        }
        $from = strtolower(trim((string) (($smtp['from'] ?? '') !== '' ? $smtp['from'] : ($smtp['user'] ?? ''))));
        return $from !== '' ? $from : null;
    }

    /**
     * Why a reply can't go, or null when it can. Possible but not certain is
     * fine: a configured route counts, and a failed send is reported after.
     *
     * @return array{code:string,text:string}|null
     */
    public static function blocker(array $invite, array $cfg): ?array
    {
        if (($invite['via'] ?? 'mail') === 'google') {
            return null; // set at Google; a failure is reported when it happens
        }
        $method = strtoupper((string) ($invite['method'] ?? ''));
        if ($method === 'CANCEL') {
            return ['code' => 'cancelled', 'text' => 'The organizer cancelled this, so there is nothing to answer.'];
        }
        if ($method !== 'REQUEST') {
            return ['code' => 'not_request', 'text' => 'This copy of the invitation asks for no reply.'];
        }
        $organizer = (string) ($invite['organizer']['email'] ?? '');
        if ($organizer === '') {
            return ['code' => 'no_organizer', 'text' => 'The invitation names no organizer to reply to.'];
        }
        $profiles = self::profiles($cfg);
        if ($profiles === []) {
            return ['code' => 'not_configured', 'text' => 'Replies need an email account to send from, and none is set up on this server (see docs/email-ingest.md, RSVP).'];
        }
        if (self::replyRoute($invite, $cfg) !== null) {
            return null;
        }
        $mine = array_values(array_unique(array_values($profiles)));
        if (in_array(strtolower($organizer), $mine, true)) {
            return ['code' => 'own_invitation', 'text' => 'This invitation was sent from ' . strtolower($organizer) . ', one of this server\'s own addresses, so there is nobody to answer.'];
        }
        return ['code' => 'address_mismatch', 'text' => 'Replies would come from ' . implode(' or ', $mine) . ', which isn\'t one of the invited addresses, so the organizer\'s calendar would ignore them.'];
    }

    /**
     * The sending accounts replies can use, as profile => address: the RSVP
     * one first when set, then the main one.
     *
     * @return array<string,string>
     */
    private static function profiles(array $cfg): array
    {
        $out = [];
        foreach (['rsvp' => $cfg['rsvp_smtp'] ?? [], 'smtp' => $cfg['smtp'] ?? []] as $name => $smtp) {
            if (($smtp['host'] ?? '') === '') {
                continue;
            }
            $from = strtolower(trim((string) (($smtp['from'] ?? '') !== '' ? $smtp['from'] : ($smtp['user'] ?? ''))));
            if ($from !== '') {
                $out[$name] = $from;
            }
        }
        return $out;
    }

    /**
     * Which account answers an invitation: the one whose address was
     * invited, never the organizer's own. The organizer is on its own guest
     * list, so a reply from that address would only say the organizer is
     * coming, and the invited guest would stay "awaiting" (seen when the
     * owner invited the Better-Cal mailbox from the address replies go out
     * from). Without a guest list, the first account that isn't the
     * organizer stands in.
     *
     * @return array{address:string,profile:string}|null
     */
    public static function replyRoute(array $invite, array $cfg): ?array
    {
        $organizer = strtolower(trim((string) ($invite['organizer']['email'] ?? '')));
        $invited = array_values(array_filter(array_map(
            static fn($a): string => strtolower(trim((string) ($a['email'] ?? ''))),
            (array) ($invite['attendees'] ?? [])
        )));
        foreach (self::profiles($cfg) as $profile => $address) {
            if ($address !== $organizer && ($invited === [] || in_array($address, $invited, true))) {
                return ['address' => $address, 'profile' => $profile];
            }
        }
        return null;
    }

    /** The invite block as the client gets it: kind, route, and whether a reply can go. */
    public static function describe(array $invite, array $cfg): array
    {
        $out = $invite;
        $out['kind'] = self::kind($invite);
        $out['via'] = ($invite['via'] ?? 'mail') === 'google' ? 'google' : 'mail';
        $block = $out['kind'] === 'invitation' ? self::blocker($invite, $cfg) : null;
        $out['canReply'] = $out['kind'] === 'invitation' && $block === null;
        $out['why'] = $block['text'] ?? null;
        $out['whyCode'] = $block['code'] ?? null;
        return $out;
    }

    /**
     * Google event resource → invite block, for an event that lists the
     * account as a guest someone else invited. Null when the account
     * organises it or isn't on the guest list: nothing to answer.
     */
    public static function fromGoogle(array $item): ?array
    {
        $self = null;
        $attendees = [];
        foreach ((array) ($item['attendees'] ?? []) as $a) {
            if (!is_array($a) || !empty($a['resource'])) {
                continue; // rooms aren't people
            }
            $status = array_search((string) ($a['responseStatus'] ?? 'needsAction'), self::GOOGLE_STATUS, true);
            $row = [
                'email' => strtolower((string) ($a['email'] ?? '')),
                'name' => isset($a['displayName']) ? mb_substr((string) $a['displayName'], 0, 200) : null,
                'partstat' => $status !== false ? $status : 'NEEDS-ACTION',
            ];
            if (!empty($a['self'])) {
                $self = $row;
            }
            if (count($attendees) < 100) {
                $attendees[] = $row;
            }
        }
        if ($self === null || !empty($item['organizer']['self'])) {
            return null;
        }
        return [
            'via' => 'google',
            'kind' => 'invitation',
            'method' => 'REQUEST',
            'organizer' => [
                'email' => strtolower((string) ($item['organizer']['email'] ?? '')),
                'name' => isset($item['organizer']['displayName']) ? mb_substr((string) $item['organizer']['displayName'], 0, 200) : null,
            ],
            'attendees' => $attendees,
            'myPartstat' => $self['partstat'],
        ];
    }

    // ---- Answering ---------------------------------------------------------------

    /**
     * Record the answer and send it where the invitation came from.
     *
     * Mail: the answer is kept here whatever happens, and the outcome of the
     * send is stored with it (invite.reply) so the sheet can say whether it
     * went and offer to try again. Google: the answer lives at Google, so a
     * failed write changes nothing here and is reported as an error.
     *
     * @return array{myPartstat:string,sent:bool,reason:?string,message:?string}
     */
    public function answer(int $userId, int $eventId, string $answer, ?string $sessionToken = null, bool $retryUncertain = false): array
    {
        $mysql = $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $database = $mysql ? (string) $this->db->scalar('SELECT DATABASE()') : (string) spl_object_id($this->db);
        $key = 'bc-rsvp-' . substr(hash('sha256', "$database:$userId:$eventId"), 0, 48);
        $locked = $mysql ? (int) $this->db->scalar('SELECT GET_LOCK(?, 0)', [$key]) === 1 : !isset(self::$testLocks[$key]);
        if (!$locked) throw HttpError::conflict('rsvp_busy', 'A reply is already being sent for this event. Reload to see its result.');
        self::$testLocks[$key] = true;
        try {
            return $this->answerLocked($userId, $eventId, $answer, $sessionToken, $retryUncertain);
        } finally {
            unset(self::$testLocks[$key]);
            if ($mysql) $this->db->scalar('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }

    private function answerLocked(int $userId, int $eventId, string $answer, ?string $sessionToken, bool $retryUncertain): array
    {
        $authority = RequestAuthority::credential($userId, null, $sessionToken);
        $authority->assertValid($this->db, false);
        $partstat = self::PARTSTATS[strtolower($answer)] ?? null;
        if ($partstat === null) {
            throw HttpError::badRequest('answer must be accepted, declined or tentative');
        }
        $row = $this->db->one(
            'SELECT * FROM events WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$eventId, $userId]
        );
        $invite = $row !== null && $row['invite_json'] !== null
            ? (is_array($row['invite_json']) ? $row['invite_json'] : json_decode((string) $row['invite_json'], true))
            : null;
        if (!is_array($invite)) {
            throw HttpError::notFound('No invitation on this event');
        }
        if (self::kind($invite) !== 'invitation') {
            throw HttpError::badRequest('This is a booking, not an invitation; there is nobody to answer');
        }
        // The answer already given (and, by mail, already sent) is not sent
        // again: pressing Accept twice mailed the organizer twice. A reply
        // that failed can still be retried.
        $via = ($invite['via'] ?? 'mail') === 'google' ? 'google' : 'mail';
        if (($invite['myPartstat'] ?? null) === $partstat
            && ($via === 'google' || (($invite['reply']['partstat'] ?? null) === $partstat && !empty($invite['reply']['sent'])))) {
            return ['myPartstat' => $partstat, 'sent' => true, 'reason' => null, 'message' => null, 'unchanged' => true];
        }
        $outcome = $via === 'google'
            ? $this->answerAtGoogle($row, $invite, $partstat, RequestAuthority::credential($userId, null, $sessionToken))
            : $this->answerByMail($row, $invite, $partstat, $authority, $retryUncertain);

        $verb = ['ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'TENTATIVE' => 'Maybe'][$partstat];
        ActivityContext::with('rsvp', fn() => (new Undo($this->db))->record(
            $userId,
            'event',
            $eventId,
            'update',
            null,
            null,
            $verb . " to '" . (string) $row['title'] . "'" . ($outcome['sent'] ? '' : ' (not sent: ' . ($outcome['message'] ?? 'unknown') . ')')
        ));
        // Journal so other open clients see the answer on their next cursor poll.
        ChangeLog::record($this->db, (int) $row['calendar_id'], (string) $row['uid'], ChangeLog::OP_MODIFY);
        return ['myPartstat' => $partstat] + $outcome;
    }

    /** @return array{sent:bool,reason:?string,message:?string} */
    private function answerByMail(array $row, array $invite, string $partstat, RequestAuthority $authority, bool $retryUncertain): array
    {
        if (in_array($invite['reply']['reason'] ?? null, ['sending', 'uncertain'], true) && !$retryUncertain) {
            throw HttpError::conflict('rsvp_uncertain', 'The previous reply may have been sent. Open this event and use Retry to confirm that another reply may be sent.');
        }
        $block = self::blocker($invite, $this->cfg);
        $outcome = ['sent' => false, 'reason' => $block['code'] ?? null, 'message' => $block['text'] ?? null];
        $attempt = bin2hex(random_bytes(16));
        $this->db->tx(function () use ($row, &$invite, $partstat, $authority, $attempt, $block, &$outcome): void {
            $authority->assertValid($this->db);
            $current = $this->db->one('SELECT invite_json FROM events WHERE id = ? AND deleted_at IS NULL' . $this->db->forUpdate(), [(int) $row['id']]);
            if ($current === null || json_decode((string) $current['invite_json'], true) !== $invite) {
                throw HttpError::conflict('invitation_changed', 'This invitation changed. Reload before replying.');
            }
            if ($block === null) {
                $admission = (new ExternalActionAdmission($this->db))->admitEmail((int) $row['user_id'], (string) $invite['organizer']['email'], null);
                $outcome = $admission['admitted']
                    ? ['sent' => false, 'reason' => 'sending', 'message' => 'The reply may have been sent. Retrying can send another copy.']
                    : ['sent' => false, 'reason' => 'email_limit', 'message' => 'The outbound email safety limit was reached or could not be checked. Your answer is saved here; no reply was sent.'];
            }
            $invite['myPartstat'] = $partstat;
            $invite['organizerTrust'] = 'owner';
            $invite['reply'] = ['attemptId' => $attempt, 'partstat' => $partstat, 'at' => Time::iso(Time::nowUtc())] + $outcome;
            $this->db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($invite), (int) $row['id']]);
            $this->db->run("UPDATE events SET status = ? WHERE id = ? AND status <> 'cancelled'", [$partstat === 'ACCEPTED' ? 'confirmed' : 'tentative', (int) $row['id']]);
        });
        if ($outcome['reason'] === 'sending') {
            $organizer = (string) $invite['organizer']['email'];
            $route = (array) self::replyRoute($invite, $this->cfg);
            $me = (string) $route['address'];
            $ics = MailIngest::buildReplyIcs(
                (string) $row['uid'],
                $organizer,
                $me,
                $partstat,
                (int) ($invite['sequence'] ?? 0),
                (string) $row['title'],
                Time::nowUtc(),
                ['start' => Time::fromDb((string) $row['start_utc']), 'end' => Time::fromDb((string) $row['end_utc']), 'allDay' => (int) $row['all_day'] === 1]
            );
            $verb = ['ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'TENTATIVE' => 'Tentative'][$partstat];
            $authority->assertValid($this->db, false);
            $error = $this->mailer->sendImipReply($organizer, $verb . ': ' . (string) $row['title'], $ics, $verb . ': ' . (string) $row['title'], (string) $route['profile']);
            $outcome = $error === null
                ? ['sent' => true, 'reason' => null, 'message' => null]
                : ['sent' => false, 'reason' => 'uncertain', 'message' => 'The reply could not be confirmed. It may have been sent; retrying can send another copy.'];
        }
        $this->db->tx(function () use ($row, $attempt, $outcome, $authority): void {
            $authority->assertValid($this->db);
            $fresh = $this->db->scalar('SELECT invite_json FROM events WHERE id = ? AND deleted_at IS NULL' . $this->db->forUpdate(), [(int) $row['id']]);
            $current = json_decode((string) $fresh, true);
            if (($current['reply']['attemptId'] ?? null) === $attempt) {
                $current['reply'] = array_replace($current['reply'], $outcome);
                $this->db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($current), (int) $row['id']]);
            }
        });
        return $outcome;
    }

    /** @return array{sent:bool,reason:?string,message:?string} */
    private function answerAtGoogle(array $row, array $invite, string $partstat, RequestAuthority $authority): array
    {
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $row['calendar_id']]);
        if ($this->google === null || $calendar === null || ($calendar['provider'] ?? '') !== 'google' || $calendar['google_account_id'] === null) {
            throw new HttpError('google_disconnected', 'This invitation came from a Google calendar that is no longer connected; answer it in Google Calendar.', 409);
        }
        $googleId = (string) ($row['google_event_id'] ?? '');
        if ($googleId === '') {
            throw new HttpError('google_write_failed', 'Better-Cal doesn\'t know this event\'s Google id yet; try again after the next sync.', 409);
        }
        $account = $this->db->one('SELECT * FROM google_accounts WHERE id = ?', [(int) $calendar['google_account_id']]);
        if ($account === null) throw HttpError::conflict('google_disconnected', 'Reconnect this Google account in Settings, Connections.');
        $check = function (bool $lock) use ($row, $calendar, $account, $authority): void {
            $authority->assertValid($this->db, $lock);
            $fresh = $this->db->one('SELECT * FROM calendars WHERE id = ?' . ($lock ? $this->db->forUpdate() : ''), [(int) $calendar['id']]);
            if (!GoogleSync::sameBinding($calendar, $fresh)) {
                throw HttpError::conflict('google_binding_changed', 'This calendar changed while the reply was running. Reload before replying again.');
            }
            $current = $this->db->one('SELECT * FROM google_accounts WHERE id = ?' . ($lock ? $this->db->forUpdate() : ''), [(int) $account['id']]);
            if ($current === null || ($current['reauth_required_at'] ?? null) !== null) {
                throw HttpError::conflict('google_reconnect_needed', 'Reconnect needed. Open Settings, Connections.');
            }
            GoogleAuth::assertSameCredential($account, $current);
            $event = $this->db->one('SELECT calendar_id, google_event_id FROM events WHERE id = ? AND deleted_at IS NULL' . ($lock ? $this->db->forUpdate() : ''), [(int) $row['id']]);
            if ($event === null || (int) $event['calendar_id'] !== (int) $calendar['id'] || $event['google_event_id'] !== $row['google_event_id']) {
                throw HttpError::conflict('google_binding_changed', 'This event changed while the reply was running. Reload before replying again.');
            }
        };
        $check(false);
        // Google replaces the whole guest list on a write, so send back the
        // list it has now (not our copy) with only our own answer changed.
        $current = $this->google->get($calendar, $googleId);
        $attendees = (array) ($current['attendees'] ?? []);
        $found = false;
        foreach ($attendees as $i => $a) {
            if (is_array($a) && !empty($a['self'])) {
                $attendees[$i]['responseStatus'] = self::GOOGLE_STATUS[$partstat];
                $found = true;
            }
        }
        if (!$found) {
            throw new HttpError('google_write_failed', 'Google no longer lists this account as a guest of the event.', 409);
        }
        // sendUpdates=none (GoogleWriter's default): Google passes a guest's
        // answer to the organizer by itself; nobody else gets mail.
        $resp = $this->google->patch($calendar, $googleId, ['attendees' => array_values($attendees)], fn() => $check(false));
        $this->db->tx(function () use ($check, $calendar, $resp, $row): void {
            $check(true);
            $this->google->apply($calendar, [$resp]);
            // The answer decides Planned or Maybe again (Events::relationship),
            // over any choice made by hand since the last answer.
            $this->db->run("UPDATE events SET attendance = 'none' WHERE id = ? AND attendance IN ('going', 'interested')", [(int) $row['id']]);
        });
        return ['sent' => true, 'reason' => null, 'message' => null];
    }
}
