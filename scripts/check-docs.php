#!/usr/bin/env php
<?php

/**
 * Verifies that every relative link in readme.md and docs/*.md points at an existing file,
 * and that every #anchor refers to a heading in the target file.
 *
 *   php scripts/check-docs.php
 */

declare(strict_types=1);

$root  = dirname(__DIR__);
$files = array_merge(
    [$root . '/readme.md', $root . '/CONTRIBUTING.md', $root . '/CHANGELOG.md', $root . '/SECURITY.md'],
    glob($root . '/docs/*.md') ?: [],
);

function slug(string $heading): string
{
    $heading = strtolower(trim(preg_replace('/[`*_]/', '', $heading) ?? ''));
    $heading = preg_replace('/[^a-z0-9 \-]/', '', $heading) ?? '';

    return str_replace(' ', '-', $heading);
}

/** @return string[] */
function anchors(string $file): array
{
    $found = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^#{1,6}\s+(.+)$/', $line, $m)) {
            $found[] = slug($m[1]);
        }
    }

    return $found;
}

$problems = 0;

foreach ($files as $file) {
    if (! is_file($file)) {
        continue;
    }

    $text = (string) file_get_contents($file);
    $text = preg_replace('/```.*?```/s', '', $text) ?? $text;   // ignore code blocks

    preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $text, $matches);

    foreach ($matches[1] as $link) {
        if (preg_match('#^(https?:|mailto:)#', $link)) {
            continue;
        }

        [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
        $target = $path === '' ? $file : realpath(dirname($file) . '/' . $path);

        if ($target === false || ! file_exists($target)) {
            echo 'BROKEN  ' . str_replace($root . '/', '', $file) . " -> {$link}\n";
            $problems++;
            continue;
        }

        if ($anchor !== null && is_file($target) && str_ends_with($target, '.md') && ! in_array($anchor, anchors($target), true)) {
            echo 'NO ANCHOR  ' . str_replace($root . '/', '', $file) . " -> {$link}\n";
            $problems++;
        }
    }
}

echo $problems === 0 ? "All documentation links resolve.\n" : "\n{$problems} problem(s) found.\n";
exit($problems === 0 ? 0 : 1);
