<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

/**
 * Sanitized credential failure. The message carries a stable reason code,
 * never a provider response body, token or secret.
 */
final class GraphAuthException extends \RuntimeException
{
    public function __construct(
        string $reasonCode,
        public readonly bool $temporary = false,
    ) {
        parent::__construct($reasonCode);
    }
}
