<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Graph\Token;
use OCA\GraphMail\Port\AccessTokenProvider;

final class FakeTokenProvider implements AccessTokenProvider
{
    public int $invalidations = 0;
    public int $calls = 0;

    public function __construct(
        public Token $token = new Token('access-token', 1_900_000_000),
    ) {
    }

    public function token(): Token
    {
        $this->calls++;
        return $this->token;
    }

    public function invalidate(): void
    {
        $this->invalidations++;
    }
}
