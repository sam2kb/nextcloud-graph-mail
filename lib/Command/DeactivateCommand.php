<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Command;

use OCA\GraphMail\Routing\ActivationException;
use OCA\GraphMail\Routing\ActivationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DeactivateCommand extends Command
{
    public function __construct(
        private readonly ActivationService $activation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('graph_mail:deactivate')
            ->setDescription('Restore the previous mail routing values this app still owns');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $status = $this->activation->deactivate();
        } catch (ActivationException $e) {
            $output->writeln(json_encode([
                'ok' => false,
                'error' => $e->reasonCode,
                'recovery' => 'See docs/operator-recovery.md. Routing was changed outside Graph Mail and was not overwritten.',
            ]));
            return 1;
        }
        $output->writeln(json_encode(['ok' => true, 'state' => $status->state->value]));
        return 0;
    }
}
