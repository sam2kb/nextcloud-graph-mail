<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Tests\Integration;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Configuration\ConfigurationService;
use OCA\GraphMail\Configuration\SettingsValidationException;
use OCA\GraphMail\Db\SubmissionLedger;
use OCA\GraphMail\Graph\GraphSender;
use OCA\GraphMail\Graph\TokenProvider;
use OCA\GraphMail\Port\HttpResponse;
use OCA\GraphMail\Port\RoutingConfig;
use OCA\GraphMail\Routing\ActivationException;
use OCA\GraphMail\Routing\ActivationService;
use OCA\GraphMail\Routing\HelperLocator;
use OCA\GraphMail\Routing\Preflight;
use OCA\GraphMail\Routing\RoutingSnapshot;
use OCA\GraphMail\Tests\Support\FakeClock;
use OCA\GraphMail\Tests\Support\FakeHttpClient;
use OCA\GraphMail\Tests\Support\FakeLock;
use OCA\GraphMail\Tests\Support\FakeTokenCache;
use OCA\GraphMail\Tests\Support\FakeTokenProvider;
use OCA\GraphMail\Transport\InboundMessage;
use OCA\GraphMail\Transport\MessageReconciler;
use OCA\GraphMail\Transport\Rfc5322AddressParser;
use OCA\GraphMail\Transport\SubmissionStatus;
use OCA\GraphMail\Transport\TransportRunner;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use OCP\IDBConnection;
use OCP\Mail\IEmailValidator;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class RuntimeTest extends TestCase
{
    private array $values = [];
    private AppSettings $settings;
    private IAppConfig $config;
    private FakeClock $clock;
    private RoutingConfig $routing;
    private ActivationService $activation;
    private bool $diagnosticsFail = false;

    protected function setUp(): void
    {
        if (!getenv('NEXTCLOUD_TEST_ROOT')) {
            self::markTestSkipped('Requires a disposable Nextcloud fixture');
        }
        $this->config = $this->createMock(IAppConfig::class);
        foreach (['String', 'Int', 'Bool'] as $type) {
            $this->config->method('getValue' . $type)->willReturnCallback(function ($app, $key, $default = null) {
                if ($this->diagnosticsFail && $key === 'ledger_key') {
                    throw new \RuntimeException('diagnostic read failed');
                }
                return $this->values[$key] ?? $default;
            });
            $this->config->method('setValue' . $type)->willReturnCallback(function ($app, $key, $value) {
                $this->values[$key] = $value;
                return true;
            });
        }
        $this->config->method('deleteKey')->willReturnCallback(function ($app, $key) {
            if ($this->diagnosticsFail) {
                throw new \RuntimeException('diagnostic write failed');
            }
            unset($this->values[$key]);
            return true;
        });
        $this->settings = new AppSettings($this->config, Server::get(ICrypto::class), Server::get(ISecureRandom::class), Server::get(IEmailValidator::class));
        $this->settings->save('fixture.invalid', '11111111-1111-1111-1111-111111111111', 'synthetic-secret', 'sender@example.invalid');
        $this->clock = new FakeClock();
        $this->routing = new class () implements RoutingConfig {
            public RoutingSnapshot $snapshot;
            public function __construct()
            {
                $this->snapshot = new RoutingSnapshot('smtp', null, null);
            }
            public function current(): RoutingSnapshot
            {
                return $this->snapshot;
            }
            public function isReadOnly(): bool
            {
                return false;
            }
            public function write(array $values): void
            {
                $this->snapshot = new RoutingSnapshot($values['mail_smtpmode'] ?? $this->snapshot->smtpMode, $values['mail_sendmailmode'] ?? $this->snapshot->sendmailMode, $values['binary_search_paths'] ?? $this->snapshot->searchPaths);
            }
            public function delete(string $key): void
            {
                $s = $this->snapshot;
                $this->snapshot = new RoutingSnapshot($key === 'mail_smtpmode' ? null : $s->smtpMode, $key === 'mail_sendmailmode' ? null : $s->sendmailMode, $key === 'binary_search_paths' ? null : $s->searchPaths);
            }
        };
        $locator = new HelperLocator();
        $finder = $this->createMock(IBinaryFinder::class);
        $finder->method('findBinaryPath')->willReturn($locator->helperPath());
        $this->activation = new ActivationService($this->routing, $this->settings, new Preflight($locator, $this->routing), $locator, $this->config, $finder, new FakeLock(), $this->clock, new NullLogger(), new FakeTokenProvider());
    }

    public function testStagingKeepsWorkingTransportUntilResolverGraceExpires(): void
    {
        self::assertSame('checking', $this->activation->activate()->state->value);
        self::assertSame('smtp', $this->routing->current()->smtpMode);
        self::assertNull($this->routing->current()->sendmailMode);
        self::assertContains('/usr/bin', $this->routing->current()->searchPaths);
        $this->clock->advance(ActivationService::RESOLVER_GRACE_SECONDS + 1);
        self::assertSame('active', $this->activation->activate()->state->value);
        self::assertSame('sendmail', $this->routing->current()->smtpMode);
        $this->activation->deactivate();
        self::assertSame(['smtp_mode' => 'smtp', 'sendmail_mode' => null, 'search_paths' => null], $this->routing->current()->toArray());
        self::assertFalse($this->activation->needsReconciliation());
    }

    public function testConflictRetainsJournalAndRestoresOnlyOwnedValues(): void
    {
        $this->activation->activate();
        $this->clock->advance(ActivationService::RESOLVER_GRACE_SECONDS + 1);
        $this->activation->activate();
        $this->routing->write(['mail_smtpmode' => 'null']);
        try {
            $this->activation->deactivate();
            self::fail('Expected conflict');
        } catch (ActivationException) {
        }
        self::assertSame('null', $this->routing->current()->smtpMode);
        self::assertNull($this->routing->current()->sendmailMode);
        $journal = json_decode($this->values['routing_journal'], true);
        self::assertSame('smtp', $journal['previous']['smtp_mode']);
        self::assertSame('conflict', $journal['state']);
        $this->expectException(ActivationException::class);
        $this->activation->activate();
    }

    public function testPreexistingHelperPathSurvivesDisableAndConfiguredEnableCanReconcile(): void
    {
        $paths = [(new HelperLocator())->helperDirectory(), '/usr/bin'];
        $this->routing->snapshot = new RoutingSnapshot('smtp', null, $paths);
        $this->activation->activate();
        $this->activation->deactivate(true);
        self::assertSame($paths, $this->routing->current()->searchPaths);
        self::assertTrue($this->activation->needsReconciliation());
        self::assertTrue($this->settings->hasSecret());
    }

    public function testRejectedReplacementKeepsWorkingCredentialRecord(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(401, [], '{"error":"invalid_client"}'));
        $tokens = new TokenProvider($http, new FakeTokenCache(), new FakeLock(), $this->clock, $this->settings, new NullLogger());
        $before = $this->values['sender_configuration'];
        try {
            (new ConfigurationService($this->settings, $tokens, new FakeLock()))->save('replacement.invalid', '22222222-2222-2222-2222-222222222222', 'new-secret', 'other@example.invalid');
            self::fail('Expected credential rejection');
        } catch (SettingsValidationException $e) {
            self::assertSame('credential_check_failed', $e->reasonCode);
        }
        self::assertSame($before, $this->values['sender_configuration']);
        self::assertSame('synthetic-secret', $this->settings->credentials()->clientSecret);
    }

    public function testAcceptedWriteRemainsAcceptedWhenAllDiagnosticsFail(): void
    {
        $http = new FakeHttpClient();
        $http->queue(new HttpResponse(202, [], ''));
        $logger = new class () extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('database failed'));
        $runner = new TransportRunner($this->settings, new MessageReconciler(new Rfc5322AddressParser()), new GraphSender($http, new FakeTokenProvider(), $this->clock, $logger), new SubmissionLedger($db, $this->clock, $logger), $this->activation, $this->clock, $logger);
        $this->diagnosticsFail = true;
        $result = $runner->handle(new InboundMessage('sender@example.invalid', ['client@example.invalid'], "From: sender@example.invalid\r\nTo: client@example.invalid\r\n\r\nbody\r\n"));
        self::assertSame(SubmissionStatus::Accepted, $result->status);
        self::assertCount(1, $http->requests);
    }

    public function testLazyHttpResponseFailureIsUnknownAndNotReplayed(): void
    {
        $response = $this->createStub(\OCP\Http\Client\IResponse::class);
        $response->method('getHeaders')->willReturn([]);
        $response->method('getBody')->willThrowException(new \RuntimeException('read failed after submission'));
        $client = $this->createMock(\OCP\Http\Client\IClient::class);
        $client->expects(self::once())->method('request')->willReturn($response);
        $service = $this->createStub(\OCP\Http\Client\IClientService::class);
        $service->method('newClient')->willReturn($client);
        $sender = new GraphSender(new \OCA\GraphMail\Adapter\NextcloudHttpClient($service), new FakeTokenProvider(), $this->clock, new NullLogger());
        $result = $sender->send("From: sender@example.invalid\r\n\r\nbody", 'sender@example.invalid', $this->clock->now() + 60);
        self::assertSame('unknown', $result->outcome->value);
    }

    public function testConcurrentConfigurationDoesNotValidateOrReplaceAnything(): void
    {
        $http = new FakeHttpClient();
        $lock = new FakeLock();
        $lock->available = false;
        $tokens = new TokenProvider($http, new FakeTokenCache(), new FakeLock(), $this->clock, $this->settings, new NullLogger());
        $before = $this->values['sender_configuration'];
        try {
            (new ConfigurationService($this->settings, $tokens, $lock))->save('other.invalid', '22222222-2222-2222-2222-222222222222', 'new-value', 'other@example.invalid');
            self::fail('Expected an update conflict');
        } catch (SettingsValidationException $error) {
            self::assertSame('configuration_in_progress', $error->reasonCode);
        }
        self::assertCount(0, $http->requests);
        self::assertSame($before, $this->values['sender_configuration']);
    }

    public function testSettingsRenderMaskedSenderAndNativeIconUrl(): void
    {
        $urls = Server::get(\OCP\IURLGenerator::class);
        $request = $this->createStub(\OCP\IRequest::class);
        $request->method('getParam')->willReturn('');
        $form = (new \OCA\GraphMail\Settings\Admin($this->settings, $this->activation, $urls, $request, Server::get(\OCP\L10N\IFactory::class)))->getForm();
        $form->renderAs('blank');
        $html = $form->render();
        self::assertStringContainsString('Save and activate', $html);
        self::assertStringContainsString('sender@example.invalid', $html);
        self::assertStringNotContainsString('synthetic-secret', $html);
        self::assertStringNotContainsString('client_secret_ciphertext', $html);
        $section = new \OCA\GraphMail\Settings\AdminSection(Server::get(\OCP\L10N\IFactory::class)->get('graph_mail'), $urls);
        self::assertStringEndsWith('/img/app.svg', $section->getIcon());
    }

    public function testInterruptedCommitCompletesWithoutLosingOriginalSnapshot(): void
    {
        $this->activation->activate();
        $this->clock->advance(ActivationService::RESOLVER_GRACE_SECONDS + 1);
        $this->routing->write(['mail_smtpmode' => 'sendmail', 'mail_sendmailmode' => 'smtp']);
        self::assertSame('active', $this->activation->activate()->state->value);
        self::assertSame('committed', json_decode($this->values['routing_journal'], true)['stage']);
        $this->activation->deactivate();
        self::assertSame('smtp', $this->routing->current()->smtpMode);
    }

    public function testOrphanedGraphRouteIsNotCapturedAsAnOriginalRoute(): void
    {
        $this->routing->snapshot = new RoutingSnapshot('sendmail', 'smtp', [(new HelperLocator())->helperDirectory()]);
        self::assertSame('conflict', $this->activation->status()->state->value);
        $this->expectException(ActivationException::class);
        $this->activation->activate();
    }
}
