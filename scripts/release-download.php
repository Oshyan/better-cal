<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Bounded stdin-to-file copy, for gh's authenticated asset download stream.
$out = $argv[1] ?? '';
$limit = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
if (!$limit || $limit < 1 || $limit > 104857600) { exit(2); }
$file = @fopen($out, 'xb');
if ($file === false) { fwrite(STDERR, "Cannot create download file\n"); exit(1); }
$ok = false;
try {
    $read = 0;
    while (!feof(STDIN)) {
        $bytes = fread(STDIN, 65536);
        if ($bytes === false) { throw new RuntimeException('Download read failed'); }
        $read += strlen($bytes);
        if ($read > $limit) { throw new RuntimeException('Release asset exceeds size limit'); }
        if (fwrite($file, $bytes) !== strlen($bytes)) { throw new RuntimeException('Download write failed'); }
    }
    if ($read === 0) { throw new RuntimeException('Empty release asset'); }
    $ok = true;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
} finally {
    fclose($file);
    if (!$ok) { @unlink($out); }
}
exit($ok ? 0 : 1);
