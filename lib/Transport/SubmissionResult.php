<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

final class SubmissionResult
{
    public function __construct(
        public readonly SubmissionStatus $status,
        public readonly string $reason,
        public readonly string $correlationId = '',
    ) {
    }

    public static function accepted(string $correlationId): self
    {
        return new self(SubmissionStatus::Accepted, 'accepted', $correlationId);
    }

    public static function temporary(string $reason): self
    {
        return new self(SubmissionStatus::TemporaryFailure, $reason);
    }

    public static function permanent(string $reason): self
    {
        return new self(SubmissionStatus::PermanentFailure, $reason);
    }
}
