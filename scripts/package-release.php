#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build the release archive for the Pelican Hub / manual installation.
 *
 * The archive is built from an explicit allowlist, never by zipping the working
 * tree or `git archive`: tests, CI files, local tooling and caches must never
 * reach an install archive, and the Hub source scan rejects development files
 * that call process or filesystem functions.
 *
 * Usage:
 *   php scripts/package-release.php [target.zip]
 *
 * The target directory must already exist. On success the entry list, byte size
 * and SHA-256 are printed; on any validation failure nothing is written.
 */
const PLUGIN_FILES = [
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'composer.json',
    'plugin.json',
    'docs/ARCHITECTURE.md',
    'docs/CLIENT-UI.md',
    'docs/DATABASE_CHANGES.md',
];

const PLUGIN_DIRECTORIES = [
    'config',
    'database/migrations',
    'lang',
    'resources',
    'src',
];

/**
 * Entries that must never appear in an install archive. Development tooling is
 * not needed at runtime and is scanned by the Hub.
 */
const FORBIDDEN_ENTRY_PATTERNS = [
    '#(^|/)tests/#',
    '#(^|/)tools/#',
    '#(^|/)vendor/#',
    '#(^|/)node_modules/#',
    '#(^|/)\.github/#',
    '#(^|/)\.git/#',
    '#(^|/)\.phpunit#',
    '#(^|/)\.pint#',
    '#(^|/)\.composer#',
    '#(^|/)\.mcp\.json$#',
    '#(^|/)\.env#',
    '#\.zip$#',
];

/**
 * Fixed timestamp (2026-01-01 UTC) applied to every archive entry, so building
 * the same tree twice produces byte-identical archives and the SHA-256 recorded
 * in update.json stays valid.
 */
const ARCHIVE_MTIME = 1767225600;

function fail(string $message): never
{
    echo 'error: '.$message.PHP_EOL;
    echo 'no archive was written.'.PHP_EOL;

    exit(1);
}

function relative_path(string $root, string $path): string
{
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

$root = dirname(__DIR__);

$target = $argv[1] ?? $root.DIRECTORY_SEPARATOR.'server-expiry.zip';

if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $target) !== 1) {
    $target = getcwd().DIRECTORY_SEPARATOR.$target;
}

if (is_dir($target)) {
    fail('the target is a directory: '.$target);
}

if (! is_dir(dirname($target))) {
    fail('the target directory does not exist: '.dirname($target));
}

if (! class_exists(ZipArchive::class)) {
    fail('the zip PHP extension is required.');
}

$manifestFile = $root.DIRECTORY_SEPARATOR.'plugin.json';

if (! is_file($manifestFile)) {
    fail('plugin.json is missing.');
}

$manifest = json_decode((string) file_get_contents($manifestFile), true);

if (! is_array($manifest)) {
    fail('plugin.json is not valid JSON.');
}

if (array_key_exists('meta', $manifest)) {
    fail('plugin.json still contains the internal meta block; remove it before packaging.');
}

if (($manifest['id'] ?? null) !== basename($root)) {
    fail('plugin.json id must match the plugin folder name: '.basename($root));
}

/** @var array<int, array{path: string, file: string}> $entries */
$entries = [];

foreach (PLUGIN_DIRECTORIES as $directory) {
    $start = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory);

    if (! is_dir($start)) {
        fail('missing plugin directory: '.$directory);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var array<int, string> $paths */
    $paths = [];

    foreach ($iterator as $item) {
        $paths[] = $item->getPathname();
    }

    sort($paths);

    foreach ($paths as $path) {
        $relative = relative_path($root, $path);

        if (is_dir($path)) {
            $entries[] = ['path' => $relative.'/', 'file' => $path];

            continue;
        }

        $entries[] = ['path' => $relative, 'file' => $path];
    }
}

foreach (PLUGIN_FILES as $relative) {
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

    if (! is_file($path)) {
        fail('missing plugin file: '.$relative);
    }

    $entries[] = ['path' => $relative, 'file' => $path];
}

// Validate the complete entry list before anything is written.
$seen = [];

foreach ($entries as $entry) {
    foreach (FORBIDDEN_ENTRY_PATTERNS as $pattern) {
        $matched = preg_match($pattern, $entry['path']);

        if ($matched === false) {
            fail('invalid forbidden-entry pattern: '.$pattern);
        }

        if ($matched === 1) {
            fail('refusing to package development path: '.$entry['path']);
        }
    }

    if (isset($seen[$entry['path']])) {
        fail('duplicate archive entry: '.$entry['path']);
    }

    $seen[$entry['path']] = true;
}

if (! isset($seen['plugin.json']) || ! isset($seen['src/ServerExpiryPlugin.php'])) {
    fail('the archive would be missing plugin.json or src/ServerExpiryPlugin.php.');
}

$archive = new ZipArchive;

if ($archive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('could not open the archive for writing: '.$target);
}

foreach ($entries as $entry) {
    if (str_ends_with($entry['path'], '/')) {
        $archive->addEmptyDir(rtrim($entry['path'], '/'));

        continue;
    }

    $archive->addFile($entry['file'], $entry['path']);
}

for ($index = 0; $index < $archive->numFiles; $index++) {
    $archive->setMtimeIndex($index, ARCHIVE_MTIME);
}

if ($archive->close() !== true) {
    fail('could not finalize the archive: '.$target);
}

echo 'zip='.$target.PHP_EOL;
echo 'bytes='.filesize($target).PHP_EOL;
echo 'sha256='.hash_file('sha256', $target).PHP_EOL;
echo 'entries='.count($entries).PHP_EOL;

foreach ($entries as $entry) {
    echo $entry['path'].PHP_EOL;
}
