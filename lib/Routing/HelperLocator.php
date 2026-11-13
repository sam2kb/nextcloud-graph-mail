<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

/**
 * Resolves the paths the transport depends on and mirrors Symfony's
 * executable search order so preflight can predict what the mailer will pick.
 */
final class HelperLocator
{
    public const HELPER_RELATIVE_PATH = 'bin/sendmail';
    public const PHP_CANDIDATES = [
        '/usr/local/bin/php',
        '/usr/bin/php',
        '/usr/bin/php8.5',
        '/usr/bin/php8.4',
        '/usr/bin/php8.3',
    ];

    public function appPath(): string
    {
        $path = realpath(dirname(__DIR__, 2));
        return $path === false ? '' : $path;
    }

    public function helperPath(): string
    {
        $appPath = $this->appPath();
        return $appPath === '' ? '' : $appPath . '/' . self::HELPER_RELATIVE_PATH;
    }

    public function helperDirectory(): string
    {
        return dirname($this->helperPath());
    }

    public function helperIsExecutable(): bool
    {
        $path = $this->helperPath();
        return $path !== '' && is_file($path) && is_executable($path);
    }

    /**
     * proc_open() receives a shell command string from Symfony, so a path
     * containing whitespace or metacharacters cannot be used safely.
     */
    public function isShellSafePath(string $path): bool
    {
        return $path !== '' && preg_match('#^[A-Za-z0-9_@%+=:,./-]+$#D', $path) === 1;
    }

    public function findRoot(): ?string
    {
        $configured = getenv('GRAPH_MAIL_NC_ROOT');
        if (is_string($configured) && $configured !== '') {
            return $this->isValidRoot($configured) ? (realpath($configured) ?: null) : null;
        }

        $directory = $this->appPath();
        while ($directory !== '' && $directory !== '/' && $directory !== '.') {
            if ($this->isValidRoot($directory)) {
                return $directory;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                break;
            }
            $directory = $parent;
        }

        return null;
    }

    public function phpBinary(): ?string
    {
        $configured = getenv('GRAPH_MAIL_PHP_BIN');
        if (is_string($configured) && $configured !== '' && is_file($configured) && is_executable($configured)) {
            return $configured;
        }
        $path = getenv('PATH');
        if (is_string($path)) {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                if ($directory === '') {
                    continue;
                }
                $candidate = rtrim($directory, '/') . '/php';
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }
        foreach (self::PHP_CANDIDATES as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Same precedence as Symfony's ExecutableFinder: the process PATH first,
     * then the configured extra directories.
     *
     * @param list<string> $searchPaths
     */
    public function resolveSendmail(string $pathEnvironment, array $searchPaths): ?string
    {
        $directories = array_merge(
            $pathEnvironment === '' ? [] : explode(PATH_SEPARATOR, $pathEnvironment),
            $searchPaths,
        );
        foreach ($directories as $directory) {
            if ($directory === '') {
                $directory = '.';
            }
            $candidate = rtrim($directory, '/') . '/sendmail';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public function pathEnvironment(): string
    {
        $path = getenv('PATH');
        return is_string($path) ? $path : '';
    }

    private function isValidRoot(string $directory): bool
    {
        return is_file($directory . '/occ')
            && is_file($directory . '/lib/base.php')
            && is_file($directory . '/version.php')
            && is_file($directory . '/config/config.php');
    }
}
