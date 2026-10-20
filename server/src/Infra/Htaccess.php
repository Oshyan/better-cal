<?php

declare(strict_types=1);

namespace BetterCal\Infra;

/**
 * Better-Cal's lines in an .htaccess file, kept apart from everyone else's
 * (#149). Control panels write their PHP version choice into the .htaccess
 * of the folder a domain points at (server/public), and owners add rules of
 * their own. Since 1.0 Better-Cal's rules sit between "# BEGIN Better-Cal"
 * and "# END Better-Cal", and an update replaces only those lines, the way
 * WordPress handles its own block.
 */
final class Htaccess
{
    public const BEGIN = '# BEGIN Better-Cal';
    public const END = '# END Better-Cal';

    /** The two files Better-Cal ships, relative to the app folder. */
    public const FILES = ['.htaccess', 'server/public/.htaccess'];

    /**
     * The files as releases before 1.0 shipped them, without markers (each
     * had one version: server/public's since 0.8.3, the top-level one since
     * 0.9.81). An update from one of those finds them word for word inside
     * the owner's file and swaps in the marked block.
     */
    private const LEGACY = [
        <<<'LEGACY'
# Better-Cal: nothing in this folder is meant to be served except
# server/public, which has its own .htaccess and is where your domain or
# subdomain should point (docs/install.md, Choosing where to put it).
#
# Some hosts only let a subdomain point inside public_html, so this whole
# folder can end up inside the main site's web root, where example.com/
# better-cal/.env or the setup code would otherwise be downloadable. This
# refuses every request for anything here; server/public's own rules replace
# these for the app itself. Apache and LiteSpeed read it; Nginx ignores it,
# so with Nginx point the site at server/public and keep this folder out of
# any web root.
#
# A rewrite rule rather than "Require all denied", which needs AuthConfig
# overrides that some shared hosts don't allow (an error on every request).
# The app needs mod_rewrite anyway.

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^ - [F]
</IfModule>
LEGACY . "\n",
        <<<'LEGACY'
# Apache and LiteSpeed routing for Better-Cal (docs/install.md). Nginx ignores
# this file; its configuration is in the same document.
#
# Every request goes to index.php except /dav, which goes to dav.php. [END]
# rather than [L]: in a directory context [L] starts another rewrite pass,
# which would send dav.php on to index.php. The first rule hands the
# Authorization header to PHP (API tokens and CalDAV sign-ins arrive in it);
# it works where CGIPassAuth is not allowed, which is most shared hosting.
#
# Needs mod_rewrite and AllowOverride FileInfo (or All) for this directory.
# No Options line: hosts that allow FileInfo but not Options would answer
# every request with an error, and listings can't happen anyway, since every
# path goes to the app.

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    RewriteRule ^dav(/|$) dav.php [END]
    RewriteRule ^ index.php [END]
</IfModule>
LEGACY . "\n",
    ];

    /**
     * Where the marked block is in $text, as [offset, length], from the
     * BEGIN line through the END line and its line break. Null unless there
     * is exactly one of each, in that order.
     *
     * @return array{int, int}|null
     */
    public static function block(string $text): ?array
    {
        if (preg_match_all('/^' . preg_quote(self::BEGIN, '/') . '[ \t]*$/m', $text) !== 1
            || preg_match_all('/^' . preg_quote(self::END, '/') . '[ \t]*$/m', $text) !== 1) {
            return null;
        }
        $pattern = '/^' . preg_quote(self::BEGIN, '/') . '[ \t]*\r?\n.*?^' . preg_quote(self::END, '/') . '[ \t]*(?:\r?\n|\z)/ms';
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        return [$m[0][1], strlen($m[0][0])];
    }

    /**
     * The new release's file with the owner's own lines from the current one
     * kept around it.
     *
     *   kept      the owner had lines outside Better-Cal's, and they stay
     *   replaced  the owner had changed Better-Cal's own lines (or the file
     *             couldn't be matched at all): the release's lines win, and
     *             the caller saves the old file for them
     *
     * $shippedHash is the SHA-256 of the file as the current version shipped
     * it (its .release-files entry), or null when that isn't known.
     *
     * @return array{text: string, kept: bool, replaced: bool}
     */
    public static function merge(string $current, string $shipped, ?string $shippedHash): array
    {
        $new = self::block($shipped);
        if ($new === null || $current === $shipped) {
            return ['text' => $shipped, 'kept' => false, 'replaced' => false];
        }
        $ours = substr($shipped, $new[0], $new[1]);
        $at = self::block($current);
        if ($at !== null) {
            $theirs = substr($current, $at[0], $at[1]);
            $before = substr($current, 0, $at[0]);
            $after = substr($current, $at[0] + $at[1]);
            $edited = $theirs !== $ours && ($shippedHash === null || !hash_equals($shippedHash, hash('sha256', $theirs)));
            return ['text' => self::join($before, $ours, $after), 'kept' => trim($before . $after) !== '', 'replaced' => $edited];
        }
        foreach (self::LEGACY as $legacy) {
            if (substr_count($current, $legacy) === 1) {
                [$before, $after] = explode($legacy, $current, 2);
                return ['text' => self::join($before, $ours, $after), 'kept' => trim($before . $after) !== '', 'replaced' => false];
            }
        }
        return ['text' => $shipped, 'kept' => false, 'replaced' => trim($current) !== ''];
    }

    private static function join(string $before, string $block, string $after): string
    {
        if ($before !== '' && !str_ends_with($before, "\n")) {
            $before .= "\n";
        }
        return $before . $block . $after;
    }
}
