<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Db;

use OCA\GraphMail\Graph\GraphSendResult;
use OCA\GraphMail\Port\Clock;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Content-free record of submission outcomes. It is not a queue and not a
 * delivery receipt: it exists to make "accepted / known rejection / unknown"
 * auditable without retaining messages, subjects or addresses.
 */
final class SubmissionLedger
{
    public const TABLE = 'graph_mail_submissions';
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly IDBConnection $db,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function record(string $correlationId, string $fingerprint, GraphSendResult $result, int $durationMs): void
    {
        try {
            $query = $this->db->getQueryBuilder();
            $query->insert(self::TABLE)
                ->values([
                    'created_at' => $query->createNamedParameter($this->clock->now(), IQueryBuilder::PARAM_INT),
                    'state' => $query->createNamedParameter($result->outcome->value),
                    'reason' => $query->createNamedParameter(substr($result->reason, 0, 64)),
                    'correlation_id' => $query->createNamedParameter(substr($correlationId, 0, 32)),
                    'fingerprint' => $query->createNamedParameter(substr($fingerprint, 0, 64)),
                    'duration_ms' => $query->createNamedParameter(max(0, $durationMs), IQueryBuilder::PARAM_INT),
                    'http_status' => $query->createNamedParameter($result->httpStatus, IQueryBuilder::PARAM_INT),
                    'request_id' => $query->createNamedParameter(substr($result->requestId, 0, 128)),
                ])
                ->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->warning('Graph Mail could not write the submission ledger entry', [
                'app' => 'graph_mail',
                'exception' => $e::class,
            ]);
        }
    }

    public function prune(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = $this->clock->now() - max(1, $days) * 86400;
        $query = $this->db->getQueryBuilder();
        $query->delete(self::TABLE)
            ->where($query->expr()->lt('created_at', $query->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
        return $query->executeStatement();
    }

    /**
     * @return array<string, int> counts by state, for diagnostics only
     */
    public function countsSince(int $since): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('state')
            ->selectAlias($query->func()->count('id'), 'amount')
            ->from(self::TABLE)
            ->where($query->expr()->gte('created_at', $query->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
            ->groupBy('state');
        $result = $query->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(string)$row['state']] = (int)$row['amount'];
        }
        $result->closeCursor();
        return $counts;
    }
}
