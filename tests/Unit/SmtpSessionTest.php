<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Unit;

use OCA\GraphMail\Tests\Support\FakeClock;
use OCA\GraphMail\Tests\Support\FakeSubmissionHandler;
use OCA\GraphMail\Transport\SmtpSession;
use OCA\GraphMail\Transport\SubmissionLimits;
use OCA\GraphMail\Transport\SubmissionResult;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SmtpSessionTest extends TestCase
{
    public function testFullTransactionIsAcceptedOnce(): void
    {
        $script = "HELO relay.example\r\n"
            . "MAIL FROM:<no-reply@example.invalid>\r\n"
            . "RCPT TO:<jane@example.invalid>\r\n"
            . "RCPT TO:<hidden@example.invalid>\r\n"
            . "DATA\r\n"
            . "Subject: synthetic\r\n"
            . "\r\n"
            . "..leading dot line\r\n"
            . ".\r\n"
            . "QUIT\r\n";

        [$code, $conversation, $handler] = $this->runSession($script);

        self::assertSame(0, $code);
        self::assertStringStartsWith('220 ', $conversation);
        self::assertStringContainsString("250 2.0.0 Ok: queued as corr123\r\n", $conversation);
        self::assertStringContainsString("221 2.0.0 Bye\r\n", $conversation);
        self::assertCount(1, $handler->messages);
        self::assertSame('no-reply@example.invalid', $handler->messages[0]->from);
        self::assertSame(['jane@example.invalid', 'hidden@example.invalid'], $handler->messages[0]->recipients);
        self::assertStringEndsWith("Subject: synthetic\r\n\r\n.leading dot line", $handler->messages[0]->rawMime);
    }

    public function testDataBeforeMailIsRefused(): void
    {
        [$code, $conversation] = $this->runSession("DATA\r\nQUIT\r\n");
        self::assertSame(0, $code);
        self::assertStringContainsString("503 5.5.1", $conversation);
    }

    public function testUnsupportedRcptParametersAreRefused(): void
    {
        $script = "MAIL FROM:<no-reply@example.invalid>\r\nRCPT TO:<jane@example.invalid> NOTIFY=SUCCESS\r\nQUIT\r\n";
        [, $conversation] = $this->runSession($script);
        self::assertStringContainsString("555 5.5.4", $conversation);
    }

    public function testPermanentFailureMapsTo550(): void
    {
        $script = "MAIL FROM:<no-reply@example.invalid>\r\nRCPT TO:<jane@example.invalid>\r\nDATA\r\nX: y\r\n.\r\nQUIT\r\n";
        [, $conversation] = $this->runSession($script, SubmissionResult::permanent('from_mismatch'));
        self::assertStringContainsString("550 5.5.1 from_mismatch\r\n", $conversation);
    }

    public function testTemporaryFailureMapsTo451(): void
    {
        $script = "MAIL FROM:<no-reply@example.invalid>\r\nRCPT TO:<jane@example.invalid>\r\nDATA\r\nX: y\r\n.\r\n";
        [, $conversation] = $this->runSession($script, SubmissionResult::temporary('throttled'));
        self::assertStringContainsString("451 4.4.1 throttled\r\n", $conversation);
    }

    public function testOversizedMessageIsRejectedBeforeSubmission(): void
    {
        $script = "MAIL FROM:<no-reply@example.invalid>\r\nRCPT TO:<jane@example.invalid>\r\nDATA\r\n"
            . str_repeat('A', 200) . "\r\n.\r\nQUIT\r\n";
        [, $conversation, $handler] = $this->runSession($script, null, new SubmissionLimits(maxMessageBytes: 50));
        self::assertStringContainsString("552 5.3.4", $conversation);
        self::assertCount(0, $handler->messages);
    }

    public function testTooManyRecipientsIsRefused(): void
    {
        $script = "MAIL FROM:<no-reply@example.invalid>\r\n"
            . "RCPT TO:<a@example.invalid>\r\n"
            . "RCPT TO:<b@example.invalid>\r\n"
            . "QUIT\r\n";
        [, $conversation] = $this->runSession($script, null, new SubmissionLimits(maxRecipients: 1));
        self::assertStringContainsString("452 4.5.3", $conversation);
    }

    /**
     * @return array{0: int, 1: string, 2: FakeSubmissionHandler}
     */
    public function testBufferedOversizedCommandIsRejected(): void
    {
        [, $conversation] = $this->runSession("NOOP " . str_repeat('X', 128) . "\r\nQUIT\r\n", null, new SubmissionLimits(maxCommandBytes: 64));
        self::assertStringNotContainsString("250 2.0.0 Ok", $conversation);
        self::assertStringContainsString('221 ', $conversation);
    }

    private function runSession(string $script, ?SubmissionResult $result = null, ?SubmissionLimits $limits = null): array
    {
        $input = fopen('php://temp', 'w+');
        fwrite($input, $script);
        rewind($input);
        $output = fopen('php://temp', 'w+');

        $handler = new FakeSubmissionHandler($result);
        $session = new SmtpSession(
            $input,
            $output,
            $handler,
            $limits ?? new SubmissionLimits(maxMessageBytes: 100_000),
            new FakeClock(),
            new NullLogger(),
        );
        $code = $session->run('relay.example');

        rewind($output);
        $conversation = (string)stream_get_contents($output);
        return [$code, $conversation, $handler];
    }
}
