<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

final class PreflightCheck
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $id,
        public readonly string $severity,
        public readonly string $detail = '',
    ) {
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'severity' => $this->severity, 'detail' => $this->detail];
    }
}
