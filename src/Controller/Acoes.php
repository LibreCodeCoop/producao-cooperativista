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

use App\Helper\ArrayValue;
use App\Helper\SseLogHandler;
use App\Kernel;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class Acoes extends AbstractController
{
    private Request $request;
    private Logger $logger;
    public function __construct(
        RequestStack $requestStack,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'monolog.logger')]
        Logger $logger,
        private SseLogHandler $sseLogHandler,
        private Kernel $kernel,
    ) {
        $this->request = $requestStack->getCurrentRequest();
        $this->logger = $logger;
        $this->logger->pushHandler($this->sseLogHandler);
    }

    #[Route('/acoes/zerar-banco-local', methods: ['GET'])]
    public function zerarBancoLocal(): Response
    {
        $response = '';

        foreach ($this->getResetDatabaseCommands() as $command) {
            $bufferedOutput = $this->executaComando($command);
            $response .= $bufferedOutput->fetch();
        }

        return $this->render('acoes/zerar_banco_local.html.twig', compact('response'));
    }

    /**
     * @return array<int, array<string, bool|string>>
     */
    private function getResetDatabaseCommands(): array
    {
        return [
            [
                'command' => 'doctrine:schema:drop',
                '--full-database' => true,
                '--force' => true,
            ],
            [
                'command' => 'doctrine:migrations:sync-metadata-storage',
                '--no-interaction' => true,
            ],
            [
                'command' => 'doctrine:migrations:migrate',
                '--no-interaction' => true,
                'version' => 'latest',
            ],
        ];
    }

    /**
     * @param array<string, bool|string> $arguments
     */
    private function executaComando(array $arguments): BufferedOutput
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $input = new ArrayInput($arguments);
        $output = new BufferedOutput();
        $application->run($input, $output);
        return $output;
    }

    #[Route('/acoes/make-producao', methods: ['GET'])]
    public function makeProducao(): Response
    {
        $inicio = new \DateTime();
        $inicio->modify('-2 month');

        return $this->render('acoes/make_producao.html.twig', [
            'inicio_ano' => $inicio->format('Y'),
            'inicio_mes' => $inicio->format('m'),
            'url' => $this->urlGenerator->generate('app_acoes_domakeproducao'),
            'baixar_dados' => $this->request->query->getBoolean('baixar_dados') ? 1 : 0,
            'atualiza_producao' => $this->request->query->getBoolean('atualiza_producao') ? 1 : 0,
        ]);
    }

    #[Route('/acoes/do-make-producao', methods: ['GET'])]
    public function doMakeProducao(): Response
    {
        $inicio = \DateTime::createFromFormat(
            'Y-m',
            $this->request->query->getString('year') . '-' . $this->request->query->getString('month')
        );
        if (!$inicio instanceof \DateTime) {
            throw new \BadMethodCallException('The query string year need to be as format Y-m');
        }

        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $input = new ArrayInput([
            'make:producao',
            '--ano-mes' => $inicio->format('Y-m'),
            '--baixar-dados' => $this->request->query->getString('baixar_dados', '0'),
            '--atualiza-producao' => $this->request->query->getBoolean('atualiza_producao'),
            '--database' => true,
            '--pesos' => $this->request->query->getString('pesos'),
        ]);
        $output = new BufferedOutput();
        $exitCode = $application->run($input, $output);
        if ($exitCode !== 0) {
            $this->logger->error('Erro: {erro}', ['erro' => $output->fetch()]);
        }
        $this->logger->info(new ArrayValue(['event' => 'done', 'data' =>  'Fim']));
        return new Response();
    }
}
