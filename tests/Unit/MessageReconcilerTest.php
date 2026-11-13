<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Unit;

use OCA\GraphMail\Transport\MessageReconciler;
use OCA\GraphMail\Transport\ReconciliationException;
use OCA\GraphMail\Transport\Rfc5322AddressParser;
use PHPUnit\Framework\TestCase;

final class MessageReconcilerTest extends TestCase
{
    private const MAILBOX = 'no-reply@example.invalid';
    private MessageReconciler $reconciler;

    protected function setUp(): void
    {
        $this->reconciler = new MessageReconciler(new Rfc5322AddressParser());
    }

    public function testBccOnlyMessageGetsHiddenRecipients(): void
    {
        $body = "hello\r\n";
        $raw = $this->mime([
            'From: Example Cloud <no-reply@example.invalid>',
            'Subject: synthetic',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
        ], $body);

        $result = $this->reconciler->reconcile($raw, ['hidden@example.invalid'], self::MAILBOX);

        self::assertStringContainsString("\r\nBcc: hidden@example.invalid\r\n", $result->rawMime);
        self::assertSame(['hidden@example.invalid'], $result->hiddenRecipients);
        self::assertStringEndsWith("\r\n\r\n" . $body, $result->rawMime);
    }

    public function testVisibleRecipientsStayAndOnlyHiddenAreAdded(): void
    {
        $raw = $this->mime([
            'From: Example Cloud <no-reply@example.invalid>',
            'To: "Doe, Jane" <jane@example.invalid>',
            'Cc: carol@example.invalid',
            'Subject: synthetic',
        ], 'body');

        $result = $this->reconciler->reconcile($raw, [
            'jane@example.invalid',
            'carol@example.invalid',
            'hidden1@example.invalid',
            'HIDDEN2@example.invalid',
        ], self::MAILBOX);

        self::assertSame(['hidden1@example.invalid', 'HIDDEN2@example.invalid'], $result->hiddenRecipients);
        self::assertStringContainsString('Bcc: hidden1@example.invalid, HIDDEN2@example.invalid', $result->rawMime);
    }

    public function testExistingBccHeaderIsReplacedNotLeaked(): void
    {
        $raw = $this->mime([
            'From: Example Cloud <no-reply@example.invalid>',
            'To: jane@example.invalid',
            'Bcc: leak@example.invalid',
            'Subject: synthetic',
        ], 'body');

        $result = $this->reconciler->reconcile($raw, ['jane@example.invalid', 'hidden@example.invalid'], self::MAILBOX);

        self::assertStringNotContainsString('leak@example.invalid', $result->rawMime);
        self::assertStringContainsString('Bcc: hidden@example.invalid', $result->rawMime);
    }

    public function testFromComparisonIsCaseInsensitiveForTheSenderMailbox(): void
    {
        $raw = $this->mime([
            'From: Example Cloud <No-Reply@Example.invalid>',
            'To: jane@example.invalid',
            'Subject: synthetic',
        ], 'body');

        $result = $this->reconciler->reconcile($raw, ['jane@example.invalid'], self::MAILBOX);
        self::assertSame([], $result->hiddenRecipients);
    }

    public function testVisibleRecipientOutsideEnvelopeIsRejected(): void
    {
        $raw = $this->mime([
            'From: Example Cloud <no-reply@example.invalid>',
            'To: attacker@example.invalid',
            'Subject: synthetic',
        ], 'body');

        $this->expectException(ReconciliationException::class);
        $this->expectExceptionMessage('visible_recipient_outside_envelope');
        $this->reconciler->reconcile($raw, ['jane@example.invalid'], self::MAILBOX);
    }

    public function testFromMustMatchConfiguredMailbox(): void
    {
        $raw = $this->mime([
            'From: Other <other@example.invalid>',
            'To: jane@example.invalid',
            'Subject: synthetic',
        ], 'body');

        $this->expectException(ReconciliationException::class);
        $this->expectExceptionMessage('from_mismatch');
        $this->reconciler->reconcile($raw, ['jane@example.invalid'], self::MAILBOX);
    }

    public function testFoldedToHeaderIsUnderstood(): void
    {
        $raw = $this->mime([
            'From: Example Cloud <no-reply@example.invalid>',
            "To: Jane Doe\r\n <jane@example.invalid>",
            'Subject: synthetic',
        ], 'body');

        $result = $this->reconciler->reconcile($raw, ['jane@example.invalid', 'hidden@example.invalid'], self::MAILBOX);
        self::assertSame(['hidden@example.invalid'], $result->hiddenRecipients);
    }

    public function testBodyBytesArePreserved(): void
    {
        $body = "--boundary\r\nContent-Type: text/plain\r\n\r\nline with .. dots\r\n--boundary--\r\n";
        $raw = $this->mime([
            'From: Example Cloud <no-reply@example.invalid>',
            'To: jane@example.invalid',
            'Subject: =?utf-8?Q?=E2=9C=93?=',
        ], $body);

        $result = $this->reconciler->reconcile($raw, ['jane@example.invalid'], self::MAILBOX);
        self::assertStringEndsWith("\r\n\r\n" . $body, $result->rawMime);
    }

    public function testLongBccFoldingRetainsEveryRecipientAndBody(): void
    {
        $recipients = [];
        for ($i = 0; $i < 60; $i++) {
            $recipients[] = 'hidden-' . $i . '@example.invalid';
        }
        $body = "binary\0body\r\nending\r\n";
        $raw = $this->mime(['From: ' . self::MAILBOX], $body);
        $result = $this->reconciler->reconcile($raw, $recipients, self::MAILBOX);
        [$headers, $resultBody] = explode("\r\n\r\n", $result->rawMime, 2);
        $headers = preg_replace('/\r\n[ \t]+/', ' ', $headers);
        preg_match('/(?:^|\r\n)Bcc: (.*)$/', $headers, $matches);
        $parsed = (new Rfc5322AddressParser())->parseList($matches[1]);
        self::assertSame($recipients, $parsed['addresses']);
        self::assertSame([], $parsed['invalid']);
        self::assertSame($body, $resultBody);
    }

    private function mime(array $headers, string $body): string
    {
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }
}
