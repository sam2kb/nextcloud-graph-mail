<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Repair;

use OCA\GraphMail\Routing\HelperLocator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * App-store extraction copies files with PHP copy(), which does not preserve
 * the executable bit. This runs on install and on every update, before the
 * app is enabled, so the bundled helper is executable again.
 */
final class InstallHelperPermissions implements IRepairStep
{
    public function __construct(
        private readonly HelperLocator $locator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Make the Graph Mail sendmail helper executable';
    }

    public function run(IOutput $output): void
    {
        $helper = $this->locator->helperPath();
        if ($helper === '' || !is_file($helper)) {
            $output->warning('Graph Mail helper is missing from the package');
            return;
        }
        if (is_executable($helper)) {
            return;
        }
        if (!@chmod($helper, 0755)) {
            $this->logger->error('Graph Mail could not make its sendmail helper executable', ['app' => 'graph_mail']);
            $output->warning('Could not set the executable bit on bin/sendmail; activation will refuse until this is fixed.');
            return;
        }
        $this->logger->info('Graph Mail restored the executable bit on its sendmail helper', ['app' => 'graph_mail']);
    }
}
