<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

use OCA\GraphMail\Graph\Credentials;

interface CredentialProvider
{
    public function isConfigured(): bool;

    public function credentials(): Credentials;
}
