<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\BackgroundJob;

use OCA\GraphMail\Db\SubmissionLedger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

final class PruneSubmissionLedger extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly SubmissionLedger $ledger,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run($argument): void
    {
        try {
            $deleted = $this->ledger->prune();
            $this->logger->debug('Graph Mail pruned {count} ledger rows', [
                'app' => 'graph_mail',
                'count' => $deleted,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Graph Mail ledger prune failed', [
                'app' => 'graph_mail',
                'exception' => $e::class,
            ]);
        }
    }
}
