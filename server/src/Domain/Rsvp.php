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
        $me = self::senderAddress($cfg);
        if ($me === null) {
            return ['code' => 'not_configured', 'text' => 'Replies need an email account to send from, and none is set up on this server (see docs/email-ingest.md, RSVP).'];
        }
        $invited = array_values(array_filter(array_map(
            static fn($a): string => strtolower((string) ($a['email'] ?? '')),
            (array) ($invite['attendees'] ?? [])
        )));
        if ($invited !== [] && !in_array($me, $invited, true)) {
            return ['code' => 'address_mismatch', 'text' => 'Replies would come from ' . $me . ', which isn\'t one of the invited addresses, so the organizer\'s calendar would ignore them.'];
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
    public function answer(int $userId, int $eventId, string $answer): array
    {
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
        $outcome = ($invite['via'] ?? 'mail') === 'google'
            ? $this->answerAtGoogle($row, $invite, $partstat)
            : $this->answerByMail($row, $invite, $partstat);

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
    private function answerByMail(array $row, array $invite, string $partstat): array
    {
        $block = self::blocker($invite, $this->cfg);
        $outcome = ['sent' => false, 'reason' => $block['code'] ?? null, 'message' => $block['text'] ?? null];
        if ($block === null) {
            $organizer = (string) $invite['organizer']['email'];
            $me = (string) self::senderAddress($this->cfg);
            $ics = MailIngest::buildReplyIcs(
                (string) $row['uid'],
                $organizer,
                $me,
                $partstat,
                (int) ($invite['sequence'] ?? 0),
                (string) $row['title'],
                Time::nowUtc()
            );
            $verb = ['ACCEPTED' => 'Accepted', 'DECLINED' => 'Declined', 'TENTATIVE' => 'Tentative'][$partstat];
            $error = $this->mailer->sendImipReply($organizer, $verb . ': ' . (string) $row['title'], $ics, $verb . ': ' . (string) $row['title']);
            $outcome = $error === null
                ? ['sent' => true, 'reason' => null, 'message' => null]
                : ['sent' => false, 'reason' => 'send_failed', 'message' => $error];
        }
        $invite['myPartstat'] = $partstat;
        $invite['reply'] = ['partstat' => $partstat, 'sent' => $outcome['sent'], 'reason' => $outcome['reason'], 'message' => $outcome['message'], 'at' => Time::iso(Time::nowUtc())];
        $this->db->run('UPDATE events SET invite_json = ? WHERE id = ?', [json_encode($invite), (int) $row['id']]);
        return $outcome;
    }

    /** @return array{sent:bool,reason:?string,message:?string} */
    private function answerAtGoogle(array $row, array $invite, string $partstat): array
    {
        $calendar = $this->db->one('SELECT * FROM calendars WHERE id = ?', [(int) $row['calendar_id']]);
        if ($this->google === null || $calendar === null || ($calendar['provider'] ?? '') !== 'google' || $calendar['google_account_id'] === null) {
            throw new HttpError('google_disconnected', 'This invitation came from a Google calendar that is no longer connected; answer it in Google Calendar.', 409);
        }
        $googleId = (string) ($row['google_event_id'] ?? '');
        if ($googleId === '') {
            throw new HttpError('google_write_failed', 'Better-Cal doesn\'t know this event\'s Google id yet; try again after the next sync.', 409);
        }
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
        $resp = $this->google->patch($calendar, $googleId, ['attendees' => array_values($attendees)]);
        $this->google->apply($calendar, [$resp]);
        return ['sent' => true, 'reason' => null, 'message' => null];
    }
}
