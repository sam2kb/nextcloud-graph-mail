<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

final class GraphSendResult
{
    public function __construct(
        public readonly GraphOutcome $outcome,
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly string $requestId = '',
        public readonly int $retryAfterSeconds = 0,
    ) {
    }

    public static function accepted(string $requestId): self
    {
        return new self(GraphOutcome::Accepted, 'accepted', 202, $requestId);
    }

    public static function rejected(string $reason, ?int $httpStatus): self
    {
        return new self(GraphOutcome::Rejected, $reason, $httpStatus);
    }

    public static function temporary(string $reason, int $retryAfter): self
    {
        return new self(GraphOutcome::Temporary, $reason, null, '', $retryAfter);
    }

    public static function unknown(string $reason, ?int $httpStatus = null): self
    {
        return new self(GraphOutcome::Unknown, $reason, $httpStatus);
    }
}
