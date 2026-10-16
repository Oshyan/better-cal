<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Export;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

/**
 * GET /export: every calendar, a zip of .ics files (Domain\Export).
 * GET /calendars/:id/export: one calendar's .ics file.
 * Session only: an export holds everything, so an API token can't take it.
 */
final class ExportController
{
    public function __construct(private readonly Export $export)
    {
    }

    public function all(Request $req): Response
    {
        $req->requireSession('Exporting your calendars');
        @set_time_limit(120);
        $path = sys_get_temp_dir() . '/bc-export-' . bin2hex(random_bytes(16)) . '.zip';
        $this->export->zip((int) $req->user['id'], $path);
        // The temporary file goes once it has been sent.
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });
        return Response::file($path, 'application/zip', [
            'Content-Disposition' => self::attachment('better-cal-export-' . date('Y-m-d') . '.zip'),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function calendar(Request $req, array $params): Response
    {
        $req->requireSession('Exporting a calendar');
        $file = $this->export->calendar((int) $req->user['id'], (int) $params['id']);
        return Response::text($file['ics'], 'text/calendar; charset=utf-8', 200, [
            'Content-Disposition' => self::attachment($file['fileName']),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** A download header for any file name: an ASCII fallback, and the real name (RFC 6266). */
    public static function attachment(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $name);
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
