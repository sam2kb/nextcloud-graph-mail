<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Command;

use OCA\GraphMail\Db\SubmissionLedger;
use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Routing\ActivationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends Command
{
    public function __construct(
        private readonly ActivationService $activation,
        private readonly SubmissionLedger $ledger,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('graph_mail:status')
            ->setDescription('Show Graph Mail configuration, routing ownership and recent submission outcomes')
            ->addOption('handshake', null, InputOption::VALUE_NONE, 'Also start the helper and check its SMTP greeting (no message is sent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $this->activation->status(true, (bool)$input->getOption('handshake'));
        $payload = $status->toArray();
        $payload['ledger_24h'] = $this->ledger->countsSince($this->clock->now() - 86400);
        $payload['helper'] = [
            'path' => 'bin/sendmail',
            'hint' => 'Run occ graph_mail:status after activation; see docs/operator-recovery.md for manual recovery.',
        ];
        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $status->state->isRoutingUsable() ? 0 : 1;
    }
}
