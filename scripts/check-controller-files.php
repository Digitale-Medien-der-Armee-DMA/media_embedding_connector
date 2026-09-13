#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fail before packaging when Nextcloud's attribute-route scanner would try to
 * reflect a controller class that the corresponding file does not declare.
 */

$controllerDirectory = $argv[1] ?? dirname(__DIR__) . '/lib/Controller';
if (!is_dir($controllerDirectory)) {
    fwrite(STDERR, "Controller directory not found: {$controllerDirectory}" . PHP_EOL);
    exit(1);
}

$errors = [];
$checked = 0;

foreach (new DirectoryIterator($controllerDirectory) as $entry) {
    $filename = $entry->getFilename();
    if (!str_ends_with($filename, 'Controller.php')) {
        continue;
    }

    ++$checked;
    if (!$entry->isFile() || $entry->isLink()) {
        $errors[] = "Controller entry must be a regular file: {$filename}";
        continue;
    }
    if (preg_match('/^[A-Z][A-Za-z0-9]*Controller\.php$/D', $filename) !== 1) {
        $errors[] = "Invalid controller filename: {$filename}";
        continue;
    }

    $source = file_get_contents($entry->getPathname());
    if ($source === false) {
        $errors[] = "Unable to read controller file: {$filename}";
        continue;
    }

    $namespace = '';
    $classes = [];
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for (++$index; $index < $count; ++$index) {
                $part = $tokens[$index];
                if ($part === ';' || $part === '{') {
                    break;
                }
                if (is_array($part) && in_array($part[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $namespace .= $part[1];
                }
            }
            continue;
        }

        if ($token[0] !== T_CLASS) {
            continue;
        }
        for (++$index; $index < $count; ++$index) {
            $part = $tokens[$index];
            if (is_array($part) && $part[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($part) && $part[0] === T_STRING) {
                $classes[] = ($namespace !== '' ? $namespace . '\\' : '') . $part[1];
            }
            break;
        }
    }

    $className = substr($filename, 0, -4);
    $expected = 'OCA\\MediaEmbeddingConnector\\Controller\\' . $className;
    if (!in_array($expected, $classes, true)) {
        $errors[] = sprintf(
            '%s must declare %s (found: %s)',
            $filename,
            $expected,
            $classes === [] ? 'no class' : implode(', ', $classes),
        );
    }
}

if ($checked === 0) {
    $errors[] = "No *Controller.php files found in {$controllerDirectory}";
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

printf("Validated %d Nextcloud controller files in %s%s", $checked, $controllerDirectory, PHP_EOL);
