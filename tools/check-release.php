<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Curentis\OpenFga\Version;

$tag = getenv('RELEASE_TAG');
if (!is_string($tag) || $tag === '') {
    fwrite(STDERR, "RELEASE_TAG is required.\n");
    exit(1);
}

$version = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
if (Version::VERSION !== $version) {
    fwrite(STDERR, sprintf(
        "Version::VERSION is %s but the tag is %s.\n",
        Version::VERSION,
        $tag,
    ));
    exit(1);
}

$changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
$heading = '## [' . $version . ']';
$start = strpos($changelog, $heading);
if ($start === false) {
    fwrite(STDERR, "CHANGELOG.md is missing {$heading}.\n");
    exit(1);
}

$unreleasedHeading = '## [Unreleased]';
$unreleasedStart = strpos($changelog, $unreleasedHeading);
if ($unreleasedStart !== false) {
    $unreleased = substr($changelog, $unreleasedStart + strlen($unreleasedHeading));
    $unreleasedEnd = strpos($unreleased, "\n## ");
    if ($unreleasedEnd !== false) {
        $unreleased = substr($unreleased, 0, $unreleasedEnd);
    }
    if (trim($unreleased) !== '') {
        fwrite(STDERR, "CHANGELOG.md still has entries under {$unreleasedHeading}. Move them into {$heading}.\n");
        exit(1);
    }
}

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments)) {
    $arguments = [];
}
$notesOnly = in_array('--notes', $arguments, true);
if (!$notesOnly) {
    echo "Release {$tag} matches Version::VERSION and {$heading}.\n";
    exit(0);
}

$headingLineEnd = strpos($changelog, "\n", $start);
$body = $headingLineEnd === false ? '' : substr($changelog, $headingLineEnd + 1);
$next = strpos($body, "\n## ");
if ($next !== false) {
    $body = substr($body, 0, $next);
}

echo trim($body) . "\n";
