<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Graph\Token;
use OCA\GraphMail\Port\TokenCache;

final class FakeTokenCache implements TokenCache
{
    /** @var array<string, Token> */
    public array $values = [];

    public function get(string $key): ?Token
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, Token $token, int $ttlSeconds): void
    {
        if ($ttlSeconds > 0) {
            $this->values[$key] = $token;
        }
    }

    public function delete(string $key): void
    {
        unset($this->values[$key]);
    }
}
