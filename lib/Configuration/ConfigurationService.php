<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Configuration;

use OCA\GraphMail\Graph\TokenProvider;
use OCA\GraphMail\Graph\GraphAuthException;

final class ConfigurationService
{
    public function __construct(private readonly AppSettings $settings, private readonly TokenProvider $tokens, private readonly \OCA\GraphMail\Port\Lock $lock)
    {
    }
    public function save(string $tenant, string $client, ?string $secret, string $mailbox): void
    {
        if (!$this->lock->tryAcquire('configuration')) {
            throw new SettingsValidationException('configuration_in_progress');
        }
        try {
            $candidate = $this->settings->prepare($tenant, $client, $secret, $mailbox);
            try {
                $this->tokens->validateCredentials($candidate);
            } catch (GraphAuthException) {
                throw new SettingsValidationException('credential_check_failed');
            }
            $this->settings->save($tenant, $client, $secret, $mailbox);
        } finally {
            $this->lock->release('configuration');
        }
    }
}
