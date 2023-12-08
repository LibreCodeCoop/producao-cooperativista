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

namespace ProducaoCooperativista\Controller;

use Monolog\Logger;
use ProducaoCooperativista\Core\App;
use ProducaoCooperativista\Helper\ArrayValue;
use ProducaoCooperativista\Helper\SseLogHandler;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;

class Acoes
{
    public function __construct(
        private UrlGenerator $urlGenerator,
        private Request $request,
        private Logger $logger,
        private SseLogHandler $sseLogHandler,
    ) {
    }

    public function zerarBancoLocal(): Response
    {
        $bufferedOutput = $this->executaMigrations('first');
        $response = $bufferedOutput->fetch();
        $bufferedOutput = $this->executaMigrations('latest');
        $response .= $bufferedOutput->fetch();

        $response = new Response(
            App::get(\Twig\Environment::class)
                ->load('acoes/zerar_banco_local.html.twig')
                ->render(compact('response'))
        );
        return $response;
    }

    private function executaMigrations(string $migration): BufferedOutput
    {
        $application = App::get(Application::class);
        $application->setAutoExit(false);
        $input = new ArrayInput([
            'migrations:migrate',
            '-n' => 0,
            'version' => $migration,
        ]);
        $output = new BufferedOutput();
        $application->run($input, $output);
        return $output;
    }

    public function makeProducao(): Response
    {
        $inicio = new \DateTime();
        $inicio->modify('-2 month');

        $response = new Response(
            App::get(\Twig\Environment::class)
                ->load('acoes/make_producao.html.twig')
                ->render([
                    'inicio_ano' => $inicio->format('Y'),
                    'inicio_mes' => $inicio->format('m'),
                    'url' => $this->urlGenerator->generate('Acoes#doMakeProducao'),
                    'baixar_dados' => $this->request->get('baixar_dados', 0) ? 1 : 0,
                    'atualiza_producao' => $this->request->get('atualiza_producao', 0) ? 1 : 0,
                ])
        );
        return $response;
    }

    public function doMakeProducao(): Response
    {
        $this->logger->pushHandler($this->sseLogHandler);
        $inicio = \DateTime::createFromFormat(
            'Y-m',
            $this->request->get('year', '') . '-' . $this->request->get('month', '')
        );
        if (!$inicio instanceof \DateTime) {
            throw new \BadMethodCallException('The query string year need to be as format Y-m');
        }

        $application = App::get(Application::class);
        $application->setAutoExit(false);
        $input = new ArrayInput([
            'make:producao',
            '--ano-mes' => $inicio->format('Y-m'),
            '--baixar-dados' => $this->request->get('baixar_dados', '0'),
            '--atualiza-producao' => (bool) $this->request->get('atualzia_producao', false),
            '--database' => true,
        ]);
        $output = new BufferedOutput();
        $application->run($input, $output);
        $this->logger->info(new ArrayValue(['event' => 'done', 'data' =>  'Fim']));
        return new Response();
    }
}
