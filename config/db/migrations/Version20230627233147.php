<?php
declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Invoices';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('nfse');
        $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
        $table->addColumn('numero', 'bigint', ['unsigned' => true]);
        $table->addColumn('numero_substituta', 'bigint');
        $table->addColumn('cnpj', 'string', ['length' => 14]);
        $table->addColumn('razao_social', 'string', ['length' => 255]);
        $table->addColumn('data_emissao', 'date');
        $table->addColumn('valor_servico', 'float');
        $table->addColumn('valor_cofins', 'float');
        $table->addColumn('valor_ir', 'float');
        $table->addColumn('valor_pis', 'float');
        $table->addColumn('valor_iss', 'float');
        $table->addColumn('discriminacao_normalizada', 'text');
        $table->addColumn('setor', 'string');
        $table->addColumn('codigo_cliente', 'string');
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['numero']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('nfse');
    }
}
