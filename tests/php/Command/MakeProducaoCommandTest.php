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

    /**
     * @dataProvider providerScenarios
     * @runInSeparateProcess
     */
    public function testScenarios(string $dataset, string $anoMes, string $expected): void
    {
        $this->loadDataset($dataset);

        $input = new ArrayInput([
            'make:producao',
            '--previsao' => true,
            '--baixar-dados' => '0',
            '--ano-mes' => $anoMes,
            '--csv' => true,
        ]);
        $output = new BufferedOutput();
        $this->application->run($input, $output);
        $this->assertEquals($expected, rtrim($output->fetch(), "\n"));
    }

    public static function providerScenarios(): array
    {
        return [
            'recebe_tudo' => [
                'recebe_tudo',
                '2023-08',
                <<<CSV
                akaunting_contact_id,auxilio,base_irpf,base_producao,bruto,dependentes,document_number,frra,health_insurance,inss,irpf,liquido,name,tax_number
                6,9685,33287.139333333,48425,34704.583333333,0,,4035.4166666667,0,1417.444,9153.9633166667,33818.176016667,"Pessoa 01",CPF_PESSOA_01
                CSV
            ],
            'recebe_metade' => [
                'recebe_metade',
                '2023-08',
                <<<CSV
                akaunting_contact_id,auxilio,base_irpf,base_producao,bruto,dependentes,document_number,frra,health_insurance,inss,irpf,liquido,name,tax_number
                6,4842.5,15934.847666667,24212.5,17352.291666667,0,,2017.7083333333,0,1417.444,4382.0831083333,16395.264558333,"Pessoa 01",CPF_PESSOA_01
                CSV
            ],
        ];
    }
}
