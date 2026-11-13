<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

use OCA\GraphMail\Port\AccessTokenProvider;
use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Port\CredentialProvider;
use OCA\GraphMail\Port\HttpClient;
use OCA\GraphMail\Port\HttpRequest;
use OCA\GraphMail\Port\HttpTransportException;
use OCA\GraphMail\Port\Lock;
use OCA\GraphMail\Port\TokenCache;
use Psr\Log\LoggerInterface;

/**
 * Client-credentials token acquisition for a single tenant, using the fixed
 * global-cloud token endpoint. No URL, scope or grant is operator-supplied.
 */
final class TokenProvider implements AccessTokenProvider
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const SCOPE = 'https://graph.microsoft.com/.default';
    private const TIMEOUT_SECONDS = 15;
    private const SAFETY_MARGIN_SECONDS = 120;
    private const MAX_TOKEN_LENGTH = 16384;
    private const CACHE_KEY_PREFIX = 'tok_';

    public function __construct(
        private readonly HttpClient $http,
        private readonly TokenCache $cache,
        private readonly Lock $lock,
        private readonly Clock $clock,
        private readonly CredentialProvider $credentials,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validateCredentials(Credentials $credentials): void
    {
        $token = $this->requestToken($credentials);
        $this->cache->set($this->cacheKey($credentials), $token, max(0, $token->expiresAt - $this->clock->now() - self::SAFETY_MARGIN_SECONDS));
    }

    public function token(): Token
    {
        try {
            $credentials = $this->credentials->credentials();
        } catch (\OCA\GraphMail\Configuration\SettingsValidationException) {
            throw new GraphAuthException('token_credentials_unavailable', false);
        }
        $key = $this->cacheKey($credentials);

        $cached = $this->cache->get($key);
        if ($cached !== null && $cached->isUsableAt($this->clock->now(), self::SAFETY_MARGIN_SECONDS)) {
            return $cached;
        }

        $haveLock = $this->lock->tryAcquire('token_' . $key);
        if (!$haveLock) {
            // Another process is refreshing. Wait briefly for its result rather
            // than stampeding Microsoft with a second request.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this->clock->sleep(1);
                $cached = $this->cache->get($key);
                if ($cached !== null && $cached->isUsableAt($this->clock->now(), self::SAFETY_MARGIN_SECONDS)) {
                    return $cached;
                }
            }
            throw new GraphAuthException('token_refresh_busy', true);
        }

        try {
            $cached = $this->cache->get($key);
            if ($cached !== null && $cached->isUsableAt($this->clock->now(), self::SAFETY_MARGIN_SECONDS)) {
                return $cached;
            }
            $token = $this->requestToken($credentials);
            $ttl = $token->expiresAt - $this->clock->now() - self::SAFETY_MARGIN_SECONDS;
            $this->cache->set($key, $token, $ttl);
            return $token;
        } finally {
            if ($haveLock) {
                $this->lock->release('token_' . $key);
            }
        }
    }

    public function invalidate(): void
    {
        try {
            $this->cache->delete($this->cacheKey($this->credentials->credentials()));
        } catch (\Throwable $e) {
            $this->logger->warning('Graph Mail could not clear the cached access token', [
                'app' => 'graph_mail',
                'exception' => $e::class,
            ]);
        }
    }

    private function requestToken(Credentials $credentials): Token
    {
        $url = sprintf(self::TOKEN_URL, rawurlencode($credentials->tenantId));
        try {
            $response = $this->http->send(new HttpRequest(
                'POST',
                $url,
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                http_build_query([
                'client_id' => $credentials->clientId,
                'scope' => self::SCOPE,
                'client_secret' => $credentials->clientSecret,
                'grant_type' => 'client_credentials',
            ], '', '&', PHP_QUERY_RFC3986),
                self::TIMEOUT_SECONDS,
            ));
        } catch (HttpTransportException) {
            throw new GraphAuthException('token_unavailable', true);
        }

        $now = $this->clock->now();
        if ($response->status !== 200) {
            $code = $this->sanitizeErrorCode($response->body);
            $temporary = $response->status === 429 || $response->status >= 500;
            $this->logger->error('Graph Mail token request was rejected', [
                'app' => 'graph_mail',
                'status' => $response->status,
                'reason' => $code,
            ]);
            throw new GraphAuthException('token_' . ($temporary ? 'unavailable' : 'rejected') . ($code !== '' ? ':' . $code : ''), $temporary);
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload)) {
            throw new GraphAuthException('token_malformed', true);
        }
        $tokenType = $payload['token_type'] ?? null;
        $accessToken = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;
        if (!is_string($tokenType) || strtolower($tokenType) !== 'bearer'
            || !is_string($accessToken) || $accessToken === '' || strlen($accessToken) > self::MAX_TOKEN_LENGTH
            || !is_numeric($expiresIn)) {
            throw new GraphAuthException('token_malformed', true);
        }
        $expiresIn = (int)$expiresIn;
        if ($expiresIn < 60 || $expiresIn > 86400) {
            throw new GraphAuthException('token_expiry_out_of_range', true);
        }

        return new Token($accessToken, $now + $expiresIn);
    }

    private function cacheKey(Credentials $credentials): string
    {
        return self::CACHE_KEY_PREFIX . $credentials->cacheScope();
    }

    /**
     * Extract only a short machine code from an OAuth error body. Provider
     * bodies can echo request data, so they are never stored or logged.
     */
    private function sanitizeErrorCode(string $body): string
    {
        $payload = json_decode($body, true);
        $code = is_array($payload) ? ($payload['error'] ?? '') : '';
        if (!is_string($code)) {
            return '';
        }
        $code = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $code) ?? '');
        return substr($code, 0, 64);
    }
}
