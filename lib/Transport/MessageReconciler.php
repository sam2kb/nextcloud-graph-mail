<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

/**
 * Reconciles Symfony's serialized MIME with the SMTP envelope.
 *
 * Symfony omits Bcc from the message it writes while still issuing RCPT for
 * hidden recipients. Graph has no separate envelope argument, so the hidden
 * recipients must be restored as a top-level Bcc header and the body must be
 * preserved byte for byte.
 */
final class MessageReconciler
{
    private const MAX_HEADER_BYTES = 65536;
    private const MAX_HEADERS = 200;

    public function __construct(
        private readonly Rfc5322AddressParser $addresses,
    ) {
    }

    public function reconcile(string $rawMime, array $envelopeRecipients, string $mailbox): ReconciledMessage
    {
        $separator = strpos($rawMime, "\r\n\r\n");
        if ($separator === false) {
            throw new ReconciliationException('malformed_message');
        }
        if ($separator > self::MAX_HEADER_BYTES) {
            throw new ReconciliationException('header_too_large');
        }
        $headerBlock = substr($rawMime, 0, $separator);
        $body = substr($rawMime, $separator + 4);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\r(?!\n)|(?<!\r)\n/', $headerBlock) === 1) {
            throw new ReconciliationException('malformed_header');
        }
        $headers = $this->parseHeaders($headerBlock);
        foreach ($headers as $header) {
            if (str_starts_with($header['name'], 'resent-')) {
                throw new ReconciliationException('unsupported_resent_headers');
            }
        }

        $mailboxNormalized = strtolower(trim($mailbox));
        $fromHeaders = $this->valuesOf($headers, 'from');
        if (count($fromHeaders) !== 1) {
            throw new ReconciliationException('missing_or_duplicate_from');
        }
        $from = $this->addresses->parseList($fromHeaders[0]);
        if (count($from['addresses']) !== 1 || $from['invalid'] !== []) {
            throw new ReconciliationException('invalid_from');
        }
        if (strtolower($from['addresses'][0]) !== $mailboxNormalized) {
            throw new ReconciliationException('from_mismatch', 'The From header must be the configured sender mailbox.');
        }

        $visible = [];
        foreach (['to', 'cc'] as $headerName) {
            foreach ($this->valuesOf($headers, $headerName) as $value) {
                $parsed = $this->addresses->parseList($value);
                if ($parsed['invalid'] !== []) {
                    throw new ReconciliationException('invalid_recipient_header');
                }
                foreach ($parsed['addresses'] as $address) {
                    $visible[] = $address;
                }
            }
        }

        $envelope = [];
        foreach ($envelopeRecipients as $recipient) {
            if (!$this->addresses->isValidAddress($recipient)) {
                throw new ReconciliationException('invalid_envelope_recipient');
            }
            $envelope[] = $recipient;
        }
        if ($envelope === []) {
            throw new ReconciliationException('empty_envelope');
        }

        $envelopeSet = [];
        foreach ($envelope as $recipient) {
            $envelopeSet[$this->addresses->normalize($recipient)] = true;
        }
        foreach ($visible as $recipient) {
            if (!isset($envelopeSet[$this->addresses->normalize($recipient)])) {
                // Delivering to a header recipient absent from the envelope
                // would add an unintended recipient. Refuse before any POST.
                throw new ReconciliationException('visible_recipient_outside_envelope');
            }
        }

        $visibleSet = [];
        foreach ($visible as $recipient) {
            $visibleSet[$this->addresses->normalize($recipient)] = true;
        }
        $hidden = [];
        $seenHidden = [];
        foreach ($envelope as $recipient) {
            $normalized = $this->addresses->normalize($recipient);
            if (isset($visibleSet[$normalized]) || isset($seenHidden[$normalized])) {
                continue;
            }
            $seenHidden[$normalized] = true;
            $hidden[] = $recipient;
        }

        $rebuiltHeaders = [];
        foreach ($headers as $header) {
            // Native output has no Bcc; drop any anyway so a hidden list is
            // never carried twice or leaked to visible recipients.
            if ($header['name'] === 'bcc') {
                continue;
            }
            $rebuiltHeaders[] = $header['raw'];
        }
        if ($hidden !== []) {
            $rebuiltHeaders[] = $this->formatBccHeader($hidden);
        }

        return new ReconciledMessage(
            implode("\r\n", $rebuiltHeaders) . "\r\n\r\n" . $body,
            $mailboxNormalized,
            $hidden,
        );
    }

    /**
     * @return list<array{name: string, raw: string, value: string}>
     */
    private function parseHeaders(string $headerBlock): array
    {
        $lines = explode("\r\n", $headerBlock);
        $headers = [];
        $current = null;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($current === null) {
                    throw new ReconciliationException('malformed_header_folding');
                }
                $current['raw'] .= "\r\n" . $line;
                $current['value'] .= ' ' . trim($line);
                continue;
            }
            if ($current !== null) {
                $headers[] = $current;
            }
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) {
                throw new ReconciliationException('malformed_header');
            }
            $name = substr($line, 0, $colon);
            if (preg_match('/^[!-9;-~]+$/D', $name) !== 1) {
                throw new ReconciliationException('malformed_header_name');
            }
            $current = [
                'name' => strtolower($name),
                'raw' => $line,
                'value' => trim(substr($line, $colon + 1)),
            ];
            if (count($headers) > self::MAX_HEADERS) {
                throw new ReconciliationException('too_many_headers');
            }
        }
        if ($current !== null) {
            $headers[] = $current;
        }
        return $headers;
    }

    /**
     * @param list<array{name: string, raw: string, value: string}> $headers
     * @return list<string>
     */
    private function valuesOf(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers as $header) {
            if ($header['name'] === $name) {
                $values[] = $header['value'];
            }
        }
        return $values;
    }

    /**
     * @param list<string> $hidden
     */
    private function formatBccHeader(array $hidden): string
    {
        $line = 'Bcc: ' . implode(', ', $hidden);
        if (strlen($line) <= 998) {
            return $line;
        }
        // Fold long lists at address boundaries, never inside an address.
        $chunks = [];
        $current = 'Bcc:';
        foreach ($hidden as $address) {
            $candidate = $current . ' ' . $address . ',';
            if (strlen($candidate) > 78 && $current !== 'Bcc:') {
                $chunks[] = $current;
                $current = ' ' . $address . ',';
            } else {
                $current = $candidate;
            }
        }
        $chunks[] = rtrim($current, ',');
        return implode("\r\n", $chunks);
    }
}
