<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Configuration;

use OCA\GraphMail\Graph\Credentials;
use OCA\GraphMail\Port\CredentialProvider;
use OCP\IAppConfig;
use OCP\Mail\IEmailValidator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * Operator configuration. The client secret is stored encrypted with the
 * instance secret and is never returned to the UI or written to logs.
 */
final class AppSettings implements CredentialProvider
{
    public const APP_ID = 'graph_mail';

    private const KEY_TENANT = 'tenant_id';
    private const KEY_CLIENT = 'client_id';
    private const KEY_MAILBOX = 'mailbox';
    private const KEY_SECRET = 'client_secret_ciphertext';
    private const KEY_GENERATION = 'credential_generation';
    private const KEY_LEDGER_KEY = 'ledger_key';

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly ICrypto $crypto,
        private readonly ISecureRandom $random,
        private readonly IEmailValidator $emailValidator,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->tenantId() !== ''
            && $this->clientId() !== ''
            && $this->mailbox() !== ''
            && $this->hasSecret();
    }

    public function tenantId(): string
    {
        return (string)($this->configuration()['tenant_id'] ?? $this->appConfig->getValueString(self::APP_ID, self::KEY_TENANT, ''));
    }

    public function clientId(): string
    {
        return (string)($this->configuration()['client_id'] ?? $this->appConfig->getValueString(self::APP_ID, self::KEY_CLIENT, ''));
    }

    public function mailbox(): string
    {
        return (string)($this->configuration()['mailbox'] ?? strtolower($this->appConfig->getValueString(self::APP_ID, self::KEY_MAILBOX, '')));
    }

    public function hasSecret(): bool
    {
        return ($this->configuration()['ciphertext'] ?? $this->appConfig->getValueString(self::APP_ID, self::KEY_SECRET, '')) !== '';
    }

    public function generation(): int
    {
        return (int)($this->configuration()['generation'] ?? $this->appConfig->getValueInt(self::APP_ID, self::KEY_GENERATION, 1));
    }

    public function credentials(): Credentials
    {
        if (!$this->isConfigured()) {
            throw new SettingsValidationException('not_configured');
        }
        return new Credentials(
            $this->tenantId(),
            $this->clientId(),
            $this->decryptSecret(),
            $this->mailbox(),
            $this->generation(),
        );
    }

    /**
     * A null secret preserves the stored value. Clearing requires clearSecret().
     */
    public function prepare(string $tenantId, string $clientId, ?string $secret, string $mailbox): Credentials
    {
        $tenantId = trim($tenantId);
        $clientId = trim($clientId);
        $mailbox = strtolower(trim($mailbox));

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,253}$/D', $tenantId) !== 1 || str_contains($tenantId, '..')) {
            throw new SettingsValidationException('invalid_tenant');
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $clientId) !== 1) {
            throw new SettingsValidationException('invalid_client_id');
        }
        if (!$this->emailValidator->isValid($mailbox)) {
            throw new SettingsValidationException('invalid_mailbox');
        }
        if ($secret !== null) {
            if ($secret === '' || strlen($secret) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
                throw new SettingsValidationException('invalid_secret');
            }
        }

        return new Credentials($tenantId, $clientId, $secret ?? $this->decryptSecret(), $mailbox, $this->generation() + 1);
    }

    public function save(string $tenantId, string $clientId, ?string $secret, string $mailbox): void
    {
        $candidate = $this->prepare($tenantId, $clientId, $secret, $mailbox);
        $configuration = [
            'tenant_id' => $candidate->tenantId, 'client_id' => $candidate->clientId,
            'mailbox' => $candidate->mailbox, 'generation' => $candidate->generation,
            'ciphertext' => $this->crypto->encrypt($candidate->clientSecret),
        ];
        $this->appConfig->setValueString(self::APP_ID, 'sender_configuration', json_encode($configuration, JSON_THROW_ON_ERROR), false, true);
    }

    public function clearSecret(): void
    {
        $record = $this->configuration();
        if ($record !== []) {
            $record['ciphertext'] = '';
            $record['generation'] = $this->generation() + 1;
            $this->appConfig->setValueString(self::APP_ID, 'sender_configuration', json_encode($record, JSON_THROW_ON_ERROR), false, true);
        }
        $this->appConfig->deleteKey(self::APP_ID, self::KEY_SECRET);
        $this->appConfig->setValueInt(self::APP_ID, self::KEY_GENERATION, $this->generation() + 1);
    }

    /**
     * Key for content-free submission fingerprints. Generated once and kept
     * as sensitive app config.
     */
    public function ledgerKey(): string
    {
        $key = $this->appConfig->getValueString(self::APP_ID, self::KEY_LEDGER_KEY, '', true);
        if ($key !== '') {
            return $key;
        }
        $key = $this->random->generate(32, ISecureRandom::CHAR_HUMAN_READABLE);
        $this->appConfig->setValueString(self::APP_ID, self::KEY_LEDGER_KEY, $key, false, true);
        return $key;
    }

    private function configuration(): array
    {
        $record = json_decode($this->appConfig->getValueString(self::APP_ID, 'sender_configuration', ''), true);
        return is_array($record) ? $record : [];
    }

    private function decryptSecret(): string
    {
        $ciphertext = $this->configuration()['ciphertext'] ?? $this->appConfig->getValueString(self::APP_ID, self::KEY_SECRET, '');
        if ($ciphertext === '') {
            throw new SettingsValidationException('missing_secret');
        }
        try {
            return $this->crypto->decrypt($ciphertext);
        } catch (\Exception) {
            throw new SettingsValidationException('unreadable_secret', 'The stored client secret cannot be decrypted with this instance.');
        }
    }
}
