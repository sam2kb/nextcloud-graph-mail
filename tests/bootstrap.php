<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../vendor/autoload.php';

$nextcloudRoot = getenv('NEXTCLOUD_TEST_ROOT');
if (is_string($nextcloudRoot) && $nextcloudRoot !== '') {
    putenv('GRAPH_MAIL_NC_ROOT=' . $nextcloudRoot);
    define('OC_CONSOLE', true);
    require $nextcloudRoot . '/lib/base.php';
}
