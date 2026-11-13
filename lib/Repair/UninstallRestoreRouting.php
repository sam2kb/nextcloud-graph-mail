<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Repair;

use OCA\GraphMail\Routing\ActivationException;
use OCA\GraphMail\Routing\ActivationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Declared as an uninstall repair step, which Nextcloud runs on disable as
 * well as on removal. It restores only values this app still owns and never
 * removes the credentials, so a later re-enable can activate again.
 */
final class UninstallRestoreRouting implements IRepairStep
{
    public function __construct(
        private readonly ActivationService $activation,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Restore mail routing changed by Graph Mail';
    }

    public function run(IOutput $output): void
    {
        try {
            $this->activation->deactivate(true);
        } catch (ActivationException $e) {
            $this->logger->error('Graph Mail could not restore mail routing automatically', [
                'app' => 'graph_mail',
                'reason' => $e->reasonCode,
            ]);
            $output->warning('Graph Mail did not restore mail routing: ' . $e->reasonCode
                . '. See docs/operator-recovery.md and use occ config:system:* to restore it manually.');
            return;
        }
        $output->info('Graph Mail restored its previous mail routing values.');
    }
}
