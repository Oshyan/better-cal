<?php

declare(strict_types=1);

use BetterCal\Domain;
use BetterCal\Http\Controllers;
use BetterCal\Http\HttpError;
use BetterCal\Http\Request;
use BetterCal\Http\Response;
use BetterCal\Http\Router;
use BetterCal\Infra\Db;
use BetterCal\Infra\LlmGateway;

require dirname(__DIR__) . '/src/bootstrap.php';

$cfg = config();

try {
    $request = Request::fromGlobals();
} catch (HttpError $e) {
    Response::error($e->errorCode, $e->getMessage(), $e->status)->send();
    exit;
}

// ---- Public outbound feed: GET /feed/{token}.ics -------------------------
if (preg_match('#^/feed/([A-Za-z0-9_-]{20,64})\.ics$#', $request->path, $m)) {
    if ($request->method !== 'GET' && $request->method !== 'HEAD') {
        Response::error('method_not_allowed', 'Method not allowed', 405)->send();
        exit;
    }
    try {
        $db = new Db($cfg['db']);
        $outFeeds = new Domain\OutFeeds($db, new Domain\Search($db), $cfg);
        (new Controllers\OutFeedsController($outFeeds))->publicFeed($m[1])->send();
    } catch (\Throwable $e) {
        error_log('feed render error: ' . $e->getMessage());
        Response::text('Server error', 'text/plain; charset=utf-8', 500)->send();
    }
    exit;
}

// ---- API: /api/v1/* ------------------------------------------------------
if (str_starts_with($request->path, '/api/')) {
    bc_handle_api($request, $cfg);
    exit;
}

// ---- Static assets + SPA shell ------------------------------------------
bc_handle_static($request);
exit;

// --------------------------------------------------------------------------

function bc_handle_api(Request $request, array $cfg): void
{
    try {
        $db = new Db($cfg['db']);
        $auth = new Domain\Auth($db, $cfg);
        $apiTokens = new Domain\ApiTokens($db);
        $undo = new Domain\Undo($db);
        $labels = new Domain\Labels($db);
        $recurrence = new Domain\Recurrence();
        $events = new Domain\Events($db, $recurrence, $undo, $labels);
        $calendars = new Domain\Calendars($db, $undo, $labels);
        $folders = new Domain\Folders($db, $undo);
        $feeds = new Domain\Feeds($db);
        $search = new Domain\Search($db);
        $outFeeds = new Domain\OutFeeds($db, $search, $cfg);
        $quickAdd = new Domain\QuickAdd($db, new LlmGateway($cfg), $events);

        $authController = new Controllers\AuthController($auth);
        $calendarsController = new Controllers\CalendarsController($db, $calendars, $feeds);
        $foldersController = new Controllers\FoldersController($folders);
        $eventsController = new Controllers\EventsController($events);
        $quickAddController = new Controllers\QuickAddController($quickAdd);
        $searchController = new Controllers\SearchController($search, $events);
        $outFeedsController = new Controllers\OutFeedsController($outFeeds);
        $tokensController = new Controllers\TokensController($apiTokens);
        $healthController = new Controllers\HealthController($db, $cfg);

        $router = new Router();
        $base = '/api/v1';

        $router->add('POST', "$base/auth/login", [$authController, 'login']);
        $router->add('POST', "$base/auth/logout", [$authController, 'logout']);
        $router->add('GET', "$base/me", [$authController, 'me']);

        $router->add('GET', "$base/calendars", [$calendarsController, 'index']);
        $router->add('POST', "$base/calendars/subscribe", [$calendarsController, 'subscribe']);
        $router->add('POST', "$base/calendars/import", [$calendarsController, 'import']);
        $router->add('POST', "$base/calendars", [$calendarsController, 'create']);
        $router->add('POST', "$base/calendars/:id/refresh", [$calendarsController, 'refresh']);
        $router->add('PATCH', "$base/calendars/:id", [$calendarsController, 'patch']);
        $router->add('DELETE', "$base/calendars/:id", [$calendarsController, 'delete']);

        $router->add('POST', "$base/folders", [$foldersController, 'create']);
        $router->add('PATCH', "$base/folders/:id", [$foldersController, 'patch']);
        $router->add('DELETE', "$base/folders/:id", [$foldersController, 'delete']);

        $router->add('GET', "$base/events", [$eventsController, 'window']);
        $router->add('POST', "$base/events", [$eventsController, 'create']);
        $router->add('PATCH', "$base/events/:id", [$eventsController, 'patch']);
        $router->add('DELETE', "$base/events/:id", [$eventsController, 'delete']);
        $router->add('POST', "$base/events/:id/attendance", [$eventsController, 'attendance']);

        $router->add('POST', "$base/quickadd", [$quickAddController, 'run']);
        $router->add('POST', "$base/undo", function (Request $req) use ($undo): Response {
            $undone = $undo->undoLatest((int) $req->user['id']);
            return Response::json(['ok' => true, 'undone' => $undone]);
        });

        $router->add('GET', "$base/search", [$searchController, 'search']);

        $router->add('GET', "$base/outfeeds", [$outFeedsController, 'index']);
        $router->add('POST', "$base/outfeeds", [$outFeedsController, 'create']);
        $router->add('DELETE', "$base/outfeeds/:id", [$outFeedsController, 'delete']);

        $router->add('GET', "$base/tokens", [$tokensController, 'index']);
        $router->add('POST', "$base/tokens", [$tokensController, 'create']);
        $router->add('DELETE', "$base/tokens/:id", [$tokensController, 'delete']);

        $router->add('GET', "$base/health", fn(): Response => $healthController->health());

        $match = $router->match($request->method, $request->path);

        $isExempt = ($request->path === "$base/auth/login" && $request->method === 'POST')
            || ($request->path === "$base/health");

        if (!$isExempt) {
            $bearer = Domain\ApiTokens::parseBearer($request->header('Authorization'));
            if ($bearer !== null) {
                // Personal access token auth: no cookie involved, so CSRF-exempt.
                $user = $apiTokens->resolve($bearer);
                if ($user === null) {
                    throw HttpError::unauthorized('Invalid or expired API token');
                }
                $request->user = $user;
                $request->authMethod = 'token';
            } else {
                $session = $auth->resolve($request->cookies[Domain\Auth::COOKIE] ?? null);
                if ($session === null) {
                    throw HttpError::unauthorized();
                }
                $request->user = $session['user'];
                $request->csrf = $session['csrf'];
                $request->authMethod = 'session';

                if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)
                    && !hash_equals($session['csrf'], (string) ($request->header('X-CSRF') ?? ''))
                ) {
                    throw HttpError::forbidden('csrf', 'Missing or invalid X-CSRF header');
                }
            }
        }

        $response = ($match['handler'])($request, $match['params']);
        $response->send();
    } catch (HttpError $e) {
        Response::error($e->errorCode, $e->getMessage(), $e->status)->send();
    } catch (\PDOException $e) {
        error_log('db error: ' . $e->getMessage());
        Response::error('db_error', 'Database error', 500)->send();
    } catch (\Throwable $e) {
        error_log('unhandled error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        Response::error('internal_error', 'Internal server error', 500)->send();
    }
}

function bc_handle_static(Request $request): void
{
    if ($request->method !== 'GET' && $request->method !== 'HEAD') {
        Response::error('method_not_allowed', 'Method not allowed', 405)->send();
        return;
    }

    $webRoot = realpath(dirname(__DIR__, 2) . '/web');
    $path = $request->path;

    $relative = null;
    if (str_starts_with($path, '/assets/')) {
        $relative = substr($path, strlen('/assets/'));
    } elseif (in_array($path, ['/sw.js', '/manifest.webmanifest', '/favicon.ico', '/favicon.svg', '/favicon.png'], true)) {
        $relative = ltrim($path, '/');
    }

    if ($relative !== null) {
        if ($webRoot === false) {
            Response::text('Not found', 'text/plain; charset=utf-8', 404)->send();
            return;
        }
        $full = realpath($webRoot . '/' . $relative);
        // realpath resolves ../ and symlink tricks; require the result inside webRoot.
        if ($full === false || !is_file($full) || !str_starts_with($full, $webRoot . DIRECTORY_SEPARATOR)) {
            Response::text('Not found', 'text/plain; charset=utf-8', 404)->send();
            return;
        }
        $noCache = str_ends_with($full, 'sw.js');
        Response::file($full, bc_mime($full), [
            'Cache-Control' => $noCache ? 'no-cache' : 'public, max-age=3600',
        ])->send();
        return;
    }

    // Any other GET path serves the SPA shell.
    $index = $webRoot !== false ? $webRoot . '/index.html' : null;
    if ($index === null || !is_file($index)) {
        Response::text('Better-Cal backend is running; frontend not deployed yet.', 'text/plain; charset=utf-8', 200)->send();
        return;
    }
    Response::file($index, 'text/html; charset=utf-8', ['Cache-Control' => 'no-cache'])->send();
}

function bc_mime(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'txt' => 'text/plain; charset=utf-8',
        'ics' => 'text/calendar; charset=utf-8',
        default => 'application/octet-stream',
    };
}
