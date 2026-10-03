<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000403Date20261003000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if ($schema->hasTable('media_embed_search')) {
            return null;
        }
        $table = $schema->createTable('media_embed_search');
        $table->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('payload', Types::TEXT, ['notnull' => true]);
        $table->setPrimaryKey(['token_hash']);
        $table->addIndex(['expires_at'], 'media_embed_search_exp_idx');
        return $schema;
    }
}
