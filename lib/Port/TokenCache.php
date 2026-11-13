<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

use OCA\GraphMail\Graph\Token;

/**
 * Cross-process cache for access tokens. Keys must already be scoped by
 * tenant, client and credential generation.
 */
interface TokenCache
{
    public function get(string $key): ?Token;

    public function set(string $key, Token $token, int $ttlSeconds): void;

    public function delete(string $key): void;
}
