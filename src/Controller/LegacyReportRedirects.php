<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Keep previously shared links working without maintaining duplicate report endpoints.
 *
 * @psalm-suppress UnusedClass Routed by Symfony attributes.
 */
final class LegacyReportRedirects extends AbstractController
{
    private const array ROUTES = [
        'calculos' => 'app_calculos_index',
        'categorias' => 'app_categorias_index',
        'invoices' => 'app_invoices_index',
        'producao' => 'app_producao_index',
        'capital-social' => 'app_capitalsocial_index',
        'capital-social-summarized' => 'app_capitalsocialsummarized_index',
        'trabalhado-summarized' => 'app_trabalhadosummarized_index',
    ];

    #[Route('/{report}', requirements: ['report' => 'calculos|categorias|invoices|producao|capital-social|capital-social-summarized|trabalhado-summarized'], methods: ['GET'])]
    public function report(Request $request, string $report): RedirectResponse
    {
        return $this->redirectToRoute(self::ROUTES[$report], $request->query->all());
    }

    #[Route('/calculos/ajuda', methods: ['GET'])]
    public function help(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_calculos_ajuda', $request->query->all());
    }
}
