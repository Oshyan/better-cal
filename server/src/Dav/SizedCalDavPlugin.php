<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Support\Limits;
use BetterCal\Domain\Ics;

/**
 * sabre/dav's CalDAV plugin, advertising OUR object size limit.
 *
 * The stock plugin answers CALDAV:max-resource-size itself, from a protected
 * default of 10,000,000 bytes, before a backend's calendar properties are
 * consulted; it advertises the number and enforces nothing. CalendarBackend
 * enforces Limits::DAV_OBJECT_BYTES on every PUT, so that is the number clients
 * should be told (RFC 4791 5.2.5), instead of finding out by 403.
 */
final class SizedCalDavPlugin extends \Sabre\CalDAV\Plugin
{
    public function __construct()
    {
        $this->maxResourceSize = Limits::get('DAV_OBJECT_BYTES');
    }

    /** Bound the transport before Sabre materializes or parses its body. */
    public static function readBody(mixed $data, ?string $length = null): string
    {
        $limit = Limits::get('DAV_OBJECT_BYTES');
        if ($length !== null && (!ctype_digit($length) || (float) $length > $limit)) {
            throw new \Sabre\DAV\Exception\Forbidden('Calendar object exceeds CALDAV:max-resource-size; shorten its description or attachments.');
        }
        if (is_resource($data)) $data = stream_get_contents($data, $limit + 1);
        if (!is_string($data) || strlen($data) > $limit) {
            throw new \Sabre\DAV\Exception\Forbidden('Calendar object exceeds CALDAV:max-resource-size; shorten its description or attachments.');
        }
        return $data;
    }

    /** jCal is decoded only after the byte bound, before constructing VObject properties. */
    public static function checkJcal(array $component, int &$events, int &$properties, int &$exdates): void
    {
        if (strtolower((string) ($component[0] ?? '')) === 'vevent' && ++$events > Limits::get('DAV_OVERRIDES') + 1) {
            throw new \InvalidArgumentException('Too many event overrides');
        }
        $eventExdates = 0;
        foreach (($component[1] ?? []) as $property) {
            if (++$properties > (Limits::get('DAV_OVERRIDES') + 1) * Ics::LINES_PER_EVENT) throw new \InvalidArgumentException('Too many calendar properties');
            $name = strtoupper((string) ($property[0] ?? ''));
            $values = array_slice($property, 3);
            if (Ics::singletonDate($name) && (count($values) !== 1 || !is_string($values[0]) || str_contains($values[0], ','))) {
                throw new \InvalidArgumentException($name . ' must contain one date');
            }
            if ($name === 'EXDATE') {
                foreach ($values as $value) $eventExdates += is_string($value) ? substr_count($value, ',') + 1 : 1;
            }
        }
        $exdates += $eventExdates;
        if ($eventExdates > Limits::get('EXDATE_VALUES_PER_EVENT') || $exdates > Limits::get('EXDATE_VALUES_PER_INPUT')) throw new \InvalidArgumentException('Too many skipped occurrences');
        foreach (($component[2] ?? []) as $child) self::checkJcal($child, $events, $properties, $exdates);
    }

    /**
     * Sabre's own validation reads the object as sent, so the escaping
     * admitEvents() gives free text doesn't apply there: every bare comma,
     * descriptions included, is a value it will make.
     */
    public static function commaProblem(string $data): ?string
    {
        $max = Limits::get('ICS_LIST_VALUES_PER_INPUT');
        return substr_count($data, ',') > $max ? 'The calendar object holds more than ' . number_format($max) . ' comma-separated values' : null;
    }

    protected function validateICalendar(&$data, $path, &$modified, \Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response, $isNew)
    {
        $data = self::readBody($data, $request->getHeader('Content-Length'));
        try {
            if (str_starts_with(ltrim($data), '[')) {
                $json = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
                $events = $properties = $exdates = 0;
                self::checkJcal($json, $events, $properties, $exdates);
                $data = ltrim($data);
            } else {
                $problem = Ics::budgetProblem($data, Limits::get('DAV_OBJECT_BYTES'), Limits::get('DAV_OVERRIDES') + 1);
                if ($problem !== null) throw new \InvalidArgumentException($problem);
                $problem = self::commaProblem($data);
                if ($problem !== null) throw new \InvalidArgumentException($problem);
                $admitted = Ics::admitEvents($data);
                if ($admitted['skipped'] !== []) throw new \InvalidArgumentException(Ics::skippedMessage($admitted['skipped']));
            }
        } catch (\Throwable $e) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar object was not saved: ' . $e->getMessage());
        }
        parent::validateICalendar($data, $path, $modified, $request, $response, $isNew);
    }
}
