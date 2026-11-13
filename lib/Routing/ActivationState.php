<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

enum ActivationState: string
{
    case SetupRequired = 'setup_required';
    case Inactive = 'inactive';
    case Checking = 'checking';
    case Active = 'active';
    case Degraded = 'degraded';
    case Conflict = 'conflict';

    public function isRoutingUsable(): bool
    {
        return $this === self::Active || $this === self::Degraded;
    }
}
