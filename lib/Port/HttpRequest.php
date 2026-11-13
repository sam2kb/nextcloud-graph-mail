<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

/**
 * A deliberately narrow HTTP request. Production code only uses fixed
 * first-party endpoints; no URL is ever taken from message content.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $timeoutSeconds,
    ) {
    }
}
