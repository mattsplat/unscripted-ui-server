<?php
// composer test: src/ui.php is the @mtjncn/ui release ui.lock.json names, not something edited
// here. It's vendored, not this package's code: change it upstream and run bin/sync-ui.php.

$root = dirname(__DIR__);
$lock = json_decode(file_get_contents("$root/ui.lock.json"), true, flags: JSON_THROW_ON_ERROR);
// Hashed with LF endings, so a CRLF checkout matches too.
$sha = hash('sha256', str_replace("\r\n", "\n", file_get_contents("$root/src/ui.php")));

if ($sha === $lock['sha256']) {
    echo "ok   src/ui.php is {$lock['package']}@{$lock['version']}\n";
    if (isset($lock['ahead'])) {
        echo "     ahead of that release: {$lock['ahead']}\n";
    }
    echo "\nall passed\n";
    exit(0);
}

echo "FAIL src/ui.php doesn't match ui.lock.json\n"
    . "  got:  $sha\n"
    . "  want: {$lock['sha256']}\n"
    . "  src/ui.php is vendored from {$lock['package']}: change packages/ui/ui.php in\n"
    . "  unscripted-js, then run php bin/sync-ui.php here. Don't edit it in this repo.\n"
    . "\n1 failed\n";
exit(1);
