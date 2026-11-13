<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Routing;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Port\AccessTokenProvider;
use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Port\Lock;
use OCA\GraphMail\Port\RoutingConfig;
use OCP\IAppConfig;
use OCP\IBinaryFinder;
use Psr\Log\LoggerInterface;

final class ActivationService
{
    private const JOURNAL = 'routing_journal';
    private const REQUESTED = 'routing_requested';
    private const DEGRADED = 'degraded_since';
    private const LOCK = 'activation';
    public const RESOLVER_GRACE_SECONDS = 305;
    private const DEFAULT_PATHS = ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin', '/opt/bin'];

    public function __construct(
        private readonly RoutingConfig $routing,
        private readonly AppSettings $settings,
        private readonly Preflight $preflight,
        private readonly HelperLocator $locator,
        private readonly IAppConfig $appConfig,
        private readonly IBinaryFinder $binaryFinder,
        private readonly Lock $lock,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly AccessTokenProvider $tokens,
    ) {
    }

    public function needsReconciliation(): bool
    {
        if (!$this->settings->isConfigured() || !$this->requested()) {
            return false;
        }
        $journal = $this->journal();
        return $journal === null || in_array($journal['state'] ?? '', ['inactive', 'checking'], true);
    }

    public function status(bool $withPreflight = false, bool $withHandshake = false): ActivationStatus
    {
        $checks = $withPreflight ? $this->preflight->check($withHandshake) : null;
        if (!$this->settings->isConfigured()) {
            return new ActivationStatus(ActivationState::SetupRequired, 'setup_required', false, $checks);
        }
        $journal = $this->journal();
        if ($journal === null || ($journal['state'] ?? '') === 'inactive') {
            $current = $this->routing->current();
            if ($current->isSendmailSmtp() && $current->hasSearchPath($this->locator->helperDirectory())) {
                return new ActivationStatus(ActivationState::Conflict, 'routing_active_without_journal', true, $checks);
            }
            $state = $this->needsReconciliation() ? ActivationState::Checking : ActivationState::Inactive;
            return new ActivationStatus($state, $state === ActivationState::Checking ? 'activation_scheduled' : 'inactive', true, $checks);
        }
        if (($journal['state'] ?? '') === 'conflict' || !$this->validJournal($journal)) {
            return new ActivationStatus(ActivationState::Conflict, 'routing_conflict', true, $checks);
        }
        $current = $this->routing->current();
        $previous = RoutingSnapshot::fromArray($journal['previous']);
        $staged = ($journal['stage'] ?? 'committed') === 'staged' && !$current->isSendmailSmtp();
        $modeMatches = $staged
            ? ($current->smtpMode === $previous->smtpMode && $current->sendmailMode === $previous->sendmailMode)
            : ($current->smtpMode === 'sendmail' && $current->sendmailMode === 'smtp');
        if (!$modeMatches || !$current->hasSearchPath($this->locator->helperDirectory()) || $this->localResolver() !== $this->locator->helperPath()) {
            return new ActivationStatus(ActivationState::Conflict, 'routing_changed_outside_graph_mail', true, $checks);
        }
        if ($staged) {
            return new ActivationStatus(ActivationState::Checking, 'awaiting_binary_finder_cache', true, $checks);
        }
        if ($this->binaryFinder->findBinaryPath('sendmail') !== $this->locator->helperPath()) {
            return new ActivationStatus(ActivationState::Conflict, 'helper_not_selected', true, $checks);
        }
        $state = $this->degradedSince() > 0 ? ActivationState::Degraded : ActivationState::Active;
        return new ActivationStatus($state, $state->value, true, $checks);
    }

    public function activate(): ActivationStatus
    {
        if (!$this->settings->isConfigured()) {
            throw new ActivationException('not_configured');
        }
        if (!$this->lock->tryAcquire(self::LOCK)) {
            throw new ActivationException('activation_in_progress');
        }
        try {
            $journal = $this->journal();
            if ($journal !== null && ($journal['state'] ?? '') !== 'inactive') {
                if (!$this->validJournal($journal) || $this->status()->state === ActivationState::Conflict) {
                    throw new ActivationException('routing_conflict');
                }
                if (($journal['stage'] ?? 'committed') === 'staged' && $this->routing->current()->isSendmailSmtp()) {
                    // A process may stop after the mode write but before its journal update.
                    $journal['stage'] = 'committed';
                    $journal['state'] = 'active';
                    $this->writeJournal($journal);
                    return $this->status();
                }
                if (($journal['stage'] ?? 'committed') === 'committed') {
                    return $this->status();
                }
                if ($this->clock->now() < ($journal['ready_after'] ?? PHP_INT_MAX)) {
                    return $this->status();
                }
                if ($this->binaryFinder->findBinaryPath('sendmail') !== $this->locator->helperPath()) {
                    throw new ActivationException('helper_not_selected');
                }
                $report = $this->preflight->check(true);
                if (!$report->canActivate()) {
                    throw new ActivationException('preflight_failed', $report->failureIds());
                }
                try {
                    $this->tokens->token();
                    $beforeCommit = $this->routing->current();
                    $expected = RoutingSnapshot::fromArray($journal['previous']);
                    if ($beforeCommit->smtpMode !== $expected->smtpMode || $beforeCommit->sendmailMode !== $expected->sendmailMode
                        || $this->localResolver() !== $this->locator->helperPath()) {
                        throw new ActivationException('routing_conflict');
                    }
                    $this->routing->write(['mail_smtpmode' => 'sendmail', 'mail_sendmailmode' => 'smtp']);
                    $current = $this->routing->current();
                    if (!$current->isSendmailSmtp()) {
                        throw new \RuntimeException('routing_readback_failed');
                    }
                    $journal['stage'] = 'committed';
                    $journal['state'] = 'active';
                    $this->writeJournal($journal);
                } catch (\Throwable) {
                    $this->restoreJournal($journal);
                    throw new ActivationException('activation_failed');
                }
                return $this->status();
            }
            if ($this->routing->isReadOnly()) {
                throw new ActivationException('preflight_failed', ['config_writable']);
            }
            $report = $this->preflight->check(true);
            if (!$report->canActivate()) {
                throw new ActivationException('preflight_failed', $report->failureIds());
            }
            try {
                $this->tokens->token();
            } catch (\Throwable) {
                throw new ActivationException('credential_check_failed');
            }
            $previous = $this->routing->current();
            if ($previous->isSendmailSmtp() && $previous->hasSearchPath($this->locator->helperDirectory())) {
                throw new ActivationException('routing_conflict');
            }
            $directory = $this->locator->helperDirectory();
            $paths = $previous->searchPaths ?? self::DEFAULT_PATHS;
            if (!in_array($directory, $paths, true)) {
                array_unshift($paths, $directory);
            }
            $journal = [
                'version' => 2, 'state' => 'checking', 'stage' => 'staged',
                'ready_after' => $this->clock->now() + self::RESOLVER_GRACE_SECONDS,
                'previous' => $previous->toArray(),
                'owned' => (new RoutingSnapshot('sendmail', 'smtp', $paths))->toArray(),
            ];
            $this->writeJournal($journal);
            $this->appConfig->setValueBool(AppSettings::APP_ID, self::REQUESTED, true);
            try {
                // Keep the old transport until all pre-existing negative lookups expire.
                $this->routing->write(['binary_search_paths' => $paths]);
                if ($this->localResolver() !== $this->locator->helperPath()) {
                    throw new \RuntimeException('helper_not_selected');
                }
            } catch (\Throwable) {
                $this->restoreJournal($journal);
                throw new ActivationException('activation_failed');
            }
            return $this->status();
        } finally {
            $this->lock->release(self::LOCK);
        }
    }

    public function deactivate(bool $preserveRequest = false): ActivationStatus
    {
        if (!$this->lock->tryAcquire(self::LOCK)) {
            throw new ActivationException('activation_in_progress');
        }
        try {
            if (!$preserveRequest) {
                $this->appConfig->setValueBool(AppSettings::APP_ID, self::REQUESTED, false);
            }
            $journal = $this->journal();
            if ($journal !== null && ($journal['state'] ?? '') !== 'inactive') {
                $this->restoreJournal($journal);
            }
            return $this->status();
        } finally {
            $this->lock->release(self::LOCK);
        }
    }

    private function restoreJournal(array $journal): void
    {
        if (!$this->validJournal($journal)) {
            throw new ActivationException('routing_conflict');
        }
        $previous = RoutingSnapshot::fromArray($journal['previous']);
        $owned = RoutingSnapshot::fromArray($journal['owned']);
        $current = $this->routing->current();
        $conflicts = [];
        foreach (['mail_smtpmode' => 'smtpMode', 'mail_sendmailmode' => 'sendmailMode'] as $key => $property) {
            if ($current->$property === $previous->$property) {
                continue;
            }
            if ($current->$property !== $owned->$property) {
                $conflicts[] = $key;
                continue;
            }
            if ($previous->$property === null) {
                $this->routing->delete($key);
            } else {
                $this->routing->write([$key => $previous->$property]);
            }
        }
        $directory = $this->locator->helperDirectory();
        if (!$previous->hasSearchPath($directory) && $current->hasSearchPath($directory)) {
            $paths = array_values(array_filter($current->searchPaths, static fn (string $path): bool => $path !== $directory));
            if ($previous->searchPaths === null && ($paths === self::DEFAULT_PATHS || $paths === [])) {
                $this->routing->delete('binary_search_paths');
            } else {
                $this->routing->write(['binary_search_paths' => $paths]);
            }
        }
        if ($conflicts !== []) {
            $journal['state'] = 'conflict';
            $this->writeJournal($journal);
            throw new ActivationException('routing_conflict', $conflicts);
        }
        $this->writeJournal(['version' => 2, 'state' => 'inactive']);
        $this->appConfig->deleteKey(AppSettings::APP_ID, self::DEGRADED);
    }

    public function recordSubmissionFailure(): void
    {
        if ($this->degradedSince() === 0) {
            $this->appConfig->setValueInt(AppSettings::APP_ID, self::DEGRADED, $this->clock->now());
        }
    }
    public function recordSubmissionSuccess(): void
    {
        $this->appConfig->deleteKey(AppSettings::APP_ID, self::DEGRADED);
    }
    private function degradedSince(): int
    {
        return $this->appConfig->getValueInt(AppSettings::APP_ID, self::DEGRADED, 0);
    }
    private function requested(): bool
    {
        return $this->appConfig->getValueBool(AppSettings::APP_ID, self::REQUESTED, true);
    }
    private function localResolver(): ?string
    {
        return $this->locator->resolveSendmail($this->locator->pathEnvironment(), $this->routing->current()->searchPaths ?? self::DEFAULT_PATHS);
    }
    private function journal(): ?array
    {
        $raw = $this->appConfig->getValueString(AppSettings::APP_ID, self::JOURNAL, '', true);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : ['state' => 'conflict'];
    }
    private function validJournal(array $journal): bool
    {
        foreach (['previous', 'owned'] as $snapshot) {
            if (!is_array($journal[$snapshot] ?? null)) {
                return false;
            }
            foreach (['smtp_mode', 'sendmail_mode', 'search_paths'] as $key) {
                if (!array_key_exists($key, $journal[$snapshot])) {
                    return false;
                }
                $value = $journal[$snapshot][$key];
                if ($value === null) {
                    continue;
                }
                if ($key === 'search_paths') {
                    if (!is_array($value) || !array_is_list($value)) {
                        return false;
                    }
                    foreach ($value as $path) {
                        if (!is_string($path)) {
                            return false;
                        }
                    }
                } elseif (!is_string($value)) {
                    return false;
                }
            }
        }
        return true;
    }
    private function writeJournal(array $journal): void
    {
        $this->appConfig->setValueString(AppSettings::APP_ID, self::JOURNAL, json_encode($journal, JSON_THROW_ON_ERROR), false, true);
    }
}
