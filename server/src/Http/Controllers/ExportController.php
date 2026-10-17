<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Export;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Http\HttpError;
use BetterCal\Infra\ExportWorkspace;

/**
 * GET /export: every calendar, a zip of .ics files (Domain\Export).
 * GET /calendars/:id/export: one calendar's .ics file.
 * Session only: an export holds everything, so an API token can't take it.
 */
final class ExportController
{
    public function __construct(private readonly Export $export, private readonly string $appRoot)
    {
    }

    public function all(Request $req): Response
    {
        $req->requireSession('Exporting your calendars');
        @set_time_limit(0); // Host-enforced limits may still apply; no app size/time cap.
        $workspace = null;
        try {
            $workspace = new ExportWorkspace($this->appRoot);
            $path = $workspace->file('zip');
            $mask = umask(0077);
            try {
                $this->export->snapshot(fn() => $this->export->zip((int) $req->user['id'], $path, $workspace));
            } finally {
                umask($mask);
            }
            return Response::stream(static function () use ($path, $workspace): void {
                try {
                    if (readfile($path) === false) {
                        throw new \RuntimeException('Sending the export failed.');
                    }
                } finally {
                    $workspace->close();
                }
            }, 'application/zip', [
                'Content-Disposition' => self::attachment('better-cal-export-' . date('Y-m-d') . '.zip'),
                'Content-Length' => (string) filesize($path),
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $e) {
            $workspace?->close();
            return self::failure($e);
        }
    }

    public function calendar(Request $req, array $params): Response
    {
        $req->requireSession('Exporting a calendar');
        $file = $this->export->calendar((int) $req->user['id'], (int) $params['id']);
        @set_time_limit(0);
        try {
            $workspace = new ExportWorkspace($this->appRoot);
        } catch (\Throwable $e) {
            return self::failure($e);
        }
        return Response::stream(function () use ($req, $file, $workspace): void {
            try {
                $this->export->snapshot(fn() => $this->export->writeCalendar((int) $req->user['id'], $file, static function (string $bytes): void {
                    echo $bytes;
                    if (connection_aborted()) {
                        throw new \RuntimeException('The export download was disconnected.');
                    }
                }));
            } finally {
                $workspace->close();
            }
        }, 'text/calendar; charset=utf-8', [
            'Content-Disposition' => self::attachment($file['fileName']),
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function failure(\Throwable $e): Response
    {
        $message = $e instanceof HttpError ? $e->getMessage() : 'The export could not be completed. Check that private export storage is writable and has enough free space, then try again.';
        if (!$e instanceof HttpError) {
            error_log('Better-Cal export failed: ' . $e->getMessage());
        }
        return Response::text($message, 'text/plain; charset=utf-8', $e instanceof HttpError ? $e->status : 500, ['Cache-Control' => 'no-store']);
    }

    /** A download header for any file name: an ASCII fallback, and the real name (RFC 6266). */
    public static function attachment(string $name): string
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9 ._()-]+/', '_', $name);
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }
}
