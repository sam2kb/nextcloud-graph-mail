<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Unit;

use OCA\GraphMail\Graph\GraphSender;
use OCA\GraphMail\Port\HttpResponse;
use OCA\GraphMail\Port\HttpTransportException;
use OCA\GraphMail\Tests\Support\FakeClock;
use OCA\GraphMail\Tests\Support\FakeHttpClient;
use OCA\GraphMail\Tests\Support\FakeTokenProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GraphSenderTest extends TestCase
{
    private const MIME = "From: no-reply@example.invalid\r\nTo: jane@example.invalid\r\n\r\nbody";

    public function test202IsAcceptanceWithRequestId(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(202, ['request-id' => 'abc-123'], ''));
        $result = $this->sender($http)->send(self::MIME, 'sender@example.invalid', 1_800_000_100);

        self::assertSame('accepted', $result->outcome->value);
        self::assertSame('abc-123', $result->requestId);
        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/v1.0/users/sender%40example.invalid/sendMail', $http->requests[0]->url);
        self::assertSame(base64_encode(self::MIME), $http->requests[0]->body);
        self::assertSame('Bearer access-token', $http->requests[0]->headers['Authorization']);
        self::assertSame('text/plain', $http->requests[0]->headers['Content-Type']);
    }

    public function test403IsPermanentAndActionable(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(403, [], '{"error":{"code":"ErrorAccessDenied"}}'));
        $result = $this->sender($http)->send(self::MIME, 'sender@example.invalid', 1_800_000_100);
        self::assertSame('rejected', $result->outcome->value);
        self::assertSame('permission_denied', $result->reason);
    }

    public function test500IsUnknownAndNotRetried(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(503, [], ''));
        $result = $this->sender($http)->send(self::MIME, 'sender@example.invalid', 1_800_000_100);
        self::assertSame('unknown', $result->outcome->value);
        self::assertSame('provider_error', $result->reason);
        self::assertCount(1, $http->requests);
    }

    public function testTransportExceptionIsUnknown(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpTransportException('connection reset'));
        $result = $this->sender($http)->send(self::MIME, 'sender@example.invalid', 1_800_000_100);
        self::assertSame('unknown', $result->outcome->value);
        self::assertSame('transport_outcome_unknown', $result->reason);
    }

    public function test401RetriesOnceWithFreshToken(): void
    {
        $http = new FakeHttpClient();
        $tokens = new FakeTokenProvider();
        $http->queue(new HttpResponse(401, [], ''));
        $http->queue(new HttpResponse(202, [], ''));
        $result = (new GraphSender($http, $tokens, new FakeClock(), new NullLogger()))
            ->send(self::MIME, 'sender@example.invalid', 1_800_000_100);

        self::assertSame('accepted', $result->outcome->value);
        self::assertSame(1, $tokens->invalidations);
        self::assertCount(2, $http->requests);
    }

    public function test429HonoursRetryAfterWithoutSleepingInRequest(): void
    {
        $http = new FakeHttpClient();
        $clock = new FakeClock();
        $http->queue(new HttpResponse(429, ['retry-after' => '120'], ''));
        $result = (new GraphSender($http, new FakeTokenProvider(), $clock, new NullLogger()))
            ->send(self::MIME, 'sender@example.invalid', 1_800_000_100);

        self::assertSame('temporary', $result->outcome->value);
        self::assertSame(120, $result->retryAfterSeconds);
        self::assertSame([], $clock->sleeps);
    }

    public function testOversizedMessageIsRejectedBeforeAnyRequest(): void
    {
        $http = new FakeHttpClient();
        $result = $this->sender($http)->send(
            str_repeat('A', GraphSender::MAX_RAW_MESSAGE_BYTES + 1),
            'sender@example.invalid',
            1_800_000_100,
        );
        self::assertSame('message_too_large', $result->reason);
        self::assertCount(0, $http->requests);
    }

    public function testExpiredDeadlineDoesNotStartRequest(): void
    {
        $http = new FakeHttpClient();
        $clock = new FakeClock();
        $result = (new GraphSender($http, new FakeTokenProvider(), $clock, new NullLogger()))
            ->send(self::MIME, 'sender@example.invalid', $clock->now());
        self::assertSame('temporary', $result->outcome->value);
        self::assertSame('deadline_exceeded', $result->reason);
        self::assertCount(0, $http->requests);
    }

    public function testRepeatedShortThrottleRemainsTemporary(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(429, ['retry-after' => '1'], ''));
        $http->queue(new HttpResponse(429, ['retry-after' => '1'], ''));
        $result = $this->sender($http)->send(self::MIME, 'sender@example.invalid', 1_800_000_100);
        self::assertSame('temporary', $result->outcome->value);
        self::assertSame('throttled', $result->reason);
        self::assertCount(2, $http->requests);
    }

    public function testExpiredDeadlineDoesNotAcquireToken(): void
    {
        $tokens = new FakeTokenProvider();
        $clock = new FakeClock();
        (new GraphSender(new FakeHttpClient(), $tokens, $clock, new NullLogger()))->send(self::MIME, 'sender@example.invalid', $clock->now());
        self::assertSame(0, $tokens->calls);
    }

    private function sender(FakeHttpClient $http): GraphSender
    {
        return new GraphSender($http, new FakeTokenProvider(), new FakeClock(), new NullLogger());
    }
}
