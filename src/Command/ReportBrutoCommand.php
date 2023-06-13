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

namespace ProducaoCooperativista\Command;

use DateTime;
use ProducaoCooperativista\Service\BaseCalculo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'report:bruto',
    description: 'Bruto de produção cooperativista por cooperado'
)]
class ReportBrutoCommand extends BaseCommand
{
    public function __construct(
        private BaseCalculo $baseCalculo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'previsao',
                null,
                InputOption::VALUE_NONE,
                'Previsão de cálculo e não o valor real com base nas NFSe.'
            )
            ->addOption(
                'ano-mes',
                null,
                InputOption::VALUE_REQUIRED,
                'Ano e mês para gerar a produção cooperativista, formato: YYYY-mm'
            )
            ->addOption(
                'dias-uteis',
                null,
                InputOption::VALUE_REQUIRED,
                'Total de dias úteis no mês trabalhado',
                22
            )
            ->addOption(
                'percentual-maximo',
                null,
                InputOption::VALUE_REQUIRED,
                'Percentual máximo para pagamento de dispêndios',
                25
            )
            ->addOption(
                'atualizar-dados',
                null,
                InputOption::VALUE_REQUIRED,
                'Acessa todas as bases externas e atualiza o banco de dados. Valores: 1 = sim, 0 = não.',
                1
            )
            ->addOption(
                'ods',
                null,
                InputOption::VALUE_NONE,
                'To output as ods'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('ano-mes')) {
            $output->writeln('<error>--ano-mes é obrigatório</error>');
            return Command::INVALID;
        }
        $diasUteis = (int) $input->getOption('dias-uteis');
        $percentualMaximo = (int) $input->getOption('percentual-maximo');
        $previsao = (bool) $input->getOption('previsao');
        $inicio = DateTime::createFromFormat('Y-m', $input->getOption('ano-mes'));
        if ((bool) $input->getOption('atualizar-dados')) {
            $this->baseCalculo->loadFromExternalSources($inicio);
        }
        $this->baseCalculo->setInicio($inicio);
        $this->baseCalculo->setDiasUteis($diasUteis);
        $this->baseCalculo->setPercentualMaximo($percentualMaximo);
        $this->baseCalculo->setPrevisao($previsao);
        $list = $this->baseCalculo->getBrutoPorCooperado();

        if ($input->getOption('csv')) {
            $output->writeLn('cooperado,total');
            foreach ($list as $cooperado => $total) {
                $output->writeLn($cooperado . ',' . $total);
            }
        }

        if ($input->getOption('ods')) {
            $this->baseCalculo->saveOds();
        }
        return Command::SUCCESS;
    }
}
