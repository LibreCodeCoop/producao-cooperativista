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
use SebastiaanLuca\PipeOperator\Pipe;
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
                'Total de dias úteis no mês trabalhado'
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
                'Atualiza a produção cooperativista'
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
        $diaUtilPagamento = (int) $input->getOption('dia-util-pagamento');
        $inicio = DateTime::createFromFormat('Y-m', $input->getOption('ano-mes'));
        if ((bool) $input->getOption('baixar-dados')) {
            $this->producaoCooperativista->loadFromExternalSources($inicio);
        }
        $this->producaoCooperativista->setDiaUtilPagamento($diaUtilPagamento);
        $this->producaoCooperativista->setInicio($inicio);
        $this->producaoCooperativista->setDiasUteis($diasUteis);
        $this->producaoCooperativista->setPercentualMaximo($percentualMaximo);
        $this->producaoCooperativista->setPrevisao($previsao);
        $list = $this->producaoCooperativista->getProducaoCooprativista();

        if ($input->getOption('atualiza-producao')) {
            $this->producaoCooperativista->updateProducao();
        }

        if ($input->getOption('csv')) {
            Pipe::from($list)
                ->pipe(current(...))
                ->pipe(array_keys(...))
                ->pipe($this->csvstr(...))
                ->pipe($output->writeLn(...));
            foreach ($list as $cooperado) {
                $output->writeLn($this->csvstr($cooperado));
            }
        }

        if ($input->getOption('ods')) {
            $this->producaoCooperativista->saveOds();
        }
        return Command::SUCCESS;
    }

    private function csvstr(array $fields) : string {
        $f = fopen('php://memory', 'r+');
        if (fputcsv($f, $fields) === false) {
            return false;
        }
        rewind($f);
        $csv_line = stream_get_contents($f);
        return rtrim($csv_line);
    }
}
