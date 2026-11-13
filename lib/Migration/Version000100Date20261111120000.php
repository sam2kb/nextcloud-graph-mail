<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 sam2kb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GraphMail\Migration;

use Closure;
use OCA\GraphMail\Db\SubmissionLedger;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

final class Version000100Date20261111120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if ($schema->hasTable(SubmissionLedger::TABLE)) {
            return null;
        }

        $table = $schema->createTable(SubmissionLedger::TABLE);
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('state', Types::STRING, [
            'notnull' => true,
            'length' => 16,
        ]);
        $table->addColumn('reason', Types::STRING, [
            'notnull' => true,
            'length' => 64,
            'default' => '',
        ]);
        $table->addColumn('correlation_id', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('fingerprint', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('duration_ms', Types::INTEGER, [
            'notnull' => true,
            'unsigned' => true,
            'default' => 0,
        ]);
        $table->addColumn('http_status', Types::INTEGER, [
            'notnull' => false,
            'unsigned' => true,
        ]);
        $table->addColumn('request_id', Types::STRING, [
            'notnull' => true,
            'length' => 128,
            'default' => '',
        ]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['created_at'], 'graph_mail_ledger_created');
        $table->addIndex(['correlation_id'], 'graph_mail_ledger_corr');

        return $schema;
    }
}
