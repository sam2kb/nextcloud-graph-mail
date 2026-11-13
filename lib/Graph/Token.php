<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

final class Token
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresAt,
    ) {
    }

    public function isUsableAt(int $now, int $safetyMarginSeconds = 120): bool
    {
        return $this->accessToken !== '' && $this->expiresAt - $safetyMarginSeconds > $now;
    }
}
