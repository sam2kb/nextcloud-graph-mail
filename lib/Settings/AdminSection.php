<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection
{
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls,
    ) {
    }

    public function getID(): string
    {
        return 'graph_mail';
    }

    public function getName(): string
    {
        return $this->l10n->t('Graph Mail');
    }

    public function getPriority(): int
    {
        return 80;
    }

    public function getIcon(): string
    {
        return $this->urls->imagePath('graph_mail', 'app.svg');
    }
}
