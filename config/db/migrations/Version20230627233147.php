<?php
/**
 * @copyright Copyright (c) 2023, Vitor Mattos <vitor@php.rio>
 *
 * @author Vitor Mattos <vitor@php.rio>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=1);

namespace ProducaoCooperativista\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230627233147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Nfse';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('nfse');
        $table->addColumn('numero', 'bigint', ['unsigned' => true]);
        $table->addColumn('numero_substituta', 'bigint', ['notnull' => false]);
        $table->addColumn('cnpj', 'string', ['length' => 14]);
        $table->addColumn('razao_social', 'string');
        $table->addColumn('data_emissao', 'datetime');
        $table->addColumn('valor_servico', 'float');
        $table->addColumn('valor_cofins', 'float');
        $table->addColumn('valor_ir', 'float');
        $table->addColumn('valor_pis', 'float');
        $table->addColumn('valor_iss', 'float');
        $table->addColumn('discriminacao_normalizada', 'text');
        $table->addColumn('setor', 'string', ['notnull' => false]);
        $table->addColumn('codigo_cliente', 'string');
        $table->addColumn('metadata', 'json');
        $table->setPrimaryKey(['numero']);
        $table->addUniqueIndex(['numero']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('nfse');
    }
}
