<?php
declare(strict_types=1);

// Only trusted local code is loaded. Never extract the downloaded archive.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/server/src/Infra/Updater.php';
require_once dirname(__DIR__) . '/server/src/Domain/Updates.php';

function releaseArchiveFiles(string $path): array
{
    if (!is_file($path) || filesize($path) > BetterCal\Domain\Updates::MAX_ZIP_BYTES) {
        throw new RuntimeException('Release zip missing or larger than 100 MiB');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('Cannot open release zip');
    }
    try {
        BetterCal\Infra\Updater::checkZip($zip);
        $root = BetterCal\Infra\Updater::zipRoot($zip);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = substr($stat['name'], strlen($root));
            if ($name === '') { continue; }
            if (str_ends_with($name, '/')) { $files[$name] = 'directory'; continue; }
            $stream = $zip->getStreamIndex($i, ZipArchive::FL_UNCHANGED);
            if ($stream === false) { throw new RuntimeException('Cannot read zip entry'); }
            $hash = hash_init('sha256');
            $read = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false || ($chunk === '' && !feof($stream))) { throw new RuntimeException('Cannot read zip entry'); }
                    $read += strlen($chunk);
                    if ($read > $stat['size']) { throw new RuntimeException('Zip entry exceeds declared size'); }
                    hash_update($hash, $chunk);
                }
            } finally { fclose($stream); }
            if ($read !== $stat['size']) { throw new RuntimeException('Truncated zip entry'); }
            $files[$name] = hash_final($hash);
        }
        ksort($files, SORT_STRING);
        return $files;
    } finally { $zip->close(); }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if ($argc === 2) {
            releaseArchiveFiles($argv[1]);
        } elseif ($argc === 3) {
            if (releaseArchiveFiles($argv[1]) !== releaseArchiveFiles($argv[2])) {
                throw new RuntimeException('Release zip differs from the local rebuild');
            }
        } else { throw new RuntimeException('usage: php scripts/release-archive.php ZIP [OTHER_ZIP]'); }
    } catch (Throwable $e) {
        fwrite(STDERR, 'release-archive: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
