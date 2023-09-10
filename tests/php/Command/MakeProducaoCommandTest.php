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

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Php\TestCase;

final class MakeProducaoCommandTest extends TestCase
{
    private Application $application;
    protected function setUp(): void
    {
        $this->application = ApplicationSingleton::$instance;
        $this->application->setAutoExit(false);
    }

    public function testBla(): void
    {
        $this->loadDataset('full');

        $input = new ArrayInput([
            'make:producao',
            '--previsao' => true,
            '--baixar-dados' => '0',
            '--ano-mes' => '2023-08',
            '--csv' => true,
        ]);
        $output = new BufferedOutput();
        $this->application->run($input, $output);
        $expected = <<<CSV
            akaunting_contact_id,auxilio,base_irpf,base_producao,bruto,dependentes,document_number,frra,health_insurance,inss,irpf,liquido,name,tax_number
            6,944.13420861443,2706.5180646947,4720.6710430721,3383.1475808684,0,,393.38925358934,0,676.62951617367,202.9888548521,3447.663418457,"Pessoa 01",CPF_PESSOA_01
            7,3776.5368344577,11925.556323473,18882.684172289,13532.590323473,1,,1573.5570143574,0,1417.444,2394.5679889552,13497.115168976,"Pessoa 02",CPF_PESSOA_02
            CSV;
        $this->assertEquals($expected, rtrim($output->fetch(), "\n"));
    }
}
