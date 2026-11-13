<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

use OCA\GraphMail\Port\Clock;
use Psr\Log\LoggerInterface;

/**
 * The bounded subset of SMTP that Symfony's sendmail transport speaks over
 * stdin/stdout: greeting, HELO/EHLO, MAIL FROM, RCPT TO, DATA, RSET, NOOP and
 * QUIT. It is not a network server and advertises no extension it does not
 * implement.
 */
final class SmtpSession
{
    private string $hostname = 'localhost';
    /** Bytes already read from the transport but not yet consumed. */
    private string $pending = '';

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input,
        private $output,
        private readonly SubmissionHandler $handler,
        private readonly SubmissionLimits $limits,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(string $hostname): int
    {
        $this->hostname = preg_replace('/[^A-Za-z0-9.-]/', '', $hostname) ?: 'localhost';
        $deadline = $this->clock->now() + $this->limits->sessionSeconds;
        $this->write('220 ' . $this->hostname . " Graph Mail ESMTP\r\n");

        $sender = null;
        $recipients = [];
        $transactions = 0;

        while (true) {
            $line = $this->readLine($deadline);
            if ($line === null) {
                $this->write("421 4.4.2 Session timeout\r\n");
                return 1;
            }
            if ($line === '') {
                $this->write("500 5.5.2 Empty command\r\n");
                continue;
            }

            $parts = explode(' ', $line, 2);
            $command = strtoupper($parts[0]);
            $argument = isset($parts[1]) ? trim($parts[1]) : '';

            switch ($command) {
                case 'HELO':
                    if ($argument === '') {
                        $this->write("501 5.5.4 HELO requires a domain\r\n");
                        break;
                    }
                    $this->write('250 ' . $this->hostname . "\r\n");
                    break;

                case 'EHLO':
                    if ($argument === '') {
                        $this->write("501 5.5.4 EHLO requires a domain\r\n");
                        break;
                    }
                    // Only HELP is offered: no 8BITMIME, SMTPUTF8, DSN or AUTH.
                    $this->write('250-' . $this->hostname . "\r\n250 HELP\r\n");
                    break;

                case 'MAIL':
                    if ($sender !== null || $recipients !== []) {
                        $this->write("503 5.5.1 Nested MAIL command\r\n");
                        break;
                    }
                    if (preg_match('/^FROM:\s*<([^<>]*)>(.*)$/i', $argument, $matches) !== 1) {
                        $this->write("501 5.5.4 Syntax: MAIL FROM:<address>\r\n");
                        break;
                    }
                    if (trim($matches[2]) !== '') {
                        $this->write("555 5.5.4 Unsupported MAIL parameters\r\n");
                        break;
                    }
                    $sender = trim($matches[1]);
                    $this->write("250 2.1.0 Ok\r\n");
                    break;

                case 'RCPT':
                    if ($sender === null) {
                        $this->write("503 5.5.1 Need MAIL before RCPT\r\n");
                        break;
                    }
                    if (preg_match('/^TO:\s*<([^<>]*)>(.*)$/i', $argument, $matches) !== 1) {
                        $this->write("501 5.5.4 Syntax: RCPT TO:<address>\r\n");
                        break;
                    }
                    if (trim($matches[2]) !== '') {
                        $this->write("555 5.5.4 Unsupported RCPT parameters\r\n");
                        break;
                    }
                    if (count($recipients) >= $this->limits->maxRecipients) {
                        $this->write("452 4.5.3 Too many recipients\r\n");
                        break;
                    }
                    $recipients[] = trim($matches[1]);
                    $this->write("250 2.1.5 Ok\r\n");
                    break;

                case 'DATA':
                    if ($argument !== '') {
                        $this->write("501 5.5.4 DATA takes no parameters\r\n");
                        break;
                    }
                    if ($sender === null || $recipients === []) {
                        $this->write("503 5.5.1 Need MAIL and RCPT before DATA\r\n");
                        break;
                    }
                    $this->write("354 End data with <CR><LF>.<CR><LF>\r\n");
                    $data = $this->readData($deadline);
                    if ($data === null) {
                        $this->write("421 4.4.2 Session timeout\r\n");
                        return 1;
                    }
                    if ($data['oversized']) {
                        $this->write("552 5.3.4 Message exceeds the local size limit\r\n");
                        $sender = null;
                        $recipients = [];
                        break;
                    }
                    $message = new InboundMessage($sender, $recipients, $data['data']);
                    $sender = null;
                    $recipients = [];
                    $transactions++;

                    $result = $this->submit($message);
                    $this->write($this->replyFor($result));

                    if ($result->status === SubmissionStatus::TemporaryFailure) {
                        // Nothing more to do in this session: the caller decides.
                        return 0;
                    }
                    if ($transactions >= $this->limits->maxTransactions) {
                        $this->write("421 4.7.0 Too many transactions in one session\r\n");
                        return 0;
                    }
                    break;

                case 'RSET':
                    $sender = null;
                    $recipients = [];
                    $this->write("250 2.0.0 Ok\r\n");
                    break;

                case 'NOOP':
                    $this->write("250 2.0.0 Ok\r\n");
                    break;

                case 'QUIT':
                    $this->write("221 2.0.0 Bye\r\n");
                    return 0;

                default:
                    $this->write("500 5.5.1 Command unrecognised\r\n");
            }
        }
    }

    private function submit(InboundMessage $message): SubmissionResult
    {
        try {
            return $this->handler->handle($message);
        } catch (\Throwable $e) {
            // Never let an exception body reach the protocol stream.
            try {
                $this->logger->error('Graph Mail transport failed unexpectedly', [
                    'app' => 'graph_mail',
                    'exception' => $e::class,
                ]);
            } catch (\Throwable) {
                // The protocol remains usable when diagnostics are unavailable.
            }
            return SubmissionResult::temporary('internal_error');
        }
    }

    private function replyFor(SubmissionResult $result): string
    {
        $reason = preg_replace('/[^a-z0-9_]/', '_', $result->reason) ?: 'error';
        return match ($result->status) {
            SubmissionStatus::Accepted => '250 2.0.0 Ok: queued as ' . $result->correlationId . "\r\n",
            SubmissionStatus::TemporaryFailure => '451 4.4.1 ' . $reason . "\r\n",
            SubmissionStatus::PermanentFailure => ($reason === 'message_too_large'
                ? '552 5.3.4 ' . $reason . "\r\n"
                : '550 5.5.1 ' . $reason . "\r\n"),
        };
    }

    private function write(string $bytes): void
    {
        fwrite($this->output, $bytes);
        fflush($this->output);
    }

    private function readLine(int $deadline): ?string
    {
        while (($position = strpos($this->pending, "\r\n")) === false) {
            if (strlen($this->pending) > $this->limits->maxCommandBytes) {
                $this->discardLongLine($deadline);
                return '';
            }
            if (!$this->readMore($deadline)) {
                return null;
            }
        }
        $line = substr($this->pending, 0, $position);
        $this->pending = substr($this->pending, $position + 2);
        if ($position > $this->limits->maxCommandBytes) {
            return '';
        }
        if (str_contains($line, "\r") || str_contains($line, "\n")) {
            return null;
        }
        return $line;
    }

    /**
     * @return array{data: string, oversized: bool}|null null on timeout
     */
    private function readData(int $deadline): ?array
    {
        $oversized = false;
        while (true) {
            if (str_starts_with($this->pending, ".\r\n")) {
                $this->pending = substr($this->pending, 3);
                return ['data' => '', 'oversized' => false];
            }

            $position = strpos($this->pending, "\r\n.\r\n");
            if ($position !== false) {
                $data = substr($this->pending, 0, $position);
                $this->pending = substr($this->pending, $position + 5);
                if ($oversized || strlen($data) > $this->limits->maxMessageBytes) {
                    return ['data' => '', 'oversized' => true];
                }
                return ['data' => $this->unDotStuff($data), 'oversized' => false];
            }

            if (strlen($this->pending) > $this->limits->maxMessageBytes + 5) {
                $oversized = true;
                // Keep only enough bytes to complete a split terminator.
                $this->pending = substr($this->pending, -4);
            }

            if ($this->clock->now() >= $deadline) {
                return null;
            }
            if (!$this->readMore($deadline)) {
                return null;
            }
        }
    }

    private function readMore(int $deadline): bool
    {
        if (!$this->waitForReadable($deadline)) {
            return false;
        }
        $chunk = fread($this->input, 8192);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $this->pending .= $chunk;
        return true;
    }

    private function unDotStuff(string $data): string
    {
        if ($data === '') {
            return $data;
        }
        $lines = explode("\r\n", $data);
        foreach ($lines as $index => $line) {
            if ($line !== '' && $line[0] === '.') {
                $lines[$index] = substr($line, 1);
            }
        }
        return implode("\r\n", $lines);
    }

    private function waitForReadable(int $deadline): bool
    {
        while (true) {
            $remaining = $deadline - $this->clock->now();
            if ($remaining < 1) {
                return false;
            }
            $read = [$this->input];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, min(1, $remaining));
            if ($selected === false) {
                return false;
            }
            if ($selected > 0) {
                return true;
            }
        }
    }

    private function discardLongLine(int $deadline): void
    {
        while (($position = strpos($this->pending, "\r\n")) === false) {
            $this->pending = substr($this->pending, -1);
            if (!$this->readMore($deadline)) {
                $this->pending = '';
                return;
            }
        }
        $this->pending = substr($this->pending, $position + 2);
    }
}
