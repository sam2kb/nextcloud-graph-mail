<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Adapter;

use OCA\GraphMail\Port\HttpClient;
use OCA\GraphMail\Port\HttpRequest;
use OCA\GraphMail\Port\HttpResponse;
use OCA\GraphMail\Port\HttpTransportException;
use OCP\Http\Client\IClientService;

final class NextcloudHttpClient implements HttpClient
{
    private const MAX_RESPONSE_BODY = 65536;

    public function __construct(
        private readonly IClientService $clientService,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $response = $this->clientService->newClient()->request($request->method, $request->url, [
                'headers' => $request->headers,
                'body' => $request->body,
                'timeout' => $request->timeoutSeconds,
                'connect_timeout' => min(10, $request->timeoutSeconds),
                // Credentials must never follow a redirect to another origin.
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[strtolower((string)$name)] = is_array($values) ? implode(', ', $values) : (string)$values;
            }

            $body = (string)$response->getBody();
            if (strlen($body) > self::MAX_RESPONSE_BODY) {
                $body = substr($body, 0, self::MAX_RESPONSE_BODY);
            }

            return new HttpResponse($response->getStatusCode(), $headers, $body);
        } catch (\Throwable $e) {
            throw new HttpTransportException('HTTP submission outcome could not be determined', 0, $e);
        }
    }
}
