<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

/**
 * Bounds for one stdio SMTP session. The message cap is enforced before any
 * HTTP request; GraphSender re-checks the encoded request size.
 */
final class SubmissionLimits
{
    public function __construct(
        public readonly int $maxCommandBytes = 2048,
        public readonly int $maxMessageBytes = 3_000_000,
        public readonly int $maxRecipients = 100,
        public readonly int $maxTransactions = 50,
        public readonly int $sessionSeconds = 120,
    ) {
    }
}
