<?php

/**
 * Vendors @mtjncn/ui's ui.php into src/, and writes down what it took in ui.lock.json:
 *
 *   php bin/sync-ui.php 0.1.1                                a published release, from npm
 *   php bin/sync-ui.php ../unscripted-js/packages/ui/ui.php  a local checkout, while it's unreleased
 *
 * src/ui.php isn't this package's code. It's the framework the browser runs, served from
 * /unscripted/ui.php, and the server renders with that same file. Change it upstream, in
 * unscripted-js at packages/ui/ui.php, then sync it here: tests/ui-lock.php fails if src/ui.php
 * stops matching the lock, so an edit made here by hand is caught.
 */

$root = dirname(__DIR__);
$source = $argv[1] ?? null;
if ($source === null) {
    fwrite(STDERR, "usage: php bin/sync-ui.php <version|path-to-ui.php>\n");
    exit(1);
}

try {
    // A local checkout doesn't name a release, so the lock keeps the version it had and says so.
    [$ui, $version, $ahead] = is_file($source)
        ? [file_get_contents($source), null, "synced from a local checkout, not a release: repin once it's published"]
        : [fromNpm($source), $source, null];
} catch (Throwable $e) {
    fwrite(STDERR, 'sync-ui: ' . $e->getMessage() . "\n");
    exit(1);
}

// LF, so the hash is the same on a CRLF checkout.
$ui = str_replace("\r\n", "\n", $ui);
if (!str_contains($ui, 'namespace Unscripted\Ui;')) {
    fwrite(STDERR, "sync-ui: that doesn't look like ui.php (no Unscripted\\Ui namespace)\n");
    exit(1);
}

$lock = json_decode(file_get_contents("$root/ui.lock.json"), true, flags: JSON_THROW_ON_ERROR);
$lock['version'] = $version ?? $lock['version'];
$lock['sha256'] = hash('sha256', $ui);
if ($ahead === null) {
    unset($lock['ahead']);
} else {
    $lock['ahead'] = $ahead;
}

file_put_contents("$root/src/ui.php", $ui);
file_put_contents("$root/ui.lock.json", json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "src/ui.php is now {$lock['package']}@{$lock['version']} ({$lock['sha256']})\n";
if (isset($lock['ahead'])) {
    echo "note: {$lock['ahead']}\n";
}

/** A published release's ui.php, from the npm registry. */
function fromNpm(string $version): string
{
    $meta = json_decode(get("https://registry.npmjs.org/@mtjncn/ui/$version"), true, flags: JSON_THROW_ON_ERROR);
    $url = $meta['dist']['tarball'] ?? throw new RuntimeException("@mtjncn/ui@$version has no tarball");
    $tgz = tempnam(sys_get_temp_dir(), 'ui') . '.tar.gz';
    file_put_contents($tgz, get($url));
    $ui = @file_get_contents("phar://$tgz/package/ui.php");
    unlink($tgz);
    return $ui === false ? throw new RuntimeException("no package/ui.php in $url") : $ui;
}

function get(string $url): string
{
    $body = @file_get_contents($url, context: stream_context_create(['http' => ['timeout' => 30]]));
    return $body === false ? throw new RuntimeException("couldn't fetch $url") : $body;
}
