<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

interface Lock
{
    /**
     * Non-blocking: returns false when another process holds the lock.
     */
    public function tryAcquire(string $name): bool;

    public function release(string $name): void;
}
