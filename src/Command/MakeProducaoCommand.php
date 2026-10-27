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

namespace App\Command;

use App\Service\Movimentacao;
use App\Helper\ArrayValue;
use App\Service\Calculation\CalculationExecutionLock;
use App\Service\Cooperative\ProfileRegistry;
use App\Domain\Competencia;
use DateTime;
use App\Service\ProducaoCooperativista;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'make:producao',
    description: 'Produção cooperativista por cooperado'
)]
class MakeProducaoCommand extends AbstractBaseCommand
{
    public function __construct(
        private ProducaoCooperativista $producaoCooperativista,
        private Movimentacao $movimentacao,
        private LoggerInterface $logger,
        private ?CalculationExecutionLock $executionLock = null,
        private ?ProfileRegistry $profiles = null,
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
                getenv('DIA_UTIL_PAGAMENTO')
            )
            ->addOption(
                'percentual-maximo',
                null,
                InputOption::VALUE_REQUIRED,
                'Percentual máximo para pagamento de dispêndios',
                getenv('PERCENTUAL_MAXIMO')
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
                'as-of',
                null,
                InputOption::VALUE_REQUIRED,
                'Data de referência para prévia histórica, no formato YYYY-MM-DD; não permite publicação'
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
        try {
            $inicio = Competencia::fromString((string) $input->getOption('ano-mes'))->start();
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>--ano-mes precisa estar no formato YYYY-MM</error>');
            return Command::INVALID;
        }
        $asOf = $input->getOption('as-of');
        if (is_string($asOf) && $asOf !== '') {
            if ($input->getOption('atualiza-producao')) {
                $output->writeln('<error>--as-of é exclusivo para prévias. Não é permitido emitir documentos com datas históricas simuladas.</error>');
                return Command::INVALID;
            }
            $referenceDate = DateTime::createFromFormat('!Y-m-d', $asOf);
            if (!$referenceDate instanceof DateTime || $referenceDate->format('Y-m-d') !== $asOf) {
                $output->writeln('<error>--as-of deve estar no formato YYYY-MM-DD, com dia válido.</error>');
                return Command::INVALID;
            }
            $this->producaoCooperativista->dates->setEvaluationTime($referenceDate);
        }

        $phase = 'validação do perfil cooperativo';
        try {
            // Fail before any external import or accounting side effect. The
            // legacy command is strictly LibreCode-specific.
            $this->profiles?->requireLibreCodeSettlement();
            $phase = 'inicialização';
            $this->executionLock?->acquire();
            $phase = 'importação dos dados';
            if ((bool) $input->getOption('baixar-dados')) {
                $this->reportPhase('Importando dados das integrações.');
                $this->producaoCooperativista->loadFromExternalSources($inicio);
            }
            $phase = 'configuração do cálculo';
            $this->reportPhase('Validando parâmetros econômicos.');
            $this->producaoCooperativista->dates->setDiaUtilPagamento($diaUtilPagamento);
            $this->producaoCooperativista->dates->setInicio($inicio);
            $this->producaoCooperativista->dates->setDiasUteis($diasUteis);
            $this->movimentacao->setPercentualMaximo($percentualMaximo);

            $phase = 'cálculo da produção';
            $this->reportPhase('Calculando produção elegível, horas e pesos.');
            $this->producaoCooperativista->getProducaoCooperativista();

            if ($input->getOption('atualiza-producao')) {
                $phase = 'gravação dos documentos no Akaunting';
                $this->reportPhase('Gravando documentos no Akaunting.');
                $this->producaoCooperativista->updateProducao();
            }

            if ($input->getOption('csv')) {
                $phase = 'exportação do relatório';
                $this->reportPhase('Preparando relatório de produção.');
                $output->writeln($this->producaoCooperativista->exportToCsv());
            }
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $this->logger->error('Falha na produção de {competencia}, etapa {etapa}: {motivo}', [
                'competencia' => $inicio->format('Y-m'),
                'etapa' => $phase,
                'motivo' => $error->getMessage(),
                'exception' => $error,
            ]);
            $output->writeln(sprintf(
                '<error>Falha na etapa "%s": %s</error>',
                OutputFormatter::escape($phase),
                OutputFormatter::escape($error->getMessage())
            ));
            return Command::FAILURE;
        } finally {
            $this->executionLock?->release();
        }
    }

    private function reportPhase(string $message): void
    {
        // Only fixed, non-sensitive progress messages are forwarded to SSE.
        // The logger retains all detailed records privately on the server.
        $this->logger->info(new ArrayValue([
            'event' => 'info',
            'data' => $message,
        ]));
    }
}
