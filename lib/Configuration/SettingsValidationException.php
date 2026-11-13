<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Configuration;

final class SettingsValidationException extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $reasonCode,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $detail : $reasonCode);
    }
}
