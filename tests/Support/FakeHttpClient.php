<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Support;

use OCA\GraphMail\Port\HttpClient;
use OCA\GraphMail\Port\HttpRequest;
use OCA\GraphMail\Port\HttpResponse;
use OCA\GraphMail\Port\HttpTransportException;

final class FakeHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queue = [];

    public function queue(HttpResponse|HttpTransportException $response): void
    {
        $this->queue[] = $response;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException('No fake response queued');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
