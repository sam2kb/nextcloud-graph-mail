<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Adapter;

use OCA\GraphMail\Graph\Token;
use OCA\GraphMail\Port\TokenCache;
use OCP\ICache;
use OCP\ICacheFactory;

final class NextcloudTokenCache implements TokenCache
{
    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory)
    {
        $this->cache = $cacheFactory->createDistributed('graph_mail/token');
    }

    public function get(string $key): ?Token
    {
        $value = $this->cache->get($key);
        if (!is_array($value)) {
            return null;
        }
        $accessToken = $value['access_token'] ?? null;
        $expiresAt = $value['expires_at'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || !is_int($expiresAt)) {
            return null;
        }
        return new Token($accessToken, $expiresAt);
    }

    public function set(string $key, Token $token, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $this->cache->set($key, [
            'access_token' => $token->accessToken,
            'expires_at' => $token->expiresAt,
        ], $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->cache->remove($key);
    }
}
