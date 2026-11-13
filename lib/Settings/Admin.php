<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Settings;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Routing\ActivationService;
use OCA\GraphMail\Routing\ActivationState;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Settings\ISettings;
use OCP\Util;

final class Admin implements ISettings
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly ActivationService $activation,
        private readonly IURLGenerator $urlGenerator,
        private readonly IRequest $request,
        private readonly IFactory $l10nFactory,
    ) {
    }

    public function getForm(): TemplateResponse
    {
        $l = $this->l10nFactory->get('graph_mail');
        $status = $this->activation->status(true);

        $params = [
            'settings' => [
                'tenant_id' => $this->settings->tenantId(),
                'client_id' => $this->settings->clientId(),
                'mailbox' => $this->settings->mailbox(),
                'has_secret' => $this->settings->hasSecret(),
            ],
            'status' => [
                'state' => $status->state->value,
                'message' => $status->message,
                'configured' => $status->configured,
                'label' => $this->stateLabel($l, $status->state),
            ],
            'preflight' => $status->preflight?->toArray() ?? [],
            'notices' => $this->notices($l, (string)$this->request->getParam('msg', '')),
            'urls' => [
                'save' => $this->urlGenerator->linkToRoute('graph_mail.settings.save'),
                'activate' => $this->urlGenerator->linkToRoute('graph_mail.settings.activate'),
                'deactivate' => $this->urlGenerator->linkToRoute('graph_mail.settings.deactivate'),
                'check' => $this->urlGenerator->linkToRoute('graph_mail.settings.check'),
                'test' => $this->urlGenerator->linkToRoute('graph_mail.settings.test'),
            ],
            'csrf' => Util::callRegister(),
        ];

        return new TemplateResponse('graph_mail', 'settings', $params);
    }

    public function getSection(): string
    {
        return 'graph_mail';
    }

    public function getPriority(): int
    {
        return 10;
    }

    private function stateLabel(\OCP\IL10N $l, ActivationState $state): string
    {
        return match ($state) {
            ActivationState::SetupRequired => $l->t('Setup required'),
            ActivationState::Inactive => $l->t('Inactive'),
            ActivationState::Checking => $l->t('Checking'),
            ActivationState::Active => $l->t('Active'),
            ActivationState::Degraded => $l->t('Degraded'),
            ActivationState::Conflict => $l->t('Conflict'),
        };
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    private function notices(\OCP\IL10N $l, string $code): array
    {
        $map = [
            'configuration_in_progress' => ['error', $l->t('Another sender update is in progress. Try again in a moment.')],
            'credential_check_failed' => ['error', $l->t('Microsoft could not validate these credentials. The previous sender configuration was kept.')],
            'checking' => ['success', $l->t('Activation is scheduled. Your previous mail route stays in use while local checks finish.')],
            'saved' => ['success', $l->t('Configuration saved.')],
            'activated' => ['success', $l->t('Configuration saved and Graph routing is active.')],
            'deactivated' => ['success', $l->t('Graph routing was removed and the previous values were restored.')],
            'check_ok' => ['success', $l->t('Microsoft accepted the client credentials. This does not yet prove the mailbox permission.')],
            'check_failed' => ['error', $l->t('Microsoft rejected the token request. Check the tenant, client ID and secret.')],
            'test_sent' => ['success', $l->t('The test message was accepted for submission. Acceptance is not proof of inbox delivery.')],
            'test_failed' => ['error', $l->t('Submission was not confirmed. Check the Nextcloud log and Microsoft 365 before trying again.')],
            'not_configured' => ['error', $l->t('Save a complete configuration first.')],
            'preflight_failed' => ['error', $l->t('Activation was refused because a local preflight check failed.')],
            'activation_failed' => ['error', $l->t('Activation failed and the previous configuration was restored.')],
            'routing_conflict' => ['error', $l->t('Mail routing was changed outside Graph Mail. Resolve the conflict manually before activating again.')],
            'invalid_tenant' => ['error', $l->t('The tenant ID is not valid.')],
            'invalid_client_id' => ['error', $l->t('The client ID must be an Entra application (GUID).')],
            'invalid_mailbox' => ['error', $l->t('The sender mailbox is not a valid address.')],
            'unreadable_secret' => ['error', $l->t('The stored secret cannot be read. Enter a replacement client secret.')],
            'missing_secret' => ['error', $l->t('Enter a client secret before activating the sender.')],
            'invalid_secret' => ['error', $l->t('The client secret is empty or contains unsupported characters.')],
            'invalid_recipient' => ['error', $l->t('The test recipient is not a valid address.')],
            'routing_not_active' => ['error', $l->t('Graph routing is not active, so no test was sent.')],
            'activation_in_progress' => ['error', $l->t('Another activation is in progress. Try again in a moment.')],
        ];
        if ($code !== '' && isset($map[$code])) {
            return [['type' => $map[$code][0], 'text' => $map[$code][1]]];
        }
        return [];
    }
}
