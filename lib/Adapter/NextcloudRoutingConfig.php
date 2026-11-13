<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Adapter;

use OCA\GraphMail\Port\RoutingConfig;
use OCA\GraphMail\Routing\RoutingSnapshot;
use OCP\IConfig;

final class NextcloudRoutingConfig implements RoutingConfig
{
    public const KEY_SMTP_MODE = 'mail_smtpmode';
    public const KEY_SENDMAIL_MODE = 'mail_sendmailmode';
    public const KEY_SEARCH_PATHS = 'binary_search_paths';

    public function __construct(
        private readonly IConfig $config,
    ) {
    }

    public function isReadOnly(): bool
    {
        return $this->config->getSystemValueBool('config_is_read_only', false);
    }

    public function current(): RoutingSnapshot
    {
        return new RoutingSnapshot(
            $this->readString(self::KEY_SMTP_MODE),
            $this->readString(self::KEY_SENDMAIL_MODE),
            $this->readStringList(self::KEY_SEARCH_PATHS),
        );
    }

    public function write(array $values): void
    {
        if ($values === []) {
            return;
        }
        $this->config->setSystemValues($values);
    }

    public function delete(string $key): void
    {
        if (!in_array($key, [self::KEY_SMTP_MODE, self::KEY_SENDMAIL_MODE, self::KEY_SEARCH_PATHS], true)) {
            throw new \InvalidArgumentException('Refusing to delete a key this app does not own');
        }
        $this->config->deleteSystemValue($key);
    }

    private function readString(string $key): ?string
    {
        $sentinel = new \stdClass();
        $value = $this->config->getSystemValue($key, $sentinel);
        return $value === $sentinel ? null : (is_string($value) ? $value : (string)$value);
    }

    /**
     * @return list<string>|null
     */
    private function readStringList(string $key): ?array
    {
        $sentinel = new \stdClass();
        $value = $this->config->getSystemValue($key, $sentinel);
        if ($value === $sentinel) {
            return null;
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, static fn ($entry): bool => is_string($entry)));
    }
}
