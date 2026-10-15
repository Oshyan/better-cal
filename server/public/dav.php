<?php

declare(strict_types=1);

// CalDAV front controller. nginx routes "location ^~ /dav" here (see the
// commented block in scripts/deploy.sh); direct hits via /dav.php/... work
// too so the endpoint is usable before the nginx change lands.

use BetterCal\Dav\AuthBackend;
use BetterCal\Dav\CalendarBackend;
use BetterCal\Dav\PrincipalBackend;
use BetterCal\Domain\Undo;
use BetterCal\Infra\Db;

define('BETTERCAL_WEB_ENTRY', true);
require dirname(__DIR__) . '/src/bootstrap.php';

if (BetterCal\Http\InstallPages::needsSetup(dirname(__DIR__, 2))) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Better-Cal is not set up yet.\n";
    exit;
}
if (!BetterCal\Http\HostCheck::allowed(config()['base_url'], (string) ($_SERVER['HTTP_HOST'] ?? ''), config()['allowed_hosts'])) {
    BetterCal\Http\HostCheck::respond(config()['base_url'], (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
}
if (BetterCal\Support\Maintenance::active(config()['app_root'])) {
    BetterCal\Support\Maintenance::respond('/dav');
}
if (BetterCal\Http\InstallPages::needsDatabaseUpdate(config())) {
    BetterCal\Http\InstallPages::databaseUpdate(config(), '/dav');
}
// Phones syncing are often what reaches the server: they notice a stopped worker too.
BetterCal\Domain\WorkerWatch::afterResponse(config());

BetterCal\Domain\ActivityContext::set('caldav');

if (!class_exists(\Sabre\DAV\Server::class)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CalDAV unavailable: sabre/dav is not installed (composer install has not run).\n";
    exit;
}

$cfg = config();
$db = new Db($cfg['db']);

// Base URI: "/dav" when routed by nginx, "/dav.php" when hit directly.
$requestPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$baseUri = str_starts_with($requestPath, '/dav.php') ? '/dav.php' : '/dav';

$principalBackend = new PrincipalBackend($db);
$calendarBackend = new CalendarBackend($db, new Undo($db));

$server = new \Sabre\DAV\Server([
    new \Sabre\DAVACL\PrincipalCollection($principalBackend),
    new \Sabre\CalDAV\CalendarRoot($principalBackend, $calendarBackend),
]);
$server->setBaseUri($baseUri);

// CalDAV sign-in shares the login form's limits on password guessing.
$loginGuard = new \BetterCal\Domain\LoginGuard(new \BetterCal\Infra\Throttle($db), $cfg['auth']['trusted_proxies'], $cfg['auth']['max_failures'], $db);
$server->addPlugin(new \Sabre\DAV\Auth\Plugin(new AuthBackend($db, $loginGuard)));
$server->addPlugin(new \BetterCal\Dav\SizedCalDavPlugin()); // sabre's CalDAV plugin, advertising our object size limit
$server->addPlugin(new \Sabre\DAV\Sync\Plugin());

$aclPlugin = new \Sabre\DAVACL\Plugin();
$aclPlugin->hideNodesFromListings = true;
$server->addPlugin($aclPlugin);

$server->exec();
