<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Unit;

use OCA\GraphMail\Graph\GraphAuthException;
use OCA\GraphMail\Graph\TokenProvider;
use OCA\GraphMail\Port\HttpResponse;
use OCA\GraphMail\Tests\Support\FakeClock;
use OCA\GraphMail\Tests\Support\FakeCredentials;
use OCA\GraphMail\Tests\Support\FakeHttpClient;
use OCA\GraphMail\Tests\Support\FakeLock;
use OCA\GraphMail\Tests\Support\FakeTokenCache;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TokenProviderTest extends TestCase
{
    public function testTokenIsRequestedOnceAndCached(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, [], json_encode([
            'token_type' => 'Bearer',
            'access_token' => 'token-value',
            'expires_in' => 3600,
        ]) ?: ''));
        $cache = new FakeTokenCache();
        $provider = $this->provider($http, $cache);

        $first = $provider->token();
        $second = $provider->token();

        self::assertSame('token-value', $first->accessToken);
        self::assertSame($first->expiresAt, $second->expiresAt);
        self::assertCount(1, $http->requests);
        self::assertStringContainsString('grant_type=client_credentials', $http->requests[0]->body);
        self::assertStringContainsString('scope=https%3A%2F%2Fgraph.microsoft.com%2F.default', $http->requests[0]->body);
        self::assertStringNotContainsString('Bearer', $http->requests[0]->body);
    }

    public function testRejectedClientIsReportedWithoutProviderBody(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(401, [], '{"error":"invalid_client","error_description":"secret for client abc leaked"}'));
        $provider = $this->provider($http, new FakeTokenCache());

        try {
            $provider->token();
            self::fail('Expected GraphAuthException');
        } catch (GraphAuthException $e) {
            self::assertSame('token_rejected:invalid_client', $e->getMessage());
            self::assertFalse($e->temporary);
            self::assertStringNotContainsString('leaked', $e->getMessage());
        }
    }

    public function testServerErrorIsTemporary(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(503, [], ''));
        $provider = $this->provider($http, new FakeTokenCache());

        $this->expectException(GraphAuthException::class);
        $this->expectExceptionMessage('token_unavailable');
        try {
            $provider->token();
        } catch (GraphAuthException $e) {
            self::assertTrue($e->temporary);
            throw $e;
        }
    }

    public function testMalformedTokenResponseIsRejected(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, [], '{"token_type":"Bearer"}'));
        $provider = $this->provider($http, new FakeTokenCache());

        $this->expectException(GraphAuthException::class);
        $this->expectExceptionMessage('token_malformed');
        $provider->token();
    }

    public function testInvalidateDropsCachedToken(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(200, [], '{"token_type":"Bearer","access_token":"one","expires_in":3600}'));
        $http->queue(new HttpResponse(200, [], '{"token_type":"Bearer","access_token":"two","expires_in":3600}'));
        $cache = new FakeTokenCache();
        $provider = $this->provider($http, $cache);

        self::assertSame('one', $provider->token()->accessToken);
        $provider->invalidate();
        self::assertSame('two', $provider->token()->accessToken);
        self::assertCount(2, $http->requests);
    }

    public function testRefreshDoesNotProceedWithoutOwningLock(): void
    {
        $http = new FakeHttpClient();
        $lock = new FakeLock();
        $lock->available = false;
        $provider = new TokenProvider($http, new FakeTokenCache(), $lock, new FakeClock(), new FakeCredentials(), new NullLogger());
        try {
            $provider->token();
            self::fail('Expected busy refresh');
        } catch (GraphAuthException $e) {
            self::assertSame('token_refresh_busy', $e->getMessage());
            self::assertTrue($e->temporary);
        }
        self::assertCount(0, $http->requests);
        self::assertSame(0, $lock->released);
    }

    private function provider(FakeHttpClient $http, FakeTokenCache $cache): TokenProvider
    {
        return new TokenProvider(
            $http,
            $cache,
            new FakeLock(),
            new FakeClock(),
            new FakeCredentials(),
            new NullLogger(),
        );
    }
}
