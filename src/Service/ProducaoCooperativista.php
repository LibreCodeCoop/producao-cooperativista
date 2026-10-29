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

namespace App\Service;

use App\Helper\Colors;
use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Calculation\CustomerCostAllocator;
use App\Service\Calculation\WeightedAllocator;
use App\Service\Calculation\ProductionDistributionPlanner;
use App\Service\Import\SourcePipeline;
use App\Service\Cooperative\ProfileRegistry;
use App\Service\Cooperative\PublicationRouter;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Source\Documents;
use App\Service\Akaunting\Source\CapitalSocial;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use NumberFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProducaoCooperativista
{
    private array $trabalhadoPorCliente = [];
    /** @var Cooperado[] */
    private array $cooperado = [];
    private int $totalCooperados = 0;
    private bool $producaoDistribuida = false;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Documents $documents,
        private Request $request,
        private DocumentConfiguration $documentConfiguration,
        private NumberFormatter $numberFormatter,
        public Dates $dates,
        private UrlGeneratorInterface $urlGenerator,
        private Movimentacao $movimentacao,
        private CustomerCostAllocator $customerCostAllocator,
        private WeightedAllocator $weightedAllocator,
        private ProductionDistributionPlanner $distributionPlanner,
        private SourcePipeline $sourcePipeline,
        private ProfileRegistry $profiles,
        private PublicationRouter $publisher,
        private CapitalSocial $capitalSocialSource,
    ) {
    }

    private function getTotalTrabalhado(): float
    {
        $trabalhado = $this->getTrabalhadoPorCliente();
        $totalTrabalhado = array_sum(array_column($trabalhado, 'trabalhado'));
        return $totalTrabalhado;
    }

    private function getTotalHorasPossiveis(): int
    {
        return $this->getTotalCooperados() * 8 * $this->dates->getDiasUteisNoMes();
    }

    public function loadFromExternalSources(DateTime $inicio): void
    {
        $this->sourcePipeline->run($inicio);
    }

    /**
     * Retorna o total de pessoas que registraram horas no Kimai em um intervalo
     * de datas
     *
     * @throws Exception
     */
    private function getTotalCooperados(): int
    {
        if ($this->totalCooperados) {
            return $this->totalCooperados;
        }

        $externo = $this->getTrabalhadoPorClienteExterno();
        $interno = $this->getTrabalhadoPorClienteInterno();
        $total = array_unique(array_merge(
            array_column($interno, 'alias', 'tax_number'),
            array_column($externo, 'alias', 'tax_number')
        ));

        $this->totalCooperados = count($total);
        $this->logger->info('Total pessoas no mês: {total}', ['total' => $this->totalCooperados]);
        return $this->totalCooperados;
    }

    /**
     * @return array[]
     */
    public function getCapitalSocial(?int $contactId = null, ?string $until = null): array
    {
        return $this->capitalSocialSource->rows($contactId, $until);
    }

    /**
     * @return array[]
     */
    public function getTrabalhadoSummarized(): array
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();

        $qb
            ->addSelect('u.alias AS nome')
            ->addSelect('u.tax_number')
            ->addSelect("SEC_TO_TIME(SUM(t.duration)) AS total_horas")
            ->from('timesheet', 't')
            ->join('t', 'users', 'u', 'u.id = t.user_id')
            ->join('t', 'projects', 'p', 'p.id = t.project_id')
            ->join('p', 'customers', 'c', 'c.id = p.customer_id')
            ->where($qb->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($qb->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->groupBy('u.alias')
            ->addGroupBy('u.tax_number')
            ->orderBy('u.alias');
        $result = $qb->executeQuery();
        $return = [];
        while ($row = $result->fetchAssociative()) {
            $return[] = $row;
        }
        return $return;
    }

    /**
     * @return array<int, array{nome: string, integralizado: int|float, devolvido: int|float, total: int|float, pendencias: int, situacao: string}>
     */
    public function getCapitalSocialSummarized(?string $until = null): array
    {
        $summary = [];
        foreach ($this->getCapitalSocial(null, $until) as $row) {
            $id = (int) $row['contact_id'];
            $summary[$id] ??= [
                    'nome' => '<a href="' . $this->urlGenerator->generate('app_capitalsocial_index', [
                        'contact_id' => $id,
                        'ate' => $until,
                    ]) . '">' . htmlspecialchars((string) $row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>',
                    'integralizado' => 0,
                    'devolvido' => 0,
                    'total' => 0,
                    'pendencias' => 0,
                    'situacao' => 'Aguardando conciliação documental',
            ];
            if ($row['status'] !== 'confirmado') {
                ++$summary[$id]['pendencias'];
                continue;
            }
            $cents = (int) round(((float) $row['amount']) * 100);
            if ($cents >= 0) {
                $summary[$id]['integralizado'] += $cents;
            } else {
                $summary[$id]['devolvido'] -= $cents;
            }
            $summary[$id]['total'] += $cents;
        }
        foreach ($summary as $id => $entry) {
            foreach (['integralizado', 'devolvido', 'total'] as $field) {
                $entry[$field] /= 100;
            }

            // Zero unclassified records does not mean the member's historical
            // position has been reconciled to society records and accounting.
            if ($entry['total'] < 0) {
                ++$entry['pendencias'];
                $entry['situacao'] = 'Histórico incompleto: devolução superior aos aportes registrados';
            } elseif ($entry['pendencias'] > 0) {
                $entry['situacao'] = 'Movimentações a conferir';
            } else {
                $entry['situacao'] = 'Aguardando conciliação documental';
            }
            $summary[$id] = $entry;
        }
        return $summary;
    }

    /**
     * @return string[]
     */
    private function clientesInternos(): array
    {
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        $cnpjClientesInternos = array_unique($cnpjClientesInternos);
        sort($cnpjClientesInternos);
        return $cnpjClientesInternos;
    }

    public function getTrabalhadoPorClienteInterno(): array
    {
        $entradas = array_filter($this->trabalhadoPorCliente, fn ($i) => $i['type'] === 'interno');
        if (count($entradas)) {
            return $entradas;
        }
        $clientesInternos = $this->clientesInternos();

        $qb = $this->entityManager->getConnection()->createQueryBuilder();

        $subQuery = $this->entityManager->getConnection()->createQueryBuilder();
        $subQuery->select('sum(t.duration) as total')
            ->from('timesheet', 't')
            ->join('t', 'projects', 'p', 't.project_id = p.id')
            ->join('p', 'customers', 'c', 'p.customer_id = c.id')
            ->join('t', 'users', 'u', 'u.id = t.user_id')
            ->where($subQuery->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($subQuery->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->andWhere($qb->expr()->in('c.vat_id', $qb->createNamedParameter($clientesInternos, ArrayParameterType::STRING)));

        $qb->select('u.alias')
            ->addSelect('u.tax_number')
            ->addSelect('u.dependents')
            ->addSelect('u.peso')
            ->addSelect('u.akaunting_contact_id')
            ->addSelect('c.id as customer_id')
            ->addSelect("c.name")
            ->addSelect("'interno' as type")
            ->addSelect('c.vat_id as customer_reference')
            ->addSelect('COALESCE(sum(t.duration), 0) * 100 / total_cliente.total as percentual_trabalhado')
            ->addSelect('sum(t.duration) as trabalhado')
            ->addSelect('total_cliente.total as total_cliente')
            ->from('customers', 'c')
            ->join('c', '(' . $subQuery->getSQL() . ')', 'total_cliente')
            ->join('c', 'projects', 'p', $qb->expr()->eq('p.customer_id', 'c.id'))
            ->join('p', 'timesheet', 't', $qb->expr()->eq('t.project_Id', 'p.id'))
            ->join('t', 'users', 'u', $qb->expr()->eq('t.user_id', 'u.id'))
            ->where($qb->expr()->in('c.vat_id', $qb->createNamedParameter($clientesInternos, ArrayParameterType::STRING)))
            ->andWhere($qb->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($qb->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->groupBy('u.alias')
            ->addGroupBy('u.tax_number')
            ->addGroupBy('u.dependents')
            ->addGroupBy('u.peso')
            ->addGroupBy('u.akaunting_contact_id')
            ->addGroupBy('c.id')
            ->addGroupBy('total_cliente.total')
            ->orderBy('u.alias');
        $result = $qb->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $row['peso'] ??= 1;
            $cooperado = $this->getCooperado($row['tax_number']);
            $cooperado
                ->setName($row['alias'])
                ->setDependentes($row['dependents'])
                ->setTaxNumber($row['tax_number'])
                ->setWeight($row['peso'])
                ->setAkauntingContactId($row['akaunting_contact_id'])
                ->setTrabalhado($cooperado->getTrabalhado() + $row['trabalhado']);
            $row['base_producao'] = 0;
            $row['percentual_trabalhado'] = (float) $row['percentual_trabalhado'];
            $this->trabalhadoPorCliente[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($this->trabalhadoPorCliente)]);
        return $this->trabalhadoPorCliente;
    }

    private function clientesContabilizaveis(): array
    {
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        $clientesContabilizaveis = array_column($this->movimentacao->getEntradasClientes(), 'customer_reference');
        $clientesContabilizaveis = array_diff(
            array_values($clientesContabilizaveis),
            $cnpjClientesInternos
        );
        $clientesContabilizaveis = array_unique($clientesContabilizaveis);
        return $clientesContabilizaveis;
    }

    public function getTrabalhadoPorClienteExterno(): array
    {
        $entradas = array_filter($this->trabalhadoPorCliente, fn ($i) => $i['type'] === 'externo');
        if (count($entradas)) {
            return $entradas;
        }
        $contabilizaveis = $this->clientesContabilizaveis();

        $qb = $this->entityManager->getConnection()->createQueryBuilder();

        $projetosAtivosNoMes = $this->entityManager->getConnection()->createQueryBuilder();
        $projetosAtivosNoMes->select('c.id as customer_id')
            ->addSelect('sum(p.time_budget) as time_budget')
            ->addSelect('p.id AS project_id')
            ->from('customers', 'c')
            ->join('c', 'projects', 'p', $projetosAtivosNoMes->expr()->eq('p.customer_id', 'c.id'))
            ->where(
                $projetosAtivosNoMes->expr()->or(
                    'p.start IS NULL',
                    $projetosAtivosNoMes->expr()->lte('p.start', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s')))
                )
            )
            ->andWhere(
                $projetosAtivosNoMes->expr()->or(
                    'p.end IS NULL',
                    $projetosAtivosNoMes->expr()->gte('p.end', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d')))
                )
            )
            ->andWhere($projetosAtivosNoMes->expr()->gt('p.time_budget', '0'))
            ->groupBy('c.id')
            ->addGroupBy('p.id');

        $subQuery = $this->entityManager->getConnection()->createQueryBuilder();
        $subQuery->select('c.vat_id')
            ->addSelect('c.id')
            ->addSelect('project_time_budget.project_id')
            ->addSelect(str_replace(
                "\n",
                ' ',
                <<<SQL
                CASE WHEN SUM(t.duration) > project_time_budget.time_budget AND SUM(t.duration) > c.time_budget THEN SUM(t.duration)
                    WHEN project_time_budget.time_budget > c.time_budget THEN project_time_budget.time_budget
                    ELSE c.time_budget
                END as total
                SQL
            ))
            ->from('customers', 'c')
            ->join('c', '(' . $projetosAtivosNoMes->getSQL() . ')', 'project_time_budget', $subQuery->expr()->eq('project_time_budget.customer_id', 'c.id'))
            ->join('c', 'projects', 'p', (string) $subQuery->expr()->and(
                $subQuery->expr()->eq('p.customer_id', 'c.id'),
                $subQuery->expr()->eq('project_time_budget.project_id', 'p.id'),
            ))
            ->join('project_time_budget', 'timesheet', 't', $subQuery->expr()->eq('t.project_Id', 'p.id'))
            ->where($subQuery->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($subQuery->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->groupBy('c.vat_id')
            ->addGroupBy('project_time_budget.project_id')
            ->addGroupBy('c.id');

        $qb->select('u.alias')
            ->addSelect('u.tax_number')
            ->addSelect('u.dependents')
            ->addSelect('u.peso')
            ->addSelect('u.akaunting_contact_id')
            ->addSelect('c.id as customer_id')
            ->addSelect('c.name')
            ->addSelect("'externo' as type")
            ->addSelect('c.vat_id as customer_reference')
            ->addSelect('COALESCE(sum(t.duration), 0) * 100 / total_cliente.total as percentual_trabalhado')
            ->addSelect('sum(t.duration) as trabalhado')
            ->addSelect('total_cliente.total as total_cliente')
            ->from('customers', 'c')
            ->join('c', '(' . $subQuery->getSQL() . ')', 'total_cliente', 'c.id = total_cliente.id')
            ->join('c', 'projects', 'p', (string) $qb->expr()->and(
                $qb->expr()->eq('p.customer_id', 'c.id'),
                $qb->expr()->eq('total_cliente.project_id', 'p.id')
            ))
            ->join('p', 'timesheet', 't', $qb->expr()->eq('t.project_Id', 'p.id'))
            ->join('t', 'users', 'u', $qb->expr()->eq('t.user_id', 'u.id'))
            ->where($qb->expr()->in('c.vat_id', $qb->createNamedParameter($contabilizaveis, ArrayParameterType::STRING)))
            ->andWhere($qb->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($qb->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->groupBy('u.alias')
            ->addGroupBy('u.tax_number')
            ->addGroupBy('u.dependents')
            ->addGroupBy('u.peso')
            ->addGroupBy('u.akaunting_contact_id')
            ->addGroupBy('c.id')
            ->addGroupBy('c.name')
            ->addGroupBy('c.vat_id')
            ->addGroupBy('total_cliente.total')
            ->orderBy('c.id')
            ->addOrderBy('u.alias');
        $result = $qb->executeQuery();
        while ($row = $result->fetchAssociative()) {
            if (!$row['customer_reference']) {
                continue;
            }
            $row['peso'] ??= 1;
            $cooperado = $this->getCooperado($row['tax_number']);
            $cooperado
                ->setName($row['alias'])
                ->setDependentes($row['dependents'])
                ->setTaxNumber($row['tax_number'])
                ->setWeight($row['peso'])
                ->setAkauntingContactId($row['akaunting_contact_id'])
                ->setTrabalhado($cooperado->getTrabalhado() + $row['trabalhado']);
            $row['base_producao'] = 0;
            $row['percentual_trabalhado'] = (float) $row['percentual_trabalhado'];
            $this->trabalhadoPorCliente[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($this->trabalhadoPorCliente)]);
        return $this->trabalhadoPorCliente;
    }

    public function getTrabalhadoPorCliente(): array
    {
        $this->getTrabalhadoPorClienteInterno();
        $this->getTrabalhadoPorClienteExterno();
        $this->validaClientes();
        return $this->trabalhadoPorCliente;
    }

    private function validaClientes(): void
    {
        $totalPorCliente = array_column($this->movimentacao->getEntradasClientes(), 'base_producao', 'customer_reference');
        $clientesInternos = $this->clientesInternos();

        $errorSemCodigoCliente = array_filter($this->trabalhadoPorCliente, function ($i) use ($totalPorCliente, $clientesInternos) {
            if (in_array($i['customer_reference'], $clientesInternos)) {
                return false;
            }
            return !isset($totalPorCliente[$i['customer_reference']]);
        });
        if (count($errorSemCodigoCliente)) {
            throw new Exception(
                "O customer_reference trabalhado no Kimai não possui faturamento no mês " . $this->dates->getInicioProximoMes()->format('Y-m-d'). ".\n" .
                "Dados:\n" .
                json_encode($errorSemCodigoCliente, JSON_PRETTY_PRINT)
            );
        }
    }

    public function updateProducao(): void
    {
        $this->profiles->requireLibreCodeSettlement();
        $members = $this->getProducaoCooperativista();
        $this->publisher->publish($members, $this->getTrabalhadoPorCliente());
    }

    /**
     * @return Cooperado[]
     */
    public function getProducaoCooperativista(): array
    {
        // The legacy settlement objects encode LibreCode-specific FRRA,
        // benefits and taxes. Never apply them to another cooperative.
        $this->profiles->requireLibreCodeSettlement();
        if ($this->producaoDistribuida) {
            return $this->cooperado;
        }

        $this->movimentacao->getMovimentacaoFinanceira();
        $this->distribuiProducao();
        $this->atualizaDescontosLiquido();
        $this->atualizaAdiantamentos();
        $this->logger->debug('Produção calculada para {total} cooperados.', ['total' => count($this->cooperado)]);
        return $this->cooperado;
    }

    private function atualizaDescontosLiquido(): self
    {
        $firstCooperado = reset($this->cooperado);
        if ($firstCooperado === false) {
            return $this;
        }

        $rows = $firstCooperado->getProducaoCooperativista()->getLiquidDiscountRows();
        foreach ($this->cooperado as $cooperado) {
            $cooperado->getProducaoCooperativista()->updateLiquidDiscounts($rows);
        }
        return $this;
    }

    private function atualizaAdiantamentos(): self
    {
        $firstCooperado = reset($this->cooperado);
        if ($firstCooperado === false) {
            return $this;
        }

        $byCooperado = $firstCooperado->getProducaoCooperativista()->getAdiantamentosParaCooperados(
            array_map(strval(...), array_keys($this->cooperado))
        );
        foreach ($this->cooperado as $cooperado) {
            $taxNumber = $cooperado->getTaxNumber();
            $cooperado->getProducaoCooperativista()->atualizaAdiantamentos($byCooperado[$taxNumber] ?? []);
        }

        return $this;
    }

    private function distribuiSobras(): void
    {
        $aDistribuir = $this->getTotalSobrasDoMes() + $this->getTotalSobrasDistribuidasNoMes();
        if ($aDistribuir === 0.0) {
            return;
        }

        // A cooperado may have several internal projects; do not distribute twice.
        $weights = [];
        foreach ($this->getTrabalhadoPorClienteInterno() as $row) {
            $taxNumber = (string) $row['tax_number'];
            $weights[$taxNumber] = $this->getCooperado($taxNumber)->getPesoFinal();
        }

        $allocation = $this->weightedAllocator->allocate($aDistribuir, $weights, 'Distribuição de sobras');
        foreach ($allocation as $taxNumber => $amount) {
            $values = $this->getCooperado((string) $taxNumber)->getProducaoCooperativista()->getValues();
            $values->setBaseProducao($values->getBaseProducao() + $amount);
        }
    }

    private function getCooperado(string $taxNumber): Cooperado
    {
        $this->cooperado[$taxNumber] ??= new Cooperado(
            entityManager: $this->entityManager,
            dates: $this->dates,
            numberFormatter: $this->numberFormatter,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
            anoFiscal: (int) $this->dates->getInicio()->format('Y'),
            mes: (int) $this->dates->getInicio()->format('m'),
        );
        return $this->cooperado[$taxNumber];
    }

    private function distribuiProducao(): void
    {
        if ($this->producaoDistribuida) {
            return;
        }

        $plan = $this->distributionPlanner->plan(
            $this->movimentacao->getEntradasClientes(),
            $this->getTrabalhadoPorCliente(),
        );
        foreach ($plan->variableWeights as $taxNumber => $weight) {
            $member = $this->getCooperado((string) $taxNumber);
            $member->setPesoFinal($member->getPesoFinal() + $weight);
        }
        foreach ($plan->amounts as $taxNumber => $amount) {
            $values = $this->getCooperado((string) $taxNumber)->getProducaoCooperativista()->getValues();
            $values->setBaseProducao($values->getBaseProducao() + $amount);
        }

        $this->distribuiSobras();
        $this->producaoDistribuida = true;
    }

    private function getTotalSobrasDoMes(): float
    {
        $totalNotasClientes = $this->movimentacao->getTotalNotasClientes();
        $totalDispendiosClientesPercentualMovel = $this->movimentacao->getTotalDispendiosClientesPercentualMovel();
        $totalDispendiosInternos = $this->movimentacao->getTotalDispendiosInternos();
        $totalPercentualDescontoFixo = $this->movimentacao->totalPercentualDescontoFixo();
        $totalSobrasClientesPercentualFixo = $this->movimentacao->getTotalSobrasClientesPercentualFixo();
        $totalBaseProducao = $this->movimentacao->getBaseProducao();
        $sobras = $totalNotasClientes
            - $totalDispendiosClientesPercentualMovel
            - $totalDispendiosInternos
            - $totalPercentualDescontoFixo
            - $totalBaseProducao
            - ($this->movimentacao->getTaxaAdministrativa() - $this->movimentacao->getTaxaMinima())
            + $totalSobrasClientesPercentualFixo;
        return $sobras;
    }

    private function getTotalSobrasDistribuidasNoMes(): float
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $qb->select('SUM(i.amount) AS total')
            ->from('invoices', 'i')
            ->where($qb->expr()->eq('transaction_of_month', $qb->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))))
            ->andWhere($qb->expr()->eq('i.type', $qb->createNamedParameter('invoice')))
            ->andWhere($qb->expr()->eq('i.archive', $qb->createNamedParameter(0, ParameterType::INTEGER)))
            ->andWhere($qb->expr()->eq('i.category_id', $qb->createNamedParameter((int) getenv('AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID'), ParameterType::INTEGER)));
        $total = $qb->executeQuery()->fetchOne();
        return (float) $total;
    }

    public function exportData(): array
    {
        $this->getProducaoCooperativista();
        $return = [
            'total_notas_clientes' => [
                'valor' => $this->movimentacao->getTotalNotasClientes(),
                'formula' => '{total_notas_clientes} = ' . $this->getFormulaTotalNotasClientes() .
                ' Relatório: <a href="' .
                $this->urlGenerator->generate('app_invoices_index', [
                    'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    'entrada_cliente' => 'sim',
                    'category_type' => 'income',
                ]) .
                '" target="_blank">notas clientes</a>'
            ],
            'total_notas_percentual_fixo' => [
                'valor' => array_sum(array_column(array_filter($this->movimentacao->getEntradasClientes(), fn ($i) => $i['percentual_desconto_fixo'] === true), 'amount')),
                'formula' => '{total_notas_percentual_fixo} = ' .implode(' + ', array_column(array_filter($this->movimentacao->getEntradasClientes(), fn ($i) => $i['percentual_desconto_fixo'] === true), 'amount')) .
                ' <a href="' .
                $this->urlGenerator->generate('app_invoices_index', [
                    'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    'entrada_cliente' => 'sim',
                    'category_type' => 'income',
                    'percentual_desconto_fixo' => 'true',
                ]) .
                '" target="_blank">notas clientes</a>',
            ],
            'total_notas_percentual_movel' => [
                'valor' => $this->movimentacao->totalNotasPercentualMovel(),
                'formula' => '{total_notas_percentual_movel} = {total_notas_clientes} - {total_notas_percentual_fixo}'
            ],
            'total_percentual_desconto_fixo' => [
                'valor' => $this->movimentacao->totalPercentualDescontoFixo(),
                'formula' => '{total_percentual_desconto_fixo} = ' . $this->getFormulaTotalPercentualDescontoFixo(),
            ],
            'total_sobras_clientes_percentual_fixo' => [
                'valor' => $this->movimentacao->getTotalSobrasClientesPercentualFixo(),
            ],
            'total_dispendios_clientes_percentual_movel' => [
                'valor' => $this->movimentacao->getTotalDispendiosClientesPercentualMovel(),
                'formula' => '{total_dispendios_clientes_percentual_movel} = ' . implode(' + ', array_values($this->movimentacao->getCustosPorCliente())) .
                ' <a href="' .
                $this->urlGenerator->generate('app_invoices_index', [
                    'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    'custos_clientes' => 'sim',
                    'category_type' => 'expense',
                ]) .
                '" target="_blank">dispêndios clientes</a>'
            ],
            'total_notas_para_percentual_movel_sem_custo_cliente' => [
                'valor' => $this->movimentacao->totalNotasParaPercentualMovelSemCustoCliente(),
                'formula' => '{total_notas_para_percentual_movel_sem_custo_cliente} = {total_notas_percentual_movel} - {total_dispendios_clientes_percentual_movel}',
            ],
            'total_dispendios_internos' => [
                'valor' => $this->movimentacao->getTotalDispendiosInternos(),
                'formula' => '{total_dispendios_internos} = somatório de ' .
                    '<a href="' .
                    $this->urlGenerator->generate('app_invoices_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'dispendio_interno' => 'sim',
                    ]) .
                    '" target="_blank">itens</a>' .
                    ' com ' .
                    '<a href="' .
                    $this->urlGenerator->generate('app_categorias_index', [
                        'dispendio_interno' => 'sim',
                    ]) .
                    '" target="_blank">categoria</a>' .
                    ' de ' .
                    '<a href="' .
                    $this->urlGenerator->generate('app_invoices_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'dispendio_interno' => 'sim',
                    ]) .
                    '" target="_blank">dispêndio interno</a>'
            ],
            'taxa_minima' => [
                'valor' => $this->movimentacao->getTaxaMinima(),
                'formula' => '{taxa_minima} = {total_dispendios_internos}',
            ],
            'taxa_maxima' => [
                'valor' => $this->movimentacao->getTaxaMaxima(),
                'formula' => '{taxa_maxima} = {taxa_minima} * 2'
            ],
            'percentual_seguranca' => ['valor' => $this->movimentacao->getPercentualMaximo()],
            'valor_seguranca' => [
                'valor' => $this->movimentacao->totalNotasParaPercentualMovelSemCustoCliente() * $this->movimentacao->getPercentualMaximo() / 100,
                'formula' => '{valor_seguranca} = {total_notas_para_percentual_movel_sem_custo_cliente} * {percentual_seguranca} / 100',
            ],
            'taxa_administrativa' => [
                'valor' => $this->movimentacao->getTaxaAdministrativa(),
                'formula' => <<<FORMULA
                    <pre>
                    SE ({taxa_minima} >= {valor_seguranca} &lbrace;
                        {taxa_administrativa} = {taxa_minima}
                    &rbrace; SENÃO SE ({taxa_maxima} >= {valor_seguranca}) &lbrace;
                        {taxa_administrativa} = {valor_seguranca}
                    &rbrace; SENÃO &lbrace;
                        {taxa_administrativa} = {taxa_maxima}
                    &rbrace;
                    </pre>
                    FORMULA
            ],
            'percentual_administrativo' => [
                'valor' => $this->movimentacao->percentualAdministrativo(),
                'formula' => '{percentual_administrativo} = {taxa_administrativa} * 100 / {total_notas_para_percentual_movel_sem_custo_cliente}',
            ],
            'base_producao' => [
                'valor' => $this->movimentacao->getBaseProducao(),
                'formula' => '{base_producao} <br> = ' . $this->getFormulaBaseProducao(),
            ],
            'reserva' => [
                'valor' => $this->movimentacao->getTaxaAdministrativa() - $this->movimentacao->getTaxaMinima(),
                'formula' => '{reserva} = {taxa_administrativa} - {taxa_minima}',
            ],
            'total_sobras_do_mes' => [
                'valor' => round($this->getTotalSobrasDoMes(), 2),
                'formula' => '{total_sobras_do_mes} = {total_notas_clientes} - {total_dispendios_clientes_percentual_movel} - {total_dispendios_internos} - {base_producao} - {reserva} - {total_percentual_desconto_fixo} + {total_sobras_clientes_percentual_fixo}'
            ],
            'total_sobras_distribuidas' => [
                'valor' => $this->getTotalSobrasDistribuidasNoMes() + round($this->getTotalSobrasDoMes(), 2),
                'formula' => '{total_sobras_distribuidas} = ' .
                    ' <a href="' .
                    $this->urlGenerator->generate('app_invoices_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'category_name' => 'Distribuição de sobras',
                    ]) .
                    '" target="_blank" title="Distribuição de sobras">' . $this->getTotalSobrasDistribuidasNoMes() . '</a>' .
                    ' + {total_sobras_do_mes}' .
                    ' <a href="' .
                    $this->urlGenerator->generate('app_invoices_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'category_name' => 'Distribuição de sobras',
                    ]) .
                    '" target="_blank">disrtibuição de sobras</a>'
            ],
            'total_horas_trabalhadas' => ['valor' => $this->getTotalTrabalhado() / 60 / 60],
            'total_horas_possiveis' => [
                'valor' => $this->getTotalHorasPossiveis(),
                'formula' => '{total_horas_possiveis} = {total_cooperados} * 8 * {dias_uteis_no_mes}'
            ],
            'total_cooperados' => [
                'valor' => $this->getTotalCooperados(),
                'formula' => '{total_cooperados} ' .
                    ' <a href="' .
                    $this->urlGenerator->generate('app_trabalhadosummarized_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    ]) .
                    '" target="_blank">Lista de cooperados</a>'
            ],
            'dias_uteis_no_mes' => ['valor' => $this->dates->getDiasUteisNoMes()],
            'transacao_do_mes' => ['valor' => $this->dates->getInicioProximoMes()->format('Y-m')],
            'ano_mes_trabalhado' => ['valor' => $this->dates->getInicio()->format('Y-m')],
        ];
        return $this->formatData($return);
    }

    private function getFormulaTotalPercentualDescontoFixo(): string
    {
        $entradasClientes = $this->movimentacao->getEntradasClientes();
        $entradasComPercentualFixo = array_filter($entradasClientes, fn ($i) => $i['percentual_desconto_fixo'] === true);
        $totalPercentualDescontoFixoFormula = [];
        foreach ($entradasComPercentualFixo as $row) {
            $totalPercentualDescontoFixoFormula[] = "({$row['amount']} * {$row['discount_percentage']} / 100)";
        }
        $totalPercentualDescontoFixoFormula = implode(' + ', $totalPercentualDescontoFixoFormula);
        return $totalPercentualDescontoFixoFormula;
    }

    private function getFormulaBaseProducao(): string
    {
        $entradasClientes = $this->movimentacao->getEntradasClientes();
        $custosAlocados = $this->customerCostAllocator->allocate(
            $entradasClientes,
            $this->movimentacao->getCustosPorCliente(),
        );

        $formula = [];
        foreach ($entradasClientes as $key => $row) {
            $title = htmlspecialchars((string) ($row['contact_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $link = '<a href="' . $this->urlGenerator->generate('app_invoices_index', [
                'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                'id' => $row['id'],
            ]) . '" title="' . $title . '">' . (float) $row['amount'] . '</a>';
            // Same cost allocation as the financial motor: a client with
            // multiple invoices must have the expense distributed among them.
            $cost = (float) ($custosAlocados[$key] ?? 0);
            $percentual = $row['percentual_desconto_fixo']
                ? (string) (float) $row['discount_percentage']
                : '{percentual_administrativo}';
            $base = '(' . $link . ' - ' . $cost . ')';
            $formula[] = $base . ' - (' . $base . ' * ' . $percentual . ' / 100)';
        }

        return implode('<br> + ', $formula);
    }

    private function getFormulaTotalNotasClientes(): string
    {
        $entradasClientes = $this->movimentacao->getEntradasClientes();
        $formula = [];
        foreach ($entradasClientes as $row) {
            $formula[] = '<a href="'.
                $this->urlGenerator->generate('app_invoices_index', [
                    'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    'id' => $row['id'],
                ]).
                '" title="' . htmlspecialchars((string) ($row['contact_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . (float) $row['amount'] . '</a>';
        }
        return implode(' + ', $formula);
    }

    private function listToNumber(array $list, int $max): array
    {
        $total = count($list);
        $return = [];
        $distance = floor($max / $total);
        $current = 0;
        while (count($return) < $total) {
            $current += $distance;
            if ($current >= $max) {
                $current = $max - 1;
            }
            $return[] = $current;
        }
        return $return;
    }

    private function formatData(array $data): array
    {
        if ($data === []) {
            return [];
        }
        $positions = $this->listToNumber(array_keys($data), count(Colors::list));
        $colorList = array_keys(Colors::list);
        $sequence = 0;
        foreach ($data as $key => $item) {
            $formula = (string) ($item['formula'] ?? '');
            if ($formula === '') {
                $data[$key]['formula'] = '<span class="' . $key . '">'
                    . htmlspecialchars((string) $item['valor'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</span>';
            } else {
                $rendered = preg_replace_callback(
                    '/\{(?<name>[A-Za-z0-9_]+)\}/',
                    static function (array $match) use ($data): string {
                        $name = $match['name'];
                        if (!array_key_exists($name, $data)) {
                            throw new \LogicException('Unknown calculation reference: ' . $name);
                        }
                        $value = htmlspecialchars(
                            (string) $data[$name]['valor'],
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8',
                        );
                        $title = htmlspecialchars(
                            ucfirst(str_replace('_', ' ', $name)) . ': ' . (string) $data[$name]['valor'],
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8',
                        );
                        return '<span class="' . $name . '" title="' . $title . '">' . $value . '</span>';
                    },
                    $formula,
                );
                $data[$key]['formula'] = str_replace(
                    'target="_blank"',
                    'target="_blank" rel="noopener noreferrer"',
                    $rendered ?? '',
                );
            }

            if (empty($item['color'])) {
                $data[$key]['color'] = Colors::list[$colorList[$positions[$sequence]]];
                $sequence++;
            }
            $data[$key]['font_color'] = Colors::colorContrast(...$data[$key]['color']);
            $data[$key]['label'] = $item['label'] ?? ucfirst(str_replace('_', ' ', $key));
        }
        return $data;
    }

    public function exportToCsv(): string
    {
        $list = $this->getProducaoCooperativista();
        if (!count($list)) {
            return '';
        }
        // header
        $cooperado = current($list);
        $output[] = $this->csvstr(array_keys($cooperado->getProducaoCooperativista()->getValues()->toArray()));
        // body
        foreach ($list as $cooperado) {
            $document = $cooperado->getProducaoCooperativista();
            $toCsv = $document->getValues()->toArray();
            $toCsv['document_number'] = $document->getPreviewDocumentNumber();
            $toCsv['adiantamento'] = array_reduce($toCsv['adiantamento'], fn ($c, $i) => $c + $i['amount'], 0);
            $output[] = $this->csvstr($toCsv);
        }
        $output = implode("\n", $output);
        return $output;
    }

    private function csvstr(array $fields): string
    {
        $f = fopen('php://memory', 'r+');
        if (fputcsv($f, $fields, ',', '"', '\\') === false) {
            return '';
        }
        rewind($f);
        $csv_line = stream_get_contents($f);
        return rtrim($csv_line);
    }
}
