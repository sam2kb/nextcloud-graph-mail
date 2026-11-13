<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Command;

use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Transport\SmtpSession;
use OCA\GraphMail\Transport\SubmissionLimits;
use OCA\GraphMail\Transport\TransportRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal entrypoint. stdout is the SMTP protocol; nothing else may be
 * written there. Diagnostics go to stderr through the platform logger.
 */
final class TransportCommand extends Command
{
    public function __construct(
        private readonly TransportRunner $runner,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('graph_mail:transport')
            ->setDescription('Internal stdio SMTP transport for the Graph Mail helper')
            ->addOption('stdio', null, InputOption::VALUE_NONE, 'Speak the sendmail -bs SMTP profile on stdin/stdout')
            ->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('stdio')) {
            fwrite(STDERR, "graph_mail:transport is an internal command; --stdio is required\n");
            return 2;
        }
        if (PHP_SAPI !== 'cli') {
            fwrite(STDERR, "graph_mail:transport can only run from the command line\n");
            return 3;
        }

        $session = new SmtpSession(
            STDIN,
            STDOUT,
            $this->runner,
            new SubmissionLimits(),
            $this->clock,
            $this->logger,
        );

        return $session->run(gethostname() ?: 'localhost');
    }
}
