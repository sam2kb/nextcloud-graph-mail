<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Adapter;

use OCA\GraphMail\Port\Lock;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

final class NextcloudLock implements Lock
{
    private const PREFIX = 'graph_mail/';

    /** @var array<string, true> locks held by this process */
    private array $held = [];

    public function __construct(
        private readonly ILockingProvider $lockingProvider,
    ) {
    }

    public function tryAcquire(string $name): bool
    {
        $path = self::PREFIX . $name;
        try {
            $this->lockingProvider->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            return false;
        }
        $this->held[$path] = true;
        return true;
    }

    public function release(string $name): void
    {
        $path = self::PREFIX . $name;
        if (!isset($this->held[$path])) {
            return;
        }
        unset($this->held[$path]);
        try {
            $this->lockingProvider->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (\Throwable) {
            // Releasing a lock we no longer own is not actionable here; the
            // provider expires stale locks on its own.
        }
    }
}
