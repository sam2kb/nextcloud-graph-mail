<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Graph;

enum GraphOutcome: string
{
    /** Microsoft documented acceptance (202). Not proof of inbox delivery. */
    case Accepted = 'accepted';
    /** Known rejection; repeating the same request will not help. */
    case Rejected = 'rejected';
    /** Known temporary condition (throttling, auth endpoint outage). */
    case Temporary = 'temporary';
    /** The write may have arrived. Never replay automatically. */
    case Unknown = 'unknown';
}
