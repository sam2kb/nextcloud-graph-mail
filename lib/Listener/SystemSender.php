<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Listener;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Routing\ActivationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Mail\Events\BeforeMessageSent;

/** @implements IEventListener<BeforeMessageSent> */
final class SystemSender implements IEventListener
{
    public function __construct(private readonly AppSettings $settings, private readonly ActivationService $activation)
    {
    }
    public function handle(Event $event): void
    {
        if (!$event instanceof BeforeMessageSent || !$this->activation->status()->state->isRoutingUsable()) {
            return;
        }
        // Graph authenticates one system mailbox. Explicit Reply-To remains unchanged.
        $event->getMessage()->setFrom([$this->settings->mailbox()]);
    }
}
