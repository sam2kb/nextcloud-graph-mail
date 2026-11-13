<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Port;

/**
 * No HTTP response was received. The outcome of a write is unknown, so
 * callers must not replay the request automatically.
 */
final class HttpTransportException extends \RuntimeException
{
}
