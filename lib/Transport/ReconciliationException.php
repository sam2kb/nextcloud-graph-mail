<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

/**
 * The message cannot be submitted safely. The reason is a stable code and is
 * safe to show in an SMTP reply.
 */
final class ReconciliationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $detail = '',
    ) {
        parent::__construct($reasonCode);
    }
}
