<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20230803185730 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Taxes';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('taxes');
        $table->addColumn('id', Types::INTEGER, ['unsigned' => true, 'autoincrement' => false]);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('rate', Types::FLOAT);
        $table->addColumn('enabled', Types::SMALLINT, ['default' => 1]);
        $table->addColumn('metadata', Types::JSON);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('taxes');
    }
}
