<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

use OCA\GraphMail\Graph\Token;

interface AccessTokenProvider
{
    /**
     * @throws \OCA\GraphMail\Graph\GraphAuthException
     */
    public function token(): Token;

    /**
     * Drop the cached token after an explicit rejection (401).
     */
    public function invalidate(): void;
}
