<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Command;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Routing\ActivationService;
use OCA\GraphMail\Transport\Rfc5322AddressParser;
use OCP\Mail\IMailer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One deliberate message to an operator-chosen recipient. It goes through the
 * real central mailer, so it also proves the helper/route wiring.
 */
final class TestSendCommand extends Command
{
    public function __construct(
        private readonly IMailer $mailer,
        private readonly AppSettings $settings,
        private readonly ActivationService $activation,
        private readonly Rfc5322AddressParser $addresses,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('graph_mail:test-send')
            ->setDescription('Send one explicit test message through the configured sender')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Recipient address for the single test message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $to = (string)$input->getOption('to');
        if (!$this->addresses->isValidAddress($to)) {
            $output->writeln(json_encode(['ok' => false, 'error' => 'invalid_recipient']));
            return 1;
        }
        if (!$this->settings->isConfigured()) {
            $output->writeln(json_encode(['ok' => false, 'error' => 'not_configured']));
            return 1;
        }
        $status = $this->activation->status();
        if (!$status->state->isRoutingUsable()) {
            $output->writeln(json_encode(['ok' => false, 'error' => 'routing_not_active', 'state' => $status->state->value]));
            return 1;
        }

        $message = $this->mailer->createMessage();
        $message->setFrom([$this->settings->mailbox() => 'Graph Mail test']);
        $message->setTo([$to]);
        $message->setSubject('Graph Mail test ' . bin2hex(random_bytes(4)));
        $message->setPlainBody("This is a test message from your Nextcloud Microsoft 365 connection.\n");
        $failed = $this->mailer->send($message);

        $output->writeln(json_encode([
            'ok' => $failed === [],
            'failed_recipients' => count($failed),
            'note' => $failed === []
                ? 'Acceptance by Microsoft is not proof of inbox delivery.'
                : 'Submission was not confirmed. Check Microsoft 365 before retrying.',
        ]));
        return $failed === [] ? 0 : 1;
    }
}
