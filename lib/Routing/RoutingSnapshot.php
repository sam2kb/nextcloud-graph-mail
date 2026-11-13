<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

/**
 * Exact presence-aware view of the system config keys this app may touch.
 * A null value means the key is absent, which is not the same as a default.
 */
final class RoutingSnapshot
{
    /**
     * @param list<string>|null $searchPaths
     */
    public function __construct(
        public readonly ?string $smtpMode,
        public readonly ?string $sendmailMode,
        public readonly ?array $searchPaths,
    ) {
    }

    /**
     * @return array{smtp_mode: ?string, sendmail_mode: ?string, search_paths: ?list<string>}
     */
    public function toArray(): array
    {
        return [
            'smtp_mode' => $this->smtpMode,
            'sendmail_mode' => $this->sendmailMode,
            'search_paths' => $this->searchPaths,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $paths = $data['search_paths'] ?? null;
        if (is_array($paths)) {
            $paths = array_values(array_filter($paths, static fn ($p): bool => is_string($p)));
        } else {
            $paths = null;
        }
        return new self(
            isset($data['smtp_mode']) && is_string($data['smtp_mode']) ? $data['smtp_mode'] : null,
            isset($data['sendmail_mode']) && is_string($data['sendmail_mode']) ? $data['sendmail_mode'] : null,
            $paths,
        );
    }

    /**
     * @param list<string>|null $paths
     */
    public function withSearchPaths(?array $paths): self
    {
        return new self($this->smtpMode, $this->sendmailMode, $paths);
    }

    public function hasSearchPath(string $directory): bool
    {
        return $this->searchPaths !== null && in_array($directory, $this->searchPaths, true);
    }

    public function withoutSearchPath(string $directory): self
    {
        if ($this->searchPaths === null) {
            return $this;
        }
        $filtered = array_values(array_filter(
            $this->searchPaths,
            static fn (string $path): bool => $path !== $directory,
        ));
        return $this->withSearchPaths($filtered === [] ? [] : $filtered);
    }

    public function equals(self $other): bool
    {
        return $this->smtpMode === $other->smtpMode
            && $this->sendmailMode === $other->sendmailMode
            && $this->searchPaths === $other->searchPaths;
    }

    public function isSendmailSmtp(): bool
    {
        return $this->smtpMode === 'sendmail' && $this->sendmailMode === 'smtp';
    }
}
