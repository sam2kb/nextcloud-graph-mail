<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Transport;

use OCA\GraphMail\Configuration\AppSettings;
use OCA\GraphMail\Db\SubmissionLedger;
use OCA\GraphMail\Graph\GraphOutcome;
use OCA\GraphMail\Graph\GraphSender;
use OCA\GraphMail\Port\Clock;
use OCA\GraphMail\Routing\ActivationService;
use Psr\Log\LoggerInterface;

/**
 * Turns one SMTP transaction into at most one Graph submission.
 *
 * There is no SMTP fallback: an ambiguous outcome becomes a 4xx reply and is
 * never replayed by this app.
 */
final class TransportRunner implements SubmissionHandler
{
    private const TOTAL_BUDGET_SECONDS = 90;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly MessageReconciler $reconciler,
        private readonly GraphSender $sender,
        private readonly SubmissionLedger $ledger,
        private readonly ActivationService $activation,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(InboundMessage $message): SubmissionResult
    {
        if (!$this->settings->isConfigured()) {
            return SubmissionResult::permanent('not_configured');
        }

        $mailbox = $this->settings->mailbox();
        try {
            $reconciled = $this->reconciler->reconcile($message->rawMime, $message->recipients, $mailbox);
        } catch (ReconciliationException $e) {
            $this->logger->warning('Graph Mail rejected a message before submission', [
                'app' => 'graph_mail',
                'reason' => $e->reasonCode,
            ]);
            return SubmissionResult::permanent($e->reasonCode);
        }

        $correlationId = bin2hex(random_bytes(8));
        $started = $this->clock->now();
        $result = $this->sender->send($reconciled->rawMime, $mailbox, $started + self::TOTAL_BUDGET_SECONDS);
        $durationMs = max(0, $this->clock->now() - $started) * 1000;

        $submission = match ($result->outcome) {
            GraphOutcome::Accepted => SubmissionResult::accepted($correlationId),
            GraphOutcome::Rejected => SubmissionResult::permanent($result->reason),
            GraphOutcome::Temporary => SubmissionResult::temporary($result->reason),
            GraphOutcome::Unknown => SubmissionResult::temporary('submission_outcome_unknown'),
        };
        $context = [
            'app' => 'graph_mail', 'correlation' => $correlationId,
            'outcome' => $result->outcome->value, 'reason' => $result->reason,
            'duration_ms' => $durationMs, 'provider_request' => $result->requestId,
        ];
        try {
            $this->ledger->record($correlationId, $this->fingerprint($reconciled->rawMime), $result, $durationMs);
        } catch (\Throwable) {
            // Audit storage cannot change the provider's submission outcome.
        }
        try {
            if ($result->outcome === GraphOutcome::Accepted) {
                $this->activation->recordSubmissionSuccess();
            } elseif ($result->outcome !== GraphOutcome::Rejected
                || in_array($result->reason, ['permission_denied', 'mailbox_not_found', 'unauthorized_after_refresh'], true)
                || str_starts_with($result->reason, 'token_')) {
                $this->activation->recordSubmissionFailure();
            }
        } catch (\Throwable) {
            // A health marker must never turn accepted mail into a retry.
        }
        try {
            $this->logger->log($result->outcome === GraphOutcome::Accepted ? 'info' : 'warning', 'Graph Mail submission outcome: {outcome}', $context);
        } catch (\Throwable) {
            // The caller still receives the established result if logging fails.
        }
        return $submission;
    }

    private function fingerprint(string $rawMime): string
    {
        return hash_hmac('sha256', $rawMime, $this->settings->ledgerKey());
    }
}
