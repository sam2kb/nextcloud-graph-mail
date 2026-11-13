<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Port\Clock;

final class FakeClock implements Clock
{
    /** @var list<int> */
    public array $sleeps = [];

    public function __construct(private int $now = 1_800_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function sleep(int $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->now += $seconds;
    }
}
