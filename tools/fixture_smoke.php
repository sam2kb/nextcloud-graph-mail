<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later */

// Only run inside an isolated, network-disabled disposable Nextcloud fixture.
if (getenv('GRAPH_MAIL_DISPOSABLE_FIXTURE') !== '1') { throw new RuntimeException('Disposable-fixture opt-in required'); }
define('OC_CONSOLE', true);
$root = '/var/www/html';
require $root . '/lib/base.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); });
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'OCA\\GraphMail\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/custom_apps/graph_mail/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
function check(bool $value, string $name): void { if (!$value) { throw new RuntimeException($name); } echo $name . " OK\n"; }
$settings = new \OCA\GraphMail\Configuration\AppSettings(
    \OCP\Server::get(\OCP\IAppConfig::class), \OCP\Server::get(\OCP\Security\ICrypto::class),
    \OCP\Server::get(\OCP\Security\ISecureRandom::class), \OCP\Server::get(\OCP\Mail\IEmailValidator::class),
);
$settings->save('fixture.invalid', '11111111-1111-1111-1111-111111111111', 'synthetic-offline-value', 'sender@example.invalid');
$routing = new \OCA\GraphMail\Adapter\NextcloudRoutingConfig(\OCP\Server::get(\OCP\IConfig::class));
$locator = new \OCA\GraphMail\Routing\HelperLocator();
$clock = new class implements \OCA\GraphMail\Port\Clock {
    public int $offset = 0;
    public function now(): int { return time() + $this->offset; }
    public function sleep(int $seconds): void { $this->offset += $seconds; }
};
$tokens = new class implements \OCA\GraphMail\Port\AccessTokenProvider {
    public function token(): \OCA\GraphMail\Graph\Token { return new \OCA\GraphMail\Graph\Token('synthetic-offline-token', PHP_INT_MAX); }
    public function invalidate(): void {}
};
$service = new \OCA\GraphMail\Routing\ActivationService(
    $routing, $settings, new \OCA\GraphMail\Routing\Preflight($locator, $routing), $locator,
    \OCP\Server::get(\OCP\IAppConfig::class), \OCP\Server::get(\OCP\IBinaryFinder::class),
    new \OCA\GraphMail\Adapter\NextcloudLock(\OCP\Server::get(\OCP\Lock\ILockingProvider::class)),
    $clock, \OCP\Server::get(\Psr\Log\LoggerInterface::class), $tokens,
);
$before = $routing->current();
check($service->activate()->state->value === 'checking', 'staged');
check($routing->current()->smtpMode === $before->smtpMode, 'old transport retained');
$clock->offset += 306;
check($service->activate()->state->value === 'active', 'native routing committed');
$message = \OCP\Server::get(\OCP\Mail\IMailer::class)->createMessage();
$message->setFrom(['original@example.invalid'])->setReplyTo(['reply@example.invalid'])->setTo(['client@example.invalid'])->setPlainBody('Not sent');
(new \OCA\GraphMail\Listener\SystemSender($settings, $service))->handle(new \OCP\Mail\Events\BeforeMessageSent($message));
check($message->getFrom() === ['sender@example.invalid'], 'native From aligned');
check($message->getReplyTo() === ['reply@example.invalid'], 'native Reply-To preserved');
// Leave active for a separate real occ app:disable lifecycle check. No send() call.
