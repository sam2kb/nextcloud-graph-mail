<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
    'routes' => [
        ['name' => 'settings#save', 'url' => '/admin/save', 'verb' => 'POST'],
        ['name' => 'settings#activate', 'url' => '/admin/activate', 'verb' => 'POST'],
        ['name' => 'settings#deactivate', 'url' => '/admin/deactivate', 'verb' => 'POST'],
        ['name' => 'settings#check', 'url' => '/admin/check', 'verb' => 'POST'],
        ['name' => 'settings#test', 'url' => '/admin/test', 'verb' => 'POST'],
    ],
];
