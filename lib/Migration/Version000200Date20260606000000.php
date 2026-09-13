<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20260606000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('media_embed_idx_files')) {
            $table = $schema->createTable('media_embed_idx_files');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('storage_id', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 127]);
            $table->addColumn('size_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('mtime', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('index_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('model_fingerprint', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('indexed_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id'], 'media_embed_file_id_uq');
            $table->addIndex(['owner_uid'], 'media_embed_file_owner_idx');
            $changed = true;
        }

        if (!$schema->hasTable('media_embed_idx_jobs')) {
            $table = $schema->createTable('media_embed_idx_jobs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('owner_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('etag', Types::STRING, ['notnull' => false, 'length' => 128]);
            $table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('next_attempt_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('claimed_at', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['status', 'next_attempt_at'], 'media_embed_job_ready_idx');
            $table->addIndex(['file_id'], 'media_embed_job_file_idx');
            $changed = true;
        }

        if (!$schema->hasTable('media_embed_skips')) {
            $table = $schema->createTable('media_embed_skips');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 128]);
            $table->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('model_id', Types::STRING, ['notnull' => false, 'length' => 128]);
            $table->addColumn('model_version', Types::STRING, ['notnull' => false, 'length' => 128]);
            $table->addColumn('model_fingerprint', Types::STRING, ['notnull' => false, 'length' => 128]);
            $table->addColumn('embedding_dim', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id'], 'media_embed_skip_file_uq');
            $table->addIndex(['reason'], 'media_embed_skip_reason_idx');
            $changed = true;
        }

        if (!$schema->hasTable('media_embed_state')) {
            $table = $schema->createTable('media_embed_state');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('state_key', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('state_value', Types::TEXT, ['notnull' => true]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['state_key'], 'media_embed_state_key_uq');
            $changed = true;
        }

        if (!$schema->hasTable('media_embed_audit')) {
            $table = $schema->createTable('media_embed_audit');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('actor_uid', Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('target_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('result', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('details', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['created_at'], 'media_embed_audit_time_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
