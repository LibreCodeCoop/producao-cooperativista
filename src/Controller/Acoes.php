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

use ProducaoCooperativista\Core\App;
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
}
