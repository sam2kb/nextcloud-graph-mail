<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Controller;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Configuration\SettingsValidationException;
use OCA\GraphMail\Graph\GraphAuthException;
use OCA\GraphMail\Port\AccessTokenProvider;
use OCA\GraphMail\Routing\ActivationException;
use OCA\GraphMail\Routing\ActivationService;
use OCA\GraphMail\Transport\Rfc5322AddressParser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Admin-only actions. The default attributes require an administrator and a
 * valid CSRF token; there is no public send endpoint.
 */
final class SettingsController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly AppSettings $settings,
        private readonly \OCA\GraphMail\Configuration\ConfigurationService $configuration,
        private readonly ActivationService $activation,
        private readonly AccessTokenProvider $tokens,
        private readonly IMailer $mailer,
        private readonly Rfc5322AddressParser $addresses,
        private readonly IURLGenerator $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct('graph_mail', $request);
    }

    public function save(): RedirectResponse
    {
        $tenantId = (string)$this->request->getParam('tenant_id', '');
        $clientId = (string)$this->request->getParam('client_id', '');
        $mailbox = (string)$this->request->getParam('mailbox', '');
        $secret = (string)$this->request->getParam('secret', '');

        try {
            $this->configuration->save($tenantId, $clientId, $secret === '' ? null : $secret, $mailbox);
        } catch (SettingsValidationException $e) {
            return $this->redirect($e->reasonCode);
        }

        return $this->activate();
    }

    public function activate(): RedirectResponse
    {
        try {
            $status = $this->activation->activate();
        } catch (ActivationException $e) {
            $this->logger->error('Graph Mail activation was refused', [
                'app' => 'graph_mail',
                'reason' => $e->reasonCode,
                'details' => $e->details,
            ]);
            return $this->redirect($e->reasonCode);
        }
        return $this->redirect($status->state->value === 'checking' ? 'checking' : 'activated');
    }

    public function deactivate(): RedirectResponse
    {
        try {
            $this->activation->deactivate();
        } catch (ActivationException $e) {
            return $this->redirect('routing_conflict');
        }
        return $this->redirect('deactivated');
    }

    public function check(): RedirectResponse
    {
        try {
            $this->tokens->token();
        } catch (GraphAuthException $e) {
            $this->logger->warning('Graph Mail connection check failed', [
                'app' => 'graph_mail',
                'reason' => $e->getMessage(),
            ]);
            return $this->redirect('check_failed');
        }
        return $this->redirect('check_ok');
    }

    public function test(): RedirectResponse
    {
        $recipient = (string)$this->request->getParam('recipient', '');
        if (!$this->addresses->isValidAddress($recipient)) {
            return $this->redirect('invalid_recipient');
        }
        if (!$this->settings->isConfigured()) {
            return $this->redirect('not_configured');
        }
        if (!$this->activation->status()->state->isRoutingUsable()) {
            return $this->redirect('routing_not_active');
        }

        $message = $this->mailer->createMessage();
        $message->setFrom([$this->settings->mailbox() => 'Graph Mail test']);
        $message->setTo([$recipient]);
        $message->setSubject('Graph Mail test ' . bin2hex(random_bytes(4)));
        $message->setPlainBody("This is a test message from your Nextcloud Microsoft 365 connection.\n");
        $failed = $this->mailer->send($message);

        return $this->redirect($failed === [] ? 'test_sent' : 'test_failed');
    }

    private function redirect(string $message): RedirectResponse
    {
        $url = $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => 'graph_mail']);
        return new RedirectResponse($url . '?msg=' . rawurlencode($message));
    }
}
