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

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Php\TestCase;

final class MakeProducaoCommandTest extends TestCase
{
    private Application $application;
    protected function setUp(): void
    {
        $kernel = static::bootKernel();
        $this->application = new Application($kernel);
        $this->application->setAutoExit(false);
    }

    #[DataProvider('providerScenarios')]
    #[RunInSeparateProcess]
    public function testScenarios(string $dataset, string $anoMes, string $expected): void
    {
        $this->loadDataset($dataset);

        $input = new ArrayInput([
            'make:producao',
            '--baixar-dados' => '0',
            '--ano-mes' => $anoMes,
            '--csv' => true,
        ]);
        $output = new BufferedOutput();
        $this->application->run($input, $output);
        $this->assertEquals($expected, rtrim($output->fetch(), "\n"));
    }

    #[RunInSeparateProcess]
    public function testDryRunNeverCreatesMissingAkauntingContacts(): void
    {
        $this->loadDataset('recebe_tudo');

        $manager = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->getConnection()->executeStatement('UPDATE users SET akaunting_contact_id = NULL WHERE id = 2');
        $manager->clear();

        $input = new ArrayInput([
            'make:producao',
            '--baixar-dados' => '0',
            '--ano-mes' => '2023-08',
            '--csv' => true,
        ]);
        $output = new BufferedOutput();
        $exitCode = $this->application->run($input, $output);

        self::assertSame(\Symfony\Component\Console\Command\Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Pessoa 02', $output->fetch());
        self::assertNull(
            $manager->getConnection()->fetchOne('SELECT akaunting_contact_id FROM users WHERE id = 2') ?: null
        );
    }

    public static function providerScenarios(): array
    {
        return [
            'recebe_tudo' => [
                'recebe_tudo',
                '2023-08',
                <<<CSV
                akaunting_contact_id,auxilio,base_irpf,base_producao,bruto,dependentes,document_number,peso,frra,liquid_discount,inss,irpf,adiantamento,liquido,name,tax_number
                6,5000,16415.17,25000,17916.67,0,PDC_CPF______02-2023-09,1,2083.33,0,1501.5,3629.21,0,17785.96,"Pessoa 02",CPF______02
                7,5000,16225.58,25000,17916.67,1,PDC_CPF______03-2023-09,1,2083.33,0,1501.5,3577.07,0,17838.1,"Pessoa 03",CPF______03
                CSV
            ],
            'recebe_metade' => [
                'recebe_metade',
                '2023-08',
                <<<CSV
                akaunting_contact_id,auxilio,base_irpf,base_producao,bruto,dependentes,document_number,peso,frra,liquid_discount,inss,irpf,adiantamento,liquido,name,tax_number
                6,3333.33,10442.95,16666.67,11944.45,0,PDC_CPF______02-2023-09,1,1388.89,0,1501.5,1986.85,0,11789.43,"Pessoa 02",CPF______02
                7,6666.67,22197.79,33333.33,23888.88,1,PDC_CPF______03-2023-09,2,2777.78,0,1501.5,5219.43,0,23834.62,"Pessoa 03",CPF______03
                CSV
            ],
        ];
    }
}
