<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

interface HttpClient
{
    /**
     * @throws HttpTransportException when no HTTP response was obtained at all
     */
    public function send(HttpRequest $request): HttpResponse;
}
