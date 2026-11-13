<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Port\Lock;

final class FakeLock implements Lock
{
    public bool $available = true;
    public int $acquired = 0;
    public int $released = 0;

    public function tryAcquire(string $name): bool
    {
        if (!$this->available) {
            return false;
        }
        $this->acquired++;
        return true;
    }

    public function release(string $name): void
    {
        $this->released++;
    }
}
