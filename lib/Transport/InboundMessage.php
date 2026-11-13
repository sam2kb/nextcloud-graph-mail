<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

final class InboundMessage
{
    /**
     * @param list<string> $recipients envelope recipients in RCPT order
     */
    public function __construct(
        public readonly string $from,
        public readonly array $recipients,
        public readonly string $rawMime,
    ) {
    }
}
