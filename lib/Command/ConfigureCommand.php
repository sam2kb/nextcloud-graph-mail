<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Command;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Configuration\SettingsValidationException;
use OCA\GraphMail\Routing\ActivationException;
use OCA\GraphMail\Routing\ActivationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Configuration is read from stdin or a secret file. A secret is never
 * accepted as an ordinary argv flag and never echoed.
 */
final class ConfigureCommand extends Command
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly \OCA\GraphMail\Configuration\ConfigurationService $configuration,
        private readonly ActivationService $activation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('graph_mail:configure')
            ->setDescription('Configure the Microsoft 365 sender from stdin JSON or a secret file')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read a JSON object from stdin: tenant_id, client_id, secret, mailbox')
            ->addOption('secret-file', null, InputOption::VALUE_REQUIRED, 'Read the client secret from this file (never from argv)')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate routing (the default)')
            ->addOption('clear-secret', null, InputOption::VALUE_NONE, 'Deactivate routing and forget the stored client secret')
            ->addOption('no-activate', null, InputOption::VALUE_NONE, 'Save configuration and keep Graph routing off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('clear-secret')) {
            try {
                $this->activation->deactivate();
                $this->settings->clearSecret();
            } catch (\Throwable) {
                $output->writeln('{"ok":false,"error":"deactivation_failed"}');
                return 1;
            }
            $output->writeln('{"ok":true,"state":"setup_required"}');
            return 0;
        }
        $secretFile = $input->getOption('secret-file');
        $secretFromFile = null;
        if (is_string($secretFile)) {
            if (!is_file($secretFile) || !is_readable($secretFile)) {
                $output->writeln(json_encode(['ok' => false, 'error' => 'secret_file_unreadable']));
                return 1;
            }
            $secretFromFile = rtrim((string)file_get_contents($secretFile, false, null, 0, 2049), "\r\n");
        }

        if (!$input->getOption('stdin')) {
            $output->writeln(json_encode(['ok' => false, 'error' => 'stdin_required']));
            return 1;
        }
        $raw = stream_get_contents(STDIN, 16385);
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload) || strlen($raw) > 16384) {
            $output->writeln(json_encode(['ok' => false, 'error' => 'invalid_json']));
            return 1;
        }

        $tenantId = is_string($payload['tenant_id'] ?? null) ? $payload['tenant_id'] : '';
        $clientId = is_string($payload['client_id'] ?? null) ? $payload['client_id'] : '';
        $mailbox = is_string($payload['mailbox'] ?? null) ? $payload['mailbox'] : '';
        $secret = $secretFromFile;
        if ($secret === null && is_string($payload['secret'] ?? null) && $payload['secret'] !== '') {
            $secret = $payload['secret'];
        }

        try {
            $this->configuration->save($tenantId, $clientId, $secret, $mailbox);
        } catch (SettingsValidationException $e) {
            $output->writeln(json_encode(['ok' => false, 'error' => $e->reasonCode]));
            return 1;
        }

        try {
            if ($input->getOption('no-activate')) {
                $this->activation->deactivate();
            } else {
                $this->activation->activate();
            }
        } catch (ActivationException $e) {
            $output->writeln(json_encode([
                'ok' => false, 'error' => $e->reasonCode, 'details' => $e->details,
            ]));
            return 1;
        }

        $status = $this->activation->status();
        $output->writeln(json_encode([
            'ok' => true,
            'configured' => $this->settings->isConfigured(),
            'state' => $status->state->value,
        ]));
        return 0;
    }
}
