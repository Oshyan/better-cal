<?php

declare(strict_types=1);

namespace BetterCal\Http\Controllers;

use BetterCal\Domain\ApiTokens;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;

final class TokensController
{
    public function __construct(private readonly ApiTokens $tokens)
    {
    }

    public function index(Request $req): Response
    {
        $this->requireSession($req);
        return Response::json(['tokens' => $this->tokens->listAll((int) $req->user['id'])]);
    }

    public function create(Request $req): Response
    {
        $this->requireSession($req);
        try {
            $created = $this->tokens->create((int) $req->user['id'], (string) ($req->str('name') ?? ''));
        } catch (\InvalidArgumentException $e) {
            throw HttpError::badRequest($e->getMessage());
        }
        // The token value is returned exactly once; only its hash is stored.
        return Response::json([
            'id' => $created['id'],
            'name' => $created['name'],
            'token' => $created['token'],
        ], 201);
    }

    public function delete(Request $req, array $params): Response
    {
        $this->requireSession($req);
        if (!$this->tokens->revoke((int) $req->user['id'], (int) $params['id'])) {
            throw HttpError::notFound('No such token');
        }
        return Response::json(['ok' => true]);
    }

    /** Token management is session-only: a bearer token must not mint or revoke tokens. */
    private function requireSession(Request $req): void
    {
        if ($req->authMethod !== 'session') {
            throw HttpError::forbidden('session_required', 'Token management requires session (cookie) authentication');
        }
    }
}
