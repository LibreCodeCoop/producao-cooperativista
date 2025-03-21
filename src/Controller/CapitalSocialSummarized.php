<?php

/**
 * @copyright Copyright (c) 2024, Vitor Mattos <vitor@php.rio>
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
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CapitalSocialSummarized extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[Route('/capital-social-summarized', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('capitalSocialSummarized.index.html.twig', [
            'url' => $this->urlGenerator->generate(
                'app_api_capitalsocialsummarized_index'
            )
        ]);
        return $response;
    }
}
