<?php

declare(strict_types=1);

/**
 * Fails when the plugin references a Shopware bundle that is not a runtime
 * requirement.
 *
 * This is the regression that stopped the v3.5.0 Store analysis: the plugin
 * extends StorefrontController, implements CookieProviderInterface and
 * subscribes to five Storefront page events, while shopware/storefront sat in
 * require-dev. The Store installs runtime requirements only, so every one of
 * those symbols resolved to nothing and the analyser aborted with internal
 * errors before reporting a single real finding.
 *
 * `shopware-cli extension validate` does not catch this - it lints the manifest
 * and config, not PHP symbols - so the check lives here.
 *
 * Scope note: this deliberately checks bundle *packages*, not individual
 * classes. The plugin supports Shopware 6.5 to 6.7 and imports classes that
 * exist in only some of them (AsyncPaymentTransactionStruct, for instance, is
 * gone in 6.7). Those are expected and handled at runtime; a missing bundle
 * never is.
 *
 * Usage: php check-runtime-symbols.php <package-dir>
 */

$packageDir = $argv[1] ?? null;

if ($packageDir === null || !is_dir($packageDir)) {
    fwrite(STDERR, "usage: php check-runtime-symbols.php <package-dir>\n");
    exit(2);
}

$packageDir = rtrim($packageDir, '/');
$srcDir = $packageDir . '/src';
$composerFile = $packageDir . '/composer.json';

if (!is_dir($srcDir) || !is_file($composerFile)) {
    fwrite(STDERR, "not an extension package: {$packageDir}\n");
    exit(2);
}

/** Shopware namespace root => composer package that provides it. */
const BUNDLE_PACKAGES = [
    'Core' => 'shopware/core',
    'Storefront' => 'shopware/storefront',
    'Administration' => 'shopware/administration',
    'Elasticsearch' => 'shopware/elasticsearch',
];

$composer = json_decode((string)file_get_contents($composerFile), true);
$runtimeRequires = array_change_key_case($composer['require'] ?? [], CASE_LOWER);

$referenced = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $code = (string)file_get_contents($file->getPathname());

    // Tokenize rather than grep: a service id in a string literal, such as
    // NotificationServiceFactory's container->has('Shopware\Administration\...')
    // guard, is not a symbol reference and resolves fine without the bundle.
    // Only name tokens count.
    foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] !== T_NAME_QUALIFIED && $token[0] !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }

        if (preg_match('#^\\\\?Shopware\\\\([A-Za-z0-9_]+)\\\\#', $token[1], $match) !== 1) {
            continue;
        }

        $bundle = $match[1];
        if (!isset(BUNDLE_PACKAGES[$bundle])) {
            continue;
        }

        $referenced[$bundle][] = str_replace($packageDir . '/', '', $file->getPathname());
    }
}

ksort($referenced);
$missing = [];

foreach ($referenced as $bundle => $usedIn) {
    $package = BUNDLE_PACKAGES[$bundle];
    $declared = isset($runtimeRequires[$package]);
    $files = array_values(array_unique($usedIn));

    printf(
        "  %-8s Shopware\\%-15s -> %-26s (%d file%s)\n",
        $declared ? 'OK' : 'MISSING',
        $bundle,
        $package,
        count($files),
        count($files) === 1 ? '' : 's'
    );

    if (!$declared) {
        $missing[$package] = array_slice($files, 0, 5);
    }
}

if ($missing === []) {
    echo "\nEvery referenced Shopware bundle is declared under \"require\".\n";
    exit(0);
}

echo "\n";
foreach ($missing as $package => $files) {
    fwrite(STDERR, "::error::{$package} is referenced by the plugin but is not a runtime requirement.\n");
    foreach ($files as $file) {
        fwrite(STDERR, "    {$file}\n");
    }
}
fwrite(STDERR, "\nMove the package into \"require\" in composer.json, with the same constraint as shopware/core.\n");

exit(1);
