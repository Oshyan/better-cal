<?php

declare(strict_types=1);

namespace BetterCal\Dav;

use BetterCal\Support\Limits;

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
}
