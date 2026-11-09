<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 *
 * Seeds Composer's dist cache from codeload.github.com for every GitHub zipball in a composer.lock.
 *
 * Some runners cannot reach api.github.com at all for the life of the job ("curl error 7 ... Failed to
 * connect to api.github.com", every package, every attempt) while github.com and codeload.github.com
 * answer. Composer downloads GitHub dists from api.github.com/repos/<owner>/<repo>/zipball/<ref>, which
 * is only a redirect to codeload.github.com/<owner>/<repo>/legacy.zip/<ref>; before downloading it
 * looks for the zip in its cache under <cache-files-dir>/<vendor>/<name>/sha1(<dist url>).zip
 * (Composer\Downloader\FileDownloader, no checksum for GitHub dists). This script writes exactly those
 * files from codeload, so the next `composer install` / `composer update` serves every GitHub dist
 * from the cache and never talks to api.github.com. Run it after a failed composer attempt: Composer
 * writes composer.lock before it downloads, so the lock already names every dist.
 *
 * Usage: php composer-dist-cache-from-codeload.php [composer.lock] [cache-files-dir]
 *   cache-files-dir defaults to `composer config cache-files-dir` run next to the lock file.
 */

declare(strict_types=1);

$lockFile = $argv[1] ?? 'composer.lock';
$cacheDir = $argv[2] ?? null;

if (!is_file($lockFile)) {
    fwrite(STDERR, "[dist-cache] no lock file at $lockFile: nothing to seed\n");
    exit(0);
}
if ($cacheDir === null) {
    $cacheDir = trim((string) shell_exec('cd ' . escapeshellarg(dirname($lockFile)) . ' && composer config cache-files-dir 2>/dev/null'));
}
if ($cacheDir === '') {
    fwrite(STDERR, "[dist-cache] cannot determine composer's cache-files-dir\n");
    exit(1);
}

$lock = json_decode((string) file_get_contents($lockFile), true);
$packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
$seeded = $present = $skipped = $failed = 0;

foreach ($packages as $package) {
    $url = $package['dist']['url'] ?? '';
    $type = $package['dist']['type'] ?? '';
    if ($type !== 'zip' || !preg_match('{^https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/?#]+)$}', $url, $m)) {
        $skipped++;
        continue;
    }
    $target = $cacheDir . '/' . $package['name'] . '/' . sha1($url) . '.zip';
    if (is_file($target)) {
        $present++;
        continue;
    }
    $codeload = "https://codeload.github.com/{$m[1]}/{$m[2]}/legacy.zip/{$m[3]}";
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
        fwrite(STDERR, "[dist-cache] cannot create " . dirname($target) . "\n");
        exit(1);
    }
    $tmp = $target . '.part';
    $ok = false;
    for ($attempt = 1; $attempt <= 3 && !$ok; $attempt++) {
        $ch = curl_init($codeload);
        $fh = fopen($tmp, 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'composer-dist-cache-from-codeload']);
        $ok = curl_exec($ch) !== false;
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if (!$ok) {
            fwrite(STDERR, "[dist-cache] {$package['name']}: attempt $attempt failed: $error\n");
            sleep(5 * $attempt);
        }
    }
    if ($ok && filesize($tmp) > 0 && rename($tmp, $target)) {
        $seeded++;
        continue;
    }
    @unlink($tmp);
    $failed++;
}

echo "[dist-cache] GitHub dists in $lockFile: seeded $seeded from codeload, $present already cached, $skipped not GitHub zips, $failed failed (cache: $cacheDir)\n";
exit($failed > 0 ? 1 : 0);
