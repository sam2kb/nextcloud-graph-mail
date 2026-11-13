<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

final class ReconciledMessage
{
    /**
     * @param list<string> $hiddenRecipients
     */
    public function __construct(
        public readonly string $rawMime,
        public readonly string $mailbox,
        public readonly array $hiddenRecipients,
    ) {
    }
}
