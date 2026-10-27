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

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Index extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('index.index.html.twig', [
            'relatorios' => [
                'calculos' => [
                    'url' => $this->urlGenerator->generate('app_calculos_index'),
                    'label' => 'Cálculos',
                    'description' => 'Bases, fórmulas e critérios utilizados na apuração.',
                ],
                'categorias' => [
                    'url' => $this->urlGenerator->generate('app_categorias_index'),
                    'label' => 'Categorias',
                    'description' => 'Classificações utilizadas para receitas e despesas.',
                ],
                'invoices' => [
                    'url' => $this->urlGenerator->generate('app_invoices_index'),
                    'label' => 'Entradas e saídas',
                    'description' => 'Recebimentos e pagamentos considerados no período.',
                ],
                'capital-social-summarized' => [
                    'url' => $this->urlGenerator->generate('app_capitalsocialsummarized_index'),
                    'label' => 'Capital social',
                    'description' => 'Posição das movimentações de capital por cooperado.',
                ],
                'producao' => [
                    'url' => $this->urlGenerator->generate('app_producao_index'),
                    'label' => 'Produção',
                    'description' => 'Distribuição da produção por cooperado e competência.',
                ],
            ],
            'acoes' => [
                'zerar_banco_local' => [
                    'url' => $this->urlGenerator->generate('app_acoes_zerarbancolocal'),
                    'label' => 'Limpar dados importados (ETL)',
                    'description' => 'Reinicia apenas a base intermediária de importação.',
                    'danger' => true,
                ],
                'executa_producao' => [
                    'url' => $this->urlGenerator->generate('app_acoes_makeproducao'),
                    'label' => 'Processar produção',
                    'description' => 'Importe dados, calcule uma prévia ou publique após aprovação.',
                ],
            ],
        ]);
    }
}
