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

use DI\Container;
use Symfony\Component\Console\Application;

$_ENV['DB_URL'] = 'pdo-mysql://root:root@mysql/report';
$_ENV['DB_URL_AKAUNTING'] = 'pdo-mysql://root:root@mysql/akaunting';
$_ENV['AKAUNTING_COMPANY_ID'] = 1;
$_ENV['AKAUNTING_FRRA_MES_PADRAO'] = 12;
$_ENV['AKAUNTING_RESGATE_SALDO_IRPF_MES_PADRAO'] = 12;
$_ENV['AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID'] = 35;
$_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'] = 11;
$_ENV['AKAUNTING_FRRA_CATEGORY_ID'] = 33;
$_ENV['AKAUNTING_ADIANTAMENTO_CATEGORY_ID'] = 34;
$_ENV['AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'] = 29;
$_ENV['AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'] = 28;
$_ENV['AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID'] = 16;
$_ENV['AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID'] = 45;
$_ENV['AKAUNTING_IMPOSTOS_COFINS'] = '{"categoryId":40,"contactId":111,"taxId":1}';
$_ENV['AKAUNTING_IMPOSTOS_INSS_IRRF'] = '{"categoryId":38,"contactId":20,"taxId":2}';
$_ENV['AKAUNTING_IMPOSTOS_IRPF_RETIDO'] = '{"categoryId":42,"contactId":20,"taxId":2}';
$_ENV['AKAUNTING_IMPOSTOS_ISS'] = '{"categoryId":39,"contactId":111,"taxId":4}';
$_ENV['AKAUNTING_IMPOSTOS_PIS'] = '{"categoryId":41,"contactId":111,"taxId":3}';
$_ENV['AKAUNTING_IMPOSTOS_ITEM_ID'] = 14;
$_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS'] = '{"bruto":26,"INSS":28,"IRRF":29,"Plano":30,"Auxílio":32,"desconto":31,"frra":27}';
$_ENV['HOLYDAYS_LIST'] = 'br-rj';
$_ENV['LOCALE'] = 'pt_BR';
$_ENV['CNPJ_CLIENTES_INTERNOS'] = '00000000000191';
$_ENV['CNPJ_COMPANY'] = '00000000000191';

require __DIR__ . '/../../src/bootstrap.php';

class ApplicationSingleton
{
    public static Application $instance;
    public static Container $container;
    public function __construct($instance, $container)
    {
        self::$instance = $instance;
        self::$container = $container;
    }
}

new ApplicationSingleton($application, $container);
