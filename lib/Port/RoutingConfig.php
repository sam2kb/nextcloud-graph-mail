<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

use OCA\GraphMail\Routing\RoutingSnapshot;

/**
 * The only system configuration this app is allowed to read or own.
 * Implementations must expose presence/absence, not merged defaults.
 */
interface RoutingConfig
{
    public function isReadOnly(): bool;

    public function current(): RoutingSnapshot;

    /**
     * @param array<string, string> $values
     */
    public function write(array $values): void;

    public function delete(string $key): void;
}
