<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\AppInfo;

use OCA\GraphMail\Adapter\NextcloudHttpClient;
use OCA\GraphMail\Adapter\NextcloudLock;
use OCA\GraphMail\Adapter\NextcloudRoutingConfig;
use OCA\GraphMail\Adapter\NextcloudTokenCache;
use OCA\GraphMail\Adapter\SystemClock;
use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Graph\TokenProvider;
use OCA\GraphMail\Port\AccessTokenProvider;
use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Port\CredentialProvider;
use OCA\GraphMail\Port\HttpClient;
use OCA\GraphMail\Port\Lock;
use OCA\GraphMail\Port\RoutingConfig;
use OCA\GraphMail\Port\TokenCache;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

final class Application extends App implements IBootstrap
{
    public const APP_ID = 'graph_mail';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(\OCP\Mail\Events\BeforeMessageSent::class, \OCA\GraphMail\Listener\SystemSender::class, -1000);
        $context->registerServiceAlias(HttpClient::class, NextcloudHttpClient::class);
        $context->registerServiceAlias(Clock::class, SystemClock::class);
        $context->registerServiceAlias(TokenCache::class, NextcloudTokenCache::class);
        $context->registerServiceAlias(Lock::class, NextcloudLock::class);
        $context->registerServiceAlias(RoutingConfig::class, NextcloudRoutingConfig::class);
        $context->registerServiceAlias(CredentialProvider::class, AppSettings::class);
        $context->registerServiceAlias(AccessTokenProvider::class, TokenProvider::class);
    }

    public function boot(IBootContext $context): void
    {
        // Deliberately empty. Routing is only reconciled on explicit
        // configuration and lifecycle transitions, never on every bootstrap.
    }
}
