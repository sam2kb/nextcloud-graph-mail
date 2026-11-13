<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

final class PreflightReport
{
    /**
     * @param list<PreflightCheck> $checks
     */
    public function __construct(
        public readonly array $checks,
    ) {
    }

    public function canActivate(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->severity === PreflightCheck::FAIL) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    public function failureIds(): array
    {
        $ids = [];
        foreach ($this->checks as $check) {
            if ($check->severity === PreflightCheck::FAIL) {
                $ids[] = $check->id;
            }
        }
        return $ids;
    }

    /**
     * @return list<array{id: string, severity: string, detail: string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (PreflightCheck $check): array => $check->toArray(), $this->checks);
    }
}
