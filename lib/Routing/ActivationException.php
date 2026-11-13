<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

final class ActivationException extends \RuntimeException
{
    /**
     * @param list<string> $details
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $details = [],
    ) {
        parent::__construct($reasonCode);
    }
}
