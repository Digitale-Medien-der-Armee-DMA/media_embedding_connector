<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000205Date20260618000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('media_embed_idx_jobs')) {
            $table = $schema->getTable('media_embed_idx_jobs');
            if (!$table->hasColumn('job_source')) {
                $table->addColumn('job_source', Types::STRING, [
                    'notnull' => true,
                    'length' => 32,
                    'default' => 'interactive',
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('priority')) {
                $table->addColumn('priority', Types::INTEGER, [
                    'notnull' => true,
                    'default' => 10,
                ]);
                $changed = true;
            }
            if (!$table->hasColumn('last_status_code')) {
                $table->addColumn('last_status_code', Types::INTEGER, ['notnull' => false]);
                $changed = true;
            }
            if (!$table->hasIndex('media_embed_job_lane_idx')) {
                $table->addIndex(['status', 'action', 'priority', 'next_attempt_at'], 'media_embed_job_lane_idx');
                $changed = true;
            }
        }

        if ($schema->hasTable('media_embed_idx_files')) {
            $table = $schema->getTable('media_embed_idx_files');
            if (!$table->hasColumn('model_id')) {
                $table->addColumn('model_id', Types::STRING, ['notnull' => false, 'length' => 128]);
                $changed = true;
            }
            if (!$table->hasColumn('model_version')) {
                $table->addColumn('model_version', Types::STRING, ['notnull' => false, 'length' => 128]);
                $changed = true;
            }
            if (!$table->hasColumn('embedding_dim')) {
                $table->addColumn('embedding_dim', Types::INTEGER, ['notnull' => false]);
                $changed = true;
            }
            if (!$table->hasColumn('contract_version')) {
                $table->addColumn('contract_version', Types::STRING, ['notnull' => false, 'length' => 64]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
