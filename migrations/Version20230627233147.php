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

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
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
        $table->addColumn('numero', Types::BIGINT, ['unsigned' => true]);
        $table->addColumn('numero_substituta', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('cnpj', Types::STRING, ['length' => 14]);
        $table->addColumn('razao_social', Types::STRING, ['length' => 255]);
        $table->addColumn('data_emissao', Types::DATETIME_MUTABLE);
        $table->addColumn('valor_servico', Types::FLOAT);
        $table->addColumn('valor_cofins', Types::FLOAT);
        $table->addColumn('valor_ir', Types::FLOAT);
        $table->addColumn('valor_pis', Types::FLOAT);
        $table->addColumn('valor_iss', Types::FLOAT);
        $table->addColumn('discriminacao_normalizada', Types::TEXT);
        $table->addColumn('setor', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('codigo_cliente', Types::STRING, ['length' => 255]);
        $table->addColumn('metadata', Types::JSON);
        $table->setPrimaryKey(['numero']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('nfse');
    }
}
