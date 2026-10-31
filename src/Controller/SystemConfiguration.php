<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class SystemConfiguration extends AbstractController
{
    #[Route('/configuracao/integracoes', methods: ['GET'])]
    public function index(): Response
    {
        // Presence checks only. Never expose credentials, URLs or tax identifiers.
        $requirements = [
            [
                'title' => 'Nextcloud · Profile Fields',
                'purpose' => 'Cadastro de referência: CPF/CNPJ, peso e dependentes.',
                'keys' => ['PROFILE_FIELDS_API_BASE_URL', 'PROFILE_FIELDS_AUTH_USER', 'PROFILE_FIELDS_AUTH_TOKEN'],
            ],
            [
                'title' => 'Kimai',
                'purpose' => 'Contas de trabalho e apontamentos de horas.',
                'keys' => ['KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN'],
            ],
            [
                'title' => 'Akaunting',
                'purpose' => 'Contatos, lançamentos e documentos financeiros.',
                'keys' => [
                    'AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER',
                    'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID', 'DATABASE_AKAUNTING_URL',
                ],
            ],
            [
                'title' => 'Apuração cooperativista',
                'purpose' => 'Banco de importação, perfil, categorias, itens e parâmetros financeiros.',
                'keys' => [
                    'DATABASE_URL', 'COOPERATIVE_PROFILE', 'CNPJ_COMPANY',
                    'CNPJ_CLIENTES_INTERNOS', 'DIA_UTIL_PAGAMENTO', 'PERCENTUAL_MAXIMO',
                ],
            ],
            [
                'title' => 'Categorias e retenções financeiras',
                'purpose' => 'IDs do plano de contas e mapeamentos fiscais utilizados nos documentos.',
                'keys' => [
                    'AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID',
                    'AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS',
                    'AKAUNTING_FRRA_CATEGORY_ID', 'AKAUNTING_ADIANTAMENTO_CATEGORY_ID',
                    'AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID',
                    'AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID',
                    'AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID',
                    'AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID',
                    'AKAUNTING_IMPOSTOS_ITEM_ID',
                    'AKAUNTING_IMPOSTOS_COFINS', 'AKAUNTING_IMPOSTOS_INSS_IRRF',
                    'AKAUNTING_IMPOSTOS_IRPF_RETIDO', 'AKAUNTING_IMPOSTOS_ISS',
                    'AKAUNTING_IMPOSTOS_PIS',
                ],
            ],
        ];

        foreach ($requirements as &$requirement) {
            $missing = array_filter($requirement['keys'], static fn (string $key): bool => trim((string) getenv($key)) === '');
            $requirement['configured'] = $missing === [];
            $requirement['configuredCount'] = count($requirement['keys']) - count($missing);
            $requirement['totalCount'] = count($requirement['keys']);
        }
        unset($requirement);

        $response = $this->render('configuracao.integracoes.html.twig', ['requirements' => $requirements]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
