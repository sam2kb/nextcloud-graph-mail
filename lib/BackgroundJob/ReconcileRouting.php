<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\BackgroundJob;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Routing\ActivationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

final class ReconcileRouting extends TimedJob
{
    public function __construct(ITimeFactory $time, private readonly ActivationService $activation, private readonly IAppConfig $config, private readonly LoggerInterface $logger)
    {
        parent::__construct($time);
        $this->setInterval(60);
    }
    protected function run($argument): void
    {
        if ($this->config->getValueString(AppSettings::APP_ID, 'enabled', 'no') !== 'yes' || !$this->activation->needsReconciliation()) {
            return;
        }
        try {
            $this->activation->activate();
        } catch (\Throwable $e) {
            $this->logger->warning('Graph Mail activation needs attention', ['app' => AppSettings::APP_ID, 'exception' => $e::class]);
        }
    }
}
