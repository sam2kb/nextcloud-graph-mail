<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

use OCA\GraphMail\Port\RoutingConfig;

/**
 * Local, side-effect-free checks. No network request and no configuration
 * write happens here; token acquisition is a separate explicit action.
 */
final class Preflight
{
    public function __construct(
        private readonly HelperLocator $locator,
        private readonly RoutingConfig $routing,
    ) {
    }

    public function check(bool $withHandshake = false): PreflightReport
    {
        $checks = [];
        $helper = $this->locator->helperPath();

        $checks[] = new PreflightCheck('helper_present', is_file($helper) ? PreflightCheck::OK : PreflightCheck::FAIL, basename($helper));
        $checks[] = new PreflightCheck('helper_executable', $this->locator->helperIsExecutable() ? PreflightCheck::OK : PreflightCheck::FAIL);
        $checks[] = new PreflightCheck('helper_path_safe', $this->locator->isShellSafePath($helper) ? PreflightCheck::OK : PreflightCheck::FAIL);
        $checks[] = new PreflightCheck('proc_open', $this->functionAvailable('proc_open') ? PreflightCheck::OK : PreflightCheck::FAIL);
        $checks[] = new PreflightCheck('exec', $this->functionAvailable('exec') ? PreflightCheck::OK : PreflightCheck::FAIL);
        $checks[] = new PreflightCheck('config_writable', $this->routing->isReadOnly() ? PreflightCheck::FAIL : PreflightCheck::OK);
        $checks[] = $this->ownerCheck();
        $root = $this->locator->findRoot();
        $checks[] = new PreflightCheck('nextcloud_root', $root !== null ? PreflightCheck::OK : PreflightCheck::FAIL, $root ?? '');
        $php = $this->locator->phpBinary();
        $checks[] = new PreflightCheck('php_binary', $php !== null ? PreflightCheck::OK : PreflightCheck::FAIL, $php ?? '');

        if ($withHandshake && (new PreflightReport($checks))->canActivate()) {
            $checks[] = $this->handshake();
        }

        $resolved = $this->locator->resolveSendmail($this->locator->pathEnvironment(), $this->routing->current()->searchPaths ?? []);
        if ($resolved === null) {
            $checks[] = new PreflightCheck('sendmail_resolution', PreflightCheck::WARN, 'helper_not_selected_yet');
        } elseif ($resolved === $helper) {
            $checks[] = new PreflightCheck('sendmail_resolution', PreflightCheck::OK, '');
        } else {
            $checks[] = new PreflightCheck('sendmail_resolution', PreflightCheck::FAIL, $resolved);
        }

        return new PreflightReport($checks);
    }

    /**
     * Start the packaged launcher and speak only the greeting and QUIT. This
     * proves the interpreter, occ boot, argv and stdout are usable without
     * sending a message or touching routing.
     */
    private function handshake(): PreflightCheck
    {
        $pipes = [];
        $process = @proc_open([$this->locator->helperPath(), '-bs'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return new PreflightCheck('helper_handshake', PreflightCheck::FAIL, 'helper_not_startable');
        }
        $deadline = microtime(true) + 10;
        foreach ([1, 2] as $index) {
            stream_set_blocking($pipes[$index], false);
        }
        try {
            $greeting = $this->readLineBounded($pipes[1], $pipes[2], $deadline);
            if ($greeting === null || !str_starts_with($greeting, '220 ')) {
                return new PreflightCheck('helper_handshake', PreflightCheck::FAIL, 'greeting_missing_or_dirty');
            }
            fwrite($pipes[0], "QUIT\r\n");
            fflush($pipes[0]);
            fclose($pipes[0]);
            $bye = $this->readLineBounded($pipes[1], $pipes[2], $deadline);
            if ($bye === null || !str_starts_with($bye, '221 ')) {
                return new PreflightCheck('helper_handshake', PreflightCheck::FAIL, 'quit_reply_missing');
            }
            do {
                $state = proc_get_status($process);
                if (!$state['running']) {
                    return new PreflightCheck('helper_handshake', $state['exitcode'] === 0 ? PreflightCheck::OK : PreflightCheck::FAIL, $state['exitcode'] === 0 ? '' : 'helper_failed');
                }
                // Drain diagnostics without retaining their potentially sensitive contents.
                fread($pipes[2], 4096);
                usleep(10_000);
            } while (microtime(true) < $deadline);
            return new PreflightCheck('helper_handshake', PreflightCheck::FAIL, 'helper_timeout');
        } finally {
            $state = proc_get_status($process);
            if ($state['running']) {
                @proc_terminate($process);
                usleep(50_000);
                @proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
        }
    }

    /** @param resource $stream @param resource $stderr */
    private function readLineBounded($stream, $stderr, float $deadline): ?string
    {
        $line = '';
        while (microtime(true) < $deadline && strlen($line) < 2048) {
            $read = [$stream, $stderr];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100_000) === false) {
                return null;
            }
            foreach ($read as $ready) {
                if ($ready === $stderr) {
                    fread($stderr, 4096);
                    continue;
                }
                $char = fread($stream, 1);
                if ($char === '' || $char === false) {
                    return null;
                }
                $line .= $char;
                if (str_ends_with($line, "\r\n")) {
                    return $line;
                }
            }
        }
        return null;
    }

    private function ownerCheck(): PreflightCheck
    {
        if (!function_exists('posix_getuid')) {
            return new PreflightCheck('config_owner', PreflightCheck::WARN, 'posix_unavailable');
        }
        $configPath = '';
        $root = $this->locator->findRoot();
        if ($root !== null) {
            $configPath = $root . '/config/config.php';
        }
        if ($configPath === '' || !is_file($configPath)) {
            return new PreflightCheck('config_owner', PreflightCheck::WARN, 'config_not_found');
        }
        $owner = @fileowner($configPath);
        if ($owner === false) {
            return new PreflightCheck('config_owner', PreflightCheck::WARN, 'owner_unknown');
        }
        return new PreflightCheck(
            'config_owner',
            $owner === posix_getuid() ? PreflightCheck::OK : PreflightCheck::FAIL,
            'owner=' . $owner . ' process=' . posix_getuid(),
        );
    }

    private function functionAvailable(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($name, $disabled, true);
    }
}
