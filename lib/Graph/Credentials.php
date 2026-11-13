<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

final class Credentials
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $mailbox,
        public readonly int $generation,
    ) {
    }

    /**
     * Material that must never be logged, but does scope token cache entries.
     */
    public function cacheScope(): string
    {
        return hash('sha256', implode("\0", [
            $this->tenantId,
            $this->clientId,
            (string)$this->generation,
            $this->clientSecret,
        ]));
    }
}
