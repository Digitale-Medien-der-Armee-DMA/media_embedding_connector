<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/NextcloudStubs.php';
require_once dirname(__DIR__) . '/psalm-stubs/NextcloudPrivateTypes.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

// nextcloud/ocp ships no autoloader. Load public API types that the stubs above
// do not replace straight from the package.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'OCP\\')) {
        return;
    }
    $file = dirname(__DIR__) . '/vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once __DIR__ . '/Support/InMemoryIndexJobDatabase.php';
