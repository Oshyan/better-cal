<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\Proposals;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

/**
 * The user's side of the C11 loop: see what a plugin suggests, accept it (one
 * atomic materialization), reject it, or undo an acceptance.
 */
final class ProposalsController
{
    public function __construct(private readonly Proposals $proposals)
    {
    }

    public function index(Request $req): Response
    {
        $status = isset($req->query['status']) ? (string) $req->query['status'] : 'open';
        return Response::json(['proposals' => $this->proposals->listFor((int) $req->user['id'], $status)]);
    }

    public function accept(Request $req, array $params): Response
    {
        return Response::json($this->proposals->accept((int) $req->user['id'], (int) $params['id']));
    }

    public function reject(Request $req, array $params): Response
    {
        return Response::json(['proposal' => $this->proposals->reject((int) $req->user['id'], (int) $params['id'])]);
    }

    public function undoAccept(Request $req, array $params): Response
    {
        return Response::json($this->proposals->undoAccept((int) $req->user['id'], (int) $params['id']));
    }
}
