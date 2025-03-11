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

return [
    ['name' => 'Acoes#doMakeProducao', 'path' => '/acoes/do-make-producao'],
    ['name' => 'Acoes#makeProducao', 'path' => '/acoes/make-producao'],
    ['name' => 'Acoes#zerarBancoLocal', 'path' => '/acoes/zerar-banco-local'],
    ['name' => 'Api\Calculos#index', 'path' => '/api/v1/calculos'],
    ['name' => 'Api\CapitalSocial#index', 'path' => '/api/v1/capital-social'],
    ['name' => 'Api\CapitalSocialSummarized#index', 'path' => '/api/v1/capital-social-summarized'],
    ['name' => 'Api\Categorias#index', 'path' => '/api/v1/categorias'],
    ['name' => 'Api\Invoices#index', 'path' => '/api/v1/invoices'],
    ['name' => 'Api\Producao#index', 'path' => '/api/v1/producao'],
    ['name' => 'Api\TrabalhadoSummarized#index', 'path' => '/api/v1/trabalhado-summarized'],
    ['name' => 'Calculos#index', 'path' => '/calculos'],
    ['name' => 'CapitalSocial#index', 'path' => '/capital-social'],
    ['name' => 'CapitalSocialSummarized#index', 'path' => '/capital-social-summarized'],
    ['name' => 'Categorias#index', 'path' => '/categorias'],
    ['name' => 'Index#index', 'path' => '/'],
    ['name' => 'Invoices#index', 'path' => '/invoices'],
    ['name' => 'Producao#index', 'path' => '/producao'],
    ['name' => 'TrabalhadoSummarized#index', 'path' => '/trabalhado-summarized'],
];
