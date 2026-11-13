<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Graph\Credentials;
use OCA\GraphMail\Port\CredentialProvider;

final class FakeCredentials implements CredentialProvider
{
    public function __construct(
        public Credentials $value = new Credentials('tenant.example', 'client-id', 'secret', 'sender@example.invalid', 1),
    ) {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function credentials(): Credentials
    {
        return $this->value;
    }
}
