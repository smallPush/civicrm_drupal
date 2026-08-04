<?php

declare(strict_types=1);

$root = dirname(__DIR__);

try {
    $release = json_decode(
        (string) file_get_contents($root . '/release.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $lock = json_decode(
        (string) file_get_contents($root . '/composer.lock'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
} catch (JsonException $exception) {
    fwrite(STDERR, 'Invalid release metadata: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$requiredKeys = ['project', 'drupal', 'civicrm'];
foreach ($requiredKeys as $key) {
    if (!isset($release[$key]) || !is_string($release[$key]) || $release[$key] === '') {
        fwrite(STDERR, sprintf("release.json must contain a non-empty '%s' version.%s", $key, PHP_EOL));
        exit(1);
    }
}

$packages = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    $packages[$package['name']] = ltrim($package['version'], 'v');
}

$expectedPackages = [
    'drupal/core-recommended' => $release['drupal'],
    'civicrm/civicrm-core' => $release['civicrm'],
    'civicrm/civicrm-drupal-8' => $release['civicrm'],
];

foreach ($expectedPackages as $package => $expectedVersion) {
    $lockedVersion = $packages[$package] ?? null;
    if ($lockedVersion !== $expectedVersion) {
        fwrite(
            STDERR,
            sprintf(
                "%s is locked at '%s', but release.json declares '%s'.%s",
                $package,
                $lockedVersion ?? 'missing',
                $expectedVersion,
                PHP_EOL,
            ),
        );
        exit(1);
    }
}

$tag = sprintf('v%s', $release['project']);

if (($argv[1] ?? null) === '--check-tag') {
    $actualTag = $argv[2] ?? '';
    if ($actualTag !== $tag) {
        fwrite(STDERR, sprintf("Expected tag '%s', received '%s'.%s", $tag, $actualTag, PHP_EOL));
        exit(1);
    }
}

echo $tag . PHP_EOL;
