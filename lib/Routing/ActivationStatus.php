<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

final class ActivationStatus
{
    public function __construct(
        public readonly ActivationState $state,
        public readonly string $message,
        public readonly bool $configured,
        public readonly ?PreflightReport $preflight = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'message' => $this->message,
            'configured' => $this->configured,
            'preflight' => $this->preflight?->toArray() ?? [],
        ];
    }
}
