<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

use OCA\GraphMail\Port\AccessTokenProvider;
use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Port\HttpClient;
use OCA\GraphMail\Port\HttpRequest;
use OCA\GraphMail\Port\HttpTransportException;
use Psr\Log\LoggerInterface;

/**
 * Submits one complete, already reconciled RFC 5322 message.
 *
 * The MIME body is sent unchanged and base64-encoded, as documented for
 * /users/{id}/sendMail. A 202 is acceptance at the submission boundary only.
 */
final class GraphSender
{
    public const GRAPH_ORIGIN = 'https://graph.microsoft.com';
    /** Microsoft documents a 4 MB write-request ceiling; base64 is the body. */
    public const MAX_BASE64_BYTES = 4_000_000;
    /** ceil(raw / 3) * 4 <= 4_000_000 keeps the encoded body under the ceiling. */
    public const MAX_RAW_MESSAGE_BYTES = 3_000_000;
    private const MAX_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly HttpClient $http,
        private readonly AccessTokenProvider $tokens,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $rawMime, string $mailbox, int $deadline): GraphSendResult
    {
        $rawLength = strlen($rawMime);
        if ($rawLength > self::MAX_RAW_MESSAGE_BYTES) {
            return GraphSendResult::rejected('message_too_large', 413);
        }
        $encoded = base64_encode($rawMime);
        if (strlen($encoded) > self::MAX_BASE64_BYTES) {
            return GraphSendResult::rejected('message_too_large', 413);
        }

        $url = self::GRAPH_ORIGIN . '/v1.0/users/' . rawurlencode($mailbox) . '/sendMail';
        $requestId = '';

        $refreshed = false;
        $retriedThrottle = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->remainingSeconds($deadline) < 1) {
                return GraphSendResult::temporary('deadline_exceeded', 0);
            }
            try {
                $token = $this->tokens->token();
            } catch (GraphAuthException $e) {
                return $e->temporary
                    ? GraphSendResult::temporary('authentication_unavailable', 0)
                    : GraphSendResult::rejected($e->getMessage(), null);
            }

            $timeout = $this->remainingSeconds($deadline);
            if ($timeout < 1) {
                return GraphSendResult::temporary('deadline_exceeded', 0);
            }

            try {
                $response = $this->http->send(new HttpRequest(
                    'POST',
                    $url,
                    [
                        'Authorization' => 'Bearer ' . $token->accessToken,
                        'Content-Type' => 'text/plain',
                    ],
                    $encoded,
                    $timeout,
                ));
            } catch (HttpTransportException) {
                // The request may have reached Microsoft. Never replay here.
                return GraphSendResult::unknown('transport_outcome_unknown');
            }

            $requestId = $this->requestId($response->header('request-id') ?: $response->header('x-ms-request-id'));

            if ($response->status === 202) {
                // The documented contract: empty 202, no message id.
                return GraphSendResult::accepted($requestId);
            }
            if ($response->status >= 200 && $response->status < 300) {
                // Any other 2xx is an undocumented success shape; do not
                // claim acceptance, and do not replay the write.
                return GraphSendResult::unknown('unexpected_success', $response->status);
            }

            if ($response->status === 401 && !$refreshed) {
                $refreshed = true;
                $this->tokens->invalidate();
                continue;
            }

            if ($response->status === 429) {
                $retryAfter = $this->retryAfterSeconds($response->header('retry-after'));
                if (!$retriedThrottle && $attempt < 2 && $retryAfter > 0 && $retryAfter <= 2 && $this->remainingSeconds($deadline) > 10) {
                    $retriedThrottle = true;
                    $this->clock->sleep($retryAfter);
                    continue;
                }
                return GraphSendResult::temporary('throttled', $retryAfter);
            }

            return match (true) {
                $response->status === 401 => GraphSendResult::rejected('unauthorized_after_refresh', 401),
                $response->status === 400 => GraphSendResult::rejected('invalid_request', 400),
                $response->status === 403 => GraphSendResult::rejected('permission_denied', 403),
                $response->status === 404 => GraphSendResult::rejected('mailbox_not_found', 404),
                $response->status === 413 => GraphSendResult::rejected('message_too_large', 413),
                $response->status >= 500 => GraphSendResult::unknown('provider_error', $response->status),
                default => GraphSendResult::rejected('rejected', $response->status),
            };
        }

        return GraphSendResult::rejected('unauthorized_after_refresh', 401);
    }

    private function remainingSeconds(int $deadline): int
    {
        return max(0, min(self::MAX_TIMEOUT_SECONDS, $deadline - $this->clock->now()));
    }

    private function requestId(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) === 1 ? $value : '';
    }

    private function retryAfterSeconds(string $header): int
    {
        $header = trim($header);
        if ($header === '') {
            return 0;
        }
        if (ctype_digit($header)) {
            return min((int)$header, 3600);
        }
        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return 0;
        }
        return max(0, min(3600, $timestamp - $this->clock->now()));
    }
}
