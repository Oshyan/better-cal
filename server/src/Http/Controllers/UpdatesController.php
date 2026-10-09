<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Updates;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class UpdatesController
{
    public function __construct(private readonly Updates $updates)
    {
    }

    public function status(Request $req): Response
    {
        $req->requireSession('Checking application updates');
        return Response::json($this->updates->status((int) $req->user['id']));
    }

    public function check(Request $req): Response
    {
        $req->requireSession('Checking application updates');
        try {
            $this->updates->check(false);
        } catch (\Throwable $e) {
            throw new HttpError('update_check_failed', $e->getMessage(), 502);
        }
        return Response::json($this->updates->status((int) $req->user['id']));
    }

    public function dismiss(Request $req): Response
    {
        $req->requireSession('Dismissing an application update notice');
        $this->updates->dismiss((int) $req->user['id']);
        return Response::json($this->updates->status((int) $req->user['id']));
    }
}
