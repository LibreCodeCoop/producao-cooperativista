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
use ProducaoCooperativista\Service\ProducaoCooperativista;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'make:producao',
    description: 'Produção cooperativista por cooperado'
)]
class MakeProducaoCommand extends BaseCommand
{
    public function __construct(
        private ProducaoCooperativista $producaoCooperativista
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
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
                'Total de dias úteis no mês trabalhado. Se não informar, irá calcular com base nos dias úteis de um mês considerando apenas feriados nacionais.'
            )
            ->addOption(
                'dia-util-pagamento',
                null,
                InputOption::VALUE_REQUIRED,
                'Número ordinal do dia útil quando o pagamento será feito',
                5
            )
            ->addOption(
                'percentual-maximo',
                null,
                InputOption::VALUE_REQUIRED,
                'Percentual máximo para pagamento de dispêndios',
                25
            )
            ->addOption(
                'baixar-dados',
                null,
                InputOption::VALUE_REQUIRED,
                'Acessa todas as bases externas e atualiza o banco de dados local. Valores: 1 = sim, 0 = não.',
                1
            )
            ->addOption(
                'atualiza-producao',
                null,
                InputOption::VALUE_NONE,
                'Atualiza a produção cooperativista no Akaunting'
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
        $diaUtilPagamento = (int) $input->getOption('dia-util-pagamento');
        $inicio = DateTime::createFromFormat('Y-m', $input->getOption('ano-mes'));
        if (!$inicio instanceof DateTime) {
            $output->writeln('<error>--ano-mes precisa estar no formato YYYY-MM</error>');
            return Command::INVALID;
        }
        if ((bool) $input->getOption('baixar-dados')) {
            $this->producaoCooperativista->loadFromExternalSources($inicio);
        }
        $this->producaoCooperativista->dates->setDiaUtilPagamento($diaUtilPagamento);
        $this->producaoCooperativista->dates->setInicio($inicio);
        $this->producaoCooperativista->dates->setDiasUteis($diasUteis);
        $this->producaoCooperativista->setPercentualMaximo($percentualMaximo);

        if ($input->getOption('atualiza-producao')) {
            $this->producaoCooperativista->updateProducao();
        }

        if ($input->getOption('csv')) {
            $output->writeLn(
                $this->producaoCooperativista->exportToCsv()
            );
        }
        if ($input->getOption('ods')) {
            $this->producaoCooperativista->exportToOds();
        }
        return Command::SUCCESS;
    }
}
