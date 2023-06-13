<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Nfse extends AbstractMigration
{
    public function change(): void
    {
        $users = $this->table('nfse');
        $users
            ->addColumn('numero', 'biginteger')
            ->addColumn('numero_substituta', 'biginteger')
            ->addColumn('cnpj', 'string', ['limit' => 14])
            ->addColumn('razao_social', 'string', ['limit' => 255])
            ->addColumn('data_emissao', 'date')
            ->addColumn('valor_servico', 'double')
            ->addColumn('valor_cofins', 'double')
            ->addColumn('valor_ir', 'double')
            ->addColumn('valor_pis', 'double')
            ->addColumn('valor_iss', 'double')
            ->addColumn('discriminacao_normalizada', 'text')
            ->addColumn('setor', 'string')
            ->addColumn('codigo_cliente', 'string')
            ->addColumn('metadata', 'text')
            ->create();
    }
}
