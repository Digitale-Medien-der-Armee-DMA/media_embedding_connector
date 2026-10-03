<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Migration;

use Closure;
use OCP\DB\{ISchemaWrapper, Types};
use OCP\Migration\{IOutput, SimpleMigrationStep};

class Version000404Date20261004000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();
        if (!$schema->hasTable('media_embed_structure')) {
            $table = $schema->createTable('media_embed_structure');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('root_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('impact_roots', Types::TEXT, ['notnull' => false]);
            $table->addColumn('delete_subtree', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $table->addColumn('cursor_value', Types::STRING, ['notnull' => true, 'default' => '', 'length' => 64]);
            $table->addColumn('index_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['index_name'], 'media_embed_structure_idx');
        }
        if (!$schema->hasTable('media_embed_struct_err')) {
            $table = $schema->createTable('media_embed_struct_err');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('index_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('last_error', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('storage_path', Types::TEXT, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['file_id', 'index_name'], 'media_embed_struct_err_unique');
        }
        return $schema;
    }
}
