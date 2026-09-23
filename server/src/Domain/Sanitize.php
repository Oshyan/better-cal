<?php

declare(strict_types=1);

namespace BetterCal\Domain;

/**
 * Pure HTML sanitizer for event descriptions. Descriptions are one field
 * storing either plain text (legacy and quick-add) or sanitized HTML (the
 * rich text editor). Sanitization is allowlist-based: a small set of
 * formatting tags survives, everything else (attributes, styles, classes,
 * scripts, iframes, unknown tags) is stripped, keeping the text content of
 * unwrapped elements. No DB, no network; unit-tested in server/tests/run.php.
 */
final class Sanitize
{
    /**
     * Every <script>/<style> (or other named) block in untrusted HTML, found
     * by one forward scan: each tag's next position is remembered and only
     * searched again once passed, the scan never restarts from an earlier
     * point, and an unclosed block ends it. The regexes this replaces did
     * O(n*k) work on a body of repeated "<script" (scan 2026-09-23, F9).
     *
     * @param list<string> $tags lower-case tag names
     * @return list<array{tag:string,attrs:string,body:string,start:int,end:int}>
     */
    public static function htmlBlocks(string $html, array $tags = ['script', 'style']): array
    {
        $lower = strtolower($html);
        $n = strlen($html);
        $next = [];
        $out = [];
        $pos = 0;
        while ($pos < $n) {
            $best = null;
            $bestTag = null;
            foreach ($tags as $t) {
                if (!array_key_exists($t, $next) || ($next[$t] !== false && $next[$t] < $pos)) {
                    $next[$t] = strpos($lower, '<' . $t, $pos);
                }
                if ($next[$t] !== false && ($best === null || $next[$t] < $best)) {
                    $best = $next[$t];
                    $bestTag = $t;
                }
            }
            if ($best === null) {
                break;
            }
            $after = $best + 1 + strlen($bestTag);
            $c = $lower[$after] ?? '';
            if (!($c === '>' || $c === '/' || ctype_space($c))) {
                $pos = $after; // "<scripts" or "<script<script": not this tag
                continue;
            }
            $gt = strpos($lower, '>', $after);
            if ($gt === false) {
                break;
            }
            $close = strpos($lower, '</' . $bestTag, $gt + 1);
            if ($close === false) {
                break;
            }
            $closeGt = strpos($lower, '>', $close);
            $end = $closeGt === false ? $n : $closeGt + 1;
            $out[] = ['tag' => $bestTag, 'attrs' => substr($html, $after, $gt - $after), 'body' => substr($html, $gt + 1, $close - $gt - 1), 'start' => $best, 'end' => $end];
            $pos = $end;
        }
        return $out;
    }

    /** Untrusted HTML without its script and style blocks (each replaced by a space). Linear. */
    public static function dropScriptStyle(string $html): string
    {
        $out = '';
        $pos = 0;
        foreach (self::htmlBlocks($html) as $b) {
            $out .= substr($html, $pos, $b['start'] - $pos) . ' ';
            $pos = $b['end'];
        }
        return $out . substr($html, $pos);
    }

    /** Tags emitted as-is (no attributes). */
    private const ALLOWED = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'a', 'ul', 'ol', 'li', 'div'];

    /** Tags dropped WITH their contents. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'head', 'title', 'svg', 'math', 'template', 'form', 'input', 'button', 'select', 'textarea'];

    /** True when the string contains tag-like markup (an HTML description). */
    public static function isHtml(?string $text): bool
    {
        // A '<' immediately followed by a tag name (or '/'): markup, not prose.
        return $text !== null && preg_match('/<\/?[a-zA-Z][^>]*>/', $text) === 1;
    }

    /**
     * Sanitize a description for storage. Plain text (no tag-like markup)
     * passes through untouched so "a < b" style prose never gets mangled;
     * anything containing markup is run through the allowlist sanitizer.
     */
    public static function description(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        if (!self::isHtml($text)) {
            return $text;
        }
        $clean = self::html($text);
        return $clean === '' ? null : $clean;
    }

    /** Allowlist-sanitize an HTML fragment. */
    public static function html(string $input): string
    {
        if (!class_exists(\DOMDocument::class)) {
            // No DOM extension: fall back to text-only (never emit raw markup).
            return htmlspecialchars(self::toText($input), ENT_QUOTES, 'UTF-8');
        }
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // The XML prolog pins the parser to UTF-8; the wrapper div keeps
        // fragment-level text nodes intact.
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="__bc_root">' . $input . '</div>',
            LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__bc_root') ?? $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return '';
        }
        return trim(self::renderChildren($root));
    }

    /**
     * Flatten a description (HTML or plain) to plain text. Block-level tags
     * and <br> become newlines; entities are decoded. Used for ICS DESCRIPTION
     * and anywhere a text-only rendering is needed.
     */
    public static function toText(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        if (!self::isHtml($text)) {
            return $text;
        }
        $s = preg_replace('/<\s*(script|style|iframe)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $text) ?? $text;
        $s = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $s) ?? $s;
        $s = preg_replace('/<\s*\/\s*(p|div|li|ul|ol|h[1-6]|blockquote|tr)\s*>/i', "\n", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse runs of 3+ newlines and trim edges.
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return trim($s);
    }

    // ---- internals -----------------------------------------------------

    private static function renderChildren(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::renderNode($child);
        }
        return $out;
    }

    private static function renderNode(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->data, ENT_QUOTES, 'UTF-8');
        }
        if (!$node instanceof \DOMElement) {
            return ''; // comments, processing instructions, CDATA
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROPPED, true)) {
            return '';
        }
        if (!in_array($tag, self::ALLOWED, true)) {
            // Unknown tag (span, table, h1, ...): unwrap, keep the children.
            return self::renderChildren($node);
        }
        if ($tag === 'br') {
            return '<br>';
        }
        if ($tag === 'a') {
            $href = self::safeHref($node->getAttribute('href'));
            if ($href === null) {
                return self::renderChildren($node); // javascript:/data:/relative: unwrap
            }
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
                . self::renderChildren($node) . '</a>';
        }
        // All other allowed tags: emitted bare, every attribute dropped.
        return '<' . $tag . '>' . self::renderChildren($node) . '</' . $tag . '>';
    }

    /** Only absolute http/https URLs survive. */
    private static function safeHref(string $href): ?string
    {
        $href = trim($href);
        return preg_match('/^https?:\/\//i', $href) === 1 ? $href : null;
    }
}
