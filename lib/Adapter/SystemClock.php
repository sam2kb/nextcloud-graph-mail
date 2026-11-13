<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Adapter;

use OCA\GraphMail\Port\Clock;
use OCP\AppFramework\Utility\ITimeFactory;

final class SystemClock implements Clock
{
    public function __construct(
        private readonly ITimeFactory $timeFactory,
    ) {
    }

    public function now(): int
    {
        return $this->timeFactory->getTime();
    }

    public function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }
}
