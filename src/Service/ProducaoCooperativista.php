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

use App\Entity\Akaunting\Contacts;
use App\Helper\Colors;
use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\Document\Taxes\Cofins;
use App\Service\Akaunting\Document\Taxes\IrpfRetidoNaNota;
use App\Service\Akaunting\Document\Taxes\Iss;
use App\Service\Akaunting\Document\Taxes\Pis;
use App\Service\Akaunting\Source\Categories;
use App\Service\Akaunting\Source\Documents;
use App\Service\Akaunting\Source\Taxes;
use App\Service\Akaunting\Source\Transactions;
use App\Service\Kimai\Source\Customers;
use App\Service\Kimai\Source\Projects;
use App\Service\Kimai\Source\Timesheets;
use App\Service\Kimai\Source\Users;
use App\Service\Source\Nfse;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use NumberFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProducaoCooperativista
{
    private array $trabalhadoPorCliente = [];
    /** @var Cooperado[] */
    private array $cooperado = [];
    private array $categoriesList = [];
    private int $totalCooperados = 0;
    private bool $producaoDistribuida = false;
    private EntityManagerInterface $entityManagerAkaunting;

    public function __construct(
        ManagerRegistry $managerRegistry,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Customers $customers,
        private Nfse $nfse,
        private Projects $projects,
        private Documents $documents,
        private Timesheets $timesheets,
        private Transactions $transactions,
        private Users $users,
        private Request $request,
        private NumberFormatter $numberFormatter,
        private Categories $categories,
        private Taxes $taxes,
        public Dates $dates,
        private UrlGeneratorInterface $urlGenerator,
        private Movimentacao $movimentacao,
    ) {
        $this->entityManagerAkaunting = $managerRegistry->getManager('akaunting');
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
        $this->dates->setInicio($inicio);
        $this->logger->info('Baixando dados externos');
        $this->customers->updateDatabase();
        $this->nfse->updateDatabase($this->dates->getInicioProximoMes());
        $this->projects->updateDatabase();
        $this->documents
            ->setDate($this->dates->getInicioProximoMes())
            ->setType('invoice')
            ->saveList()
            ->setType('bill')
            ->saveList();
        $this->timesheets->updateDatabase($this->dates->getInicio());
        $this->transactions
            ->setDate($this->dates->getInicioProximoMes())
            ->saveList();
        $this->categories->saveList();
        $this->taxes->saveList();
        $this->users->saveList();
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
    public function getCapitalSocial(): array
    {
        $sql =
            <<<SQL
            SELECT * FROM (
            -- Transactions
            SELECT contact_id, c.name, amount,
                paid_at AS due_at,
                t.id,
                'transaction' as`'table`
            FROM transactions t
            join contacts c on c.id = t.contact_id
            WHERE category_id = 25
            and document_id is null
            AND t.deleted_at IS NULL
            UNION
            -- Documents
            SELECT contact_id, contact_Name, amount, due_at,
                d.id,
                'documents' as`'table`
            FROM documents d
            WHERE d.category_id = 25
            AND d.deleted_at IS NULL
            UNION
            -- Documents with items
            SELECT d.contact_id, d.contact_Name, di.price, d.due_at,
                d.id,
                'documents_item' as`'table`
            FROM document_items as di
            JOIN documents as d on di.document_id = d.id
            where di.item_id = 11
            AND d.deleted_at IS NULL
            AND di.deleted_at IS NULL
            ) x
            ORDER BY x.name, x.due_at
            SQL;
        $qb = $this->entityManagerAkaunting->getConnection()->createQueryBuilder();
        $stmt = $qb->executeQuery($sql);
        $return = $stmt->fetchAllAssociative();
        if (empty($return)) {
            throw new Exception('Sem capital social');
        }
        return $return;
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
     * @return (string)[][]
     */
    public function getCapitalSocialSummarized(): array
    {
        $capitalSocial = $this->getCapitalSocial();
        $return = [];
        foreach ($capitalSocial as $row) {
            $return[$row['name']] = [
                'nome' => '<a href="' .
                    $this->urlGenerator->generate('app_capitalsocial_index', [
                        'name' => $row['name'],
                    ]) .
                    '">' . $row['name'] . '</a>',
                'total' => $row['amount'] + ($return[$row['name']]['total'] ?? 0),
            ];
        }
        if (empty($return)) {
            throw new Exception('Sem capital social');
        }
        return $return;
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
            $row['peso'] = $row['peso'] ?? 1;
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
            $row['peso'] = $row['peso'] ?? 1;
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

    private function cadastraCooperadoQueProduziuNoAkaunting(): void
    {
        $trabalhadoPorCliente =  $this->getTrabalhadoPorCliente();
        $exists = [];
        foreach ($trabalhadoPorCliente as $row) {
            if (empty($row['akaunting_contact_id'])) {
                if (in_array($row['tax_number'], $exists)) {
                    continue;
                }
                $akauntingContactId = $this->cadastraCooperadoNoAkaunting(
                    name: $row['alias'],
                    taxNumber: $row['tax_number']
                );
                $this->getCooperado($row['tax_number'])
                    ->setAkauntingContactId($akauntingContactId);
                $exists[] = $row['tax_number'];
            }
        }
    }

    private function cadastraCooperadoNoAkaunting(string $name, string $taxNumber): int
    {
        $contacts = new Contacts();
        $contacts
            ->setCompanyId(getenv('AKAUNTING_COMPANY_ID'), ParameterType::INTEGER)
            ->setType('vendor')
            ->setName($name)
            ->setTaxNumber($taxNumber)
            ->setCountry('BR')
            ->setCurrencyCode('BRL')
            ->setEnabled(1)
            ->setCreatedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setUpdatedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'));
        $this->entityManagerAkaunting->persist($contacts);
        return $contacts->getId();
    }

    public function setPercentualMaximo(int $percentualMaximo): void
    {
        $this->movimentacao->setPercentualMaximo($percentualMaximo);
    }

    public function updatePesos(array $pesos): void
    {
        $this->users->updatePesos($pesos);
    }

    public function updateProducao(): void
    {
        $producao = $this->getProducaoCooperativista();
        foreach ($producao as $cooperado) {
            $producaoCooperativista = $cooperado->getProducaoCooperativista();
            $producaoCooperativista->save();
            $this->logger->info('Produção do cooperado {nome} criada. Número: {numero}. Líquido: {liquido}', [
                'nome' => $cooperado->getName(),
                'numero' => $producaoCooperativista->getDocumentNumber(),
                'liquido' => $producaoCooperativista->getValues()->getLiquido(),
            ]);

            if (strlen($cooperado->getTaxNumber()) > 11) {
                continue;
            }
            if ($this->dates->getDataPagamento()->format('m') === '12') {
                continue;
            }
            $frra = $cooperado->getFrra();
            $frra->getValues()->setFrra(
                $producaoCooperativista->getValues()->getFrra()
            )->setUpdated();
            $frra->save();
            $this->logger->info('FRRA do cooperado {nome} criada. Número: {numero}. Líquido: {liquido}', [
                'nome' => $cooperado->getName(),
                'numero' => $frra->getDocumentNumber(),
                'liquido' => $frra->getValues()->getLiquido(),
            ]);
        }

        $inssIrpf = new IrpfRetidoNaNota(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $inssIrpf->saveMonthTaxes();

        $cofins = new Cofins(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $cofins->setTotalBrutoNotasClientes($this->movimentacao->getTotalBrutoNotasClientes());
        $cofins->saveMonthTaxes();

        $pis = new Pis(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $pis->setTotalBrutoNotasClientes($this->movimentacao->getTotalBrutoNotasClientes());
        $pis->saveMonthTaxes();

        $iss = new Iss(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $iss->setTotalBrutoNotasClientes($this->movimentacao->getTotalBrutoNotasClientes());
        $iss->saveMonthTaxes();
    }

    /**
     * @return Cooperado[]
     */
    public function getProducaoCooperativista(): array
    {
        if ($this->cooperado) {
            return $this->cooperado;
        }

        $this->movimentacao->getMovimentacaoFinanceira();
        $this->cadastraCooperadoQueProduziuNoAkaunting();
        $this->distribuiProducao();
        $this->atualizaPlanoDeSaude();
        $this->atualizaAdiantamentos();
        $this->logger->debug('Produção por cooperado ooperado: {json}', ['json' => json_encode($this->cooperado)]);
        return $this->cooperado;
    }

    private function atualizaPlanoDeSaude(): self
    {
        foreach ($this->cooperado as $cooperado) {
            $cooperado->getProducaoCooperativista()->updateHealthInsurance();
        }
        return $this;
    }

    private function atualizaAdiantamentos(): self
    {
        foreach ($this->cooperado as $cooperado) {
            $cooperado->getProducaoCooperativista()->atualizaAdiantamentos();
        }
        return $this;
    }

    private function distribuiSobras(): void
    {
        $aDistribuir = $this->getTotalSobrasDoMes() + $this->getTotalSobrasDistribuidasNoMes();
        if ($aDistribuir === 0.0) {
            return;
        }

        $trabalhadoPorClienteInterno = $this->getTrabalhadoPorClienteInterno();
        $pesoTotal = 0;
        foreach ($trabalhadoPorClienteInterno as $data) {
            $pesoTotal += $this->getCooperado($data['tax_number'])->getPesoFinal();
        }
        $pesoTotal = $pesoTotal ?: 1;
        foreach ($trabalhadoPorClienteInterno as $data) {
            $cooperado = $this->getCooperado($data['tax_number']);
            $aReceber = ($cooperado->getPesoFinal() / $pesoTotal) * $aDistribuir;
            $values = $cooperado->getProducaoCooperativista()->getValues();
            $values->setBaseProducao($values->getBaseProducao() + $aReceber);
        }
    }

    private function getCooperado(string $taxNumber): Cooperado
    {
        if (!isset($this->cooperado[$taxNumber])) {
            $this->cooperado[$taxNumber] = new Cooperado(
                anoFiscal: (int) $this->dates->getInicio()->format('Y'),
                mes: (int) $this->dates->getInicio()->format('m'),
                entityManager: $this->entityManager,
                dates: $this->dates,
                numberFormatter: $this->numberFormatter,
                documents: $this->documents,
                request: $this->request,
            );
        }
        return $this->cooperado[$taxNumber];
    }

    private function distribuiProducao(): void
    {
        if ($this->producaoDistribuida) {
            return;
        }
        $this->distribuiProducaoDescontoFixo();

        $clientesDescontoFixo = array_column($this->movimentacao->getEntradasClientes(percentualDescontoFixo: true), 'customer_reference');

        $trabalhadoPorCliente = $this->getTrabalhadoPorCliente();
        $listaPesoTotal = [];
        $cooperados = [];
        $listaTempoContratado = [];
        $listaTempoTrabalhado = [];
        foreach ($trabalhadoPorCliente as $row) {
            if (in_array($row['customer_reference'], $clientesDescontoFixo)) {
                continue;
            }
            if (!isset($listaTempoContratado[$row['customer_reference']])) {
                $listaTempoContratado[$row['customer_reference']] = $row['total_cliente'];
            }
            if (!isset($listaTempoTrabalhado[$row['customer_reference']])) {
                $listaTempoTrabalhado[$row['customer_reference']] = 0;
            }
            $listaTempoTrabalhado[$row['customer_reference']] += $row['trabalhado'];
            $cooperado = $this->getCooperado($row['tax_number']);
            $pesoFinal = $cooperado->getPesoFinal() + $row['trabalhado'] * $row['peso'];
            if (!is_numeric($row['peso']) || $row['peso'] <= 0) {
                $row['peso'] = 1;
            }
            $cooperado->setPesoFinal($pesoFinal);
            $cooperados[$row['tax_number']] = $cooperado;
            $listaPesoTotal[$cooperado->getTaxNumber()] = $pesoFinal;
        }

        $totalTempoContratado = array_sum($listaTempoContratado);
        $totalTempoTrabalhado = array_sum($listaTempoTrabalhado);
        $pesoTotal = array_sum($listaPesoTotal) ?: 1;

        $totalBasePorCliente = array_column($this->movimentacao->getEntradasClientes(percentualDescontoFixo: false), 'base_producao', 'id');
        $aDistribuir = array_sum($totalBasePorCliente);

        if ($totalTempoTrabalhado < $totalTempoContratado) {
            $aDistribuir = $totalTempoTrabalhado * $aDistribuir / $totalTempoContratado;
        }

        foreach ($cooperados as $cooperado) {
            $aReceber = ($cooperado->getPesoFinal() / $pesoTotal) * $aDistribuir;
            $values = $cooperado->getProducaoCooperativista()->getValues();
            $values->setBaseProducao($values->getBaseProducao() + $aReceber);
        }
        $this->distribuiSobras();
        $this->producaoDistribuida = true;
    }

    private function distribuiProducaoDescontoFixo(): void
    {
        $entradas = $this->movimentacao->getEntradasClientes(percentualDescontoFixo: true);
        $trabalhadoPorCliente = $this->getTrabalhadoPorCliente();
        foreach ($entradas as $entrada) {
            $customerReference = $entrada['customer_reference'];
            $aDistribuir = $entrada['base_producao'];
            $pesoTotal = 0;
            $pesosCooperados = [];
            foreach ($trabalhadoPorCliente as $row) {
                if ($row['customer_reference'] !== $customerReference) {
                    continue;
                }
                if (!isset($pesosCooperados[$row['tax_number']])) {
                    $pesosCooperados[$row['tax_number']] = 0;
                }
                $pesosCooperados[$row['tax_number']] += $row['trabalhado'] * $row['peso'];
                $pesoTotal += $pesosCooperados[$row['tax_number']];
            }
            $pesoTotal = $pesoTotal ?: 1;
            foreach ($pesosCooperados as $taxNumber => $pesoCooperado) {
                $cooperado = $this->getCooperado((string) $taxNumber);
                $aReceber = ($pesoCooperado / $pesoTotal) * $aDistribuir;
                $values = $cooperado->getProducaoCooperativista()->getValues();
                $values->setBaseProducao($values->getBaseProducao() + $aReceber);
            }
        }
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
            ->andWhere($qb->expr()->eq('i.archive', $qb->createNamedParameter(0), ParameterType::INTEGER))
            ->andWhere($qb->expr()->eq('i.category_id', $qb->createNamedParameter(getenv('AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID')), ParameterType::INTEGER));
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
                'formula' => '{total_dispendios_clientes_percentual_movel} = ' . implode(' + ', array_column($this->movimentacao->getCustosPorCliente(), 'amount')) .
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
                'valor' => abs(round($this->getTotalSobrasDoMes(), 2)),
                'formula' => '{total_sobras_do_mes} = {total_notas_clientes} - {total_dispendios_clientes_percentual_movel} - {total_dispendios_internos} - {base_producao} - {reserva} - {total_percentual_desconto_fixo} + {total_sobras_clientes_percentual_fixo}'
            ],
            'total_sobras_distribuidas' => [
                'valor' => $this->getTotalSobrasDistribuidasNoMes() + abs(round($this->getTotalSobrasDoMes(), 2)),
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

        $custosPorCliente = $this->movimentacao->getCustosPorCliente();

        $formula = [];
        foreach ($entradasClientes as $row) {
            if (isset($custosPorCliente[$row['customer_reference']])) {
                $base = '(<a href="'.
                    $this->urlGenerator->generate('app_invoices_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'id' => $row['id'],
                    ]).
                    '" title="'.$row['contact_name'].'">' . $row['amount'] . "</a> - {$custosPorCliente[$row['customer_reference']]})";
                unset($custosPorCliente[$row['customer_reference']]);
            } else {
                $base = '<a href="'.
                    $this->urlGenerator->generate('app_invoices_index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'id' => $row['id'],
                    ]).
                    '" title="'.$row['contact_name'].'">' . $row['amount'] . '</a>';
            }
            if (!$row['percentual_desconto_fixo']) {
                $row['discount_percentage'] = '{percentual_administrativo}';
            }
            $formula[] = "($base - ($base * {$row['discount_percentage']} / 100))";
        }
        return implode("<br /> + ", $formula);
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
                '" title="'.$row['contact_name'].'">' . $row['amount'] . '</a>';
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
        $positions = $this->listToNumber(array_keys($data), count(Colors::list));
        $colorList = array_keys(Colors::list);
        $sequence = 0;
        foreach ($data as $key => $value) {
            if (!empty($data[$key]['formula'])) {
                preg_match_all('/\{(?<name>\w+)\}/', $value['formula'], $matches, PREG_SET_ORDER, 0);
                foreach ($matches as $match) {
                    $value['formula'] = preg_replace(
                        '/\{' . $match['name'] . '\}/',
                        '<span class="' . $match['name'] . '">' . $data[$match['name']]['valor'] . '</span>',
                        $value['formula']
                    );
                }
                $data[$key]['formula'] = preg_replace(['/{/', '/}/'], ['<span class="', '"></span>'], $value['formula']);
            } else {
                $data[$key]['formula'] = "<span class=\"$key\">{$data[$key]['valor']}</span>";
            }
            if (empty($value['color'])) {
                $data[$key]['color'] = Colors::list[$colorList[$positions[$sequence]]];
                $sequence++;
            }
            $data[$key]['font_color'] = Colors::colorContrast(...$data[$key]['color']);
            $data[$key]['label'] = $value['label'] ?? ucfirst(str_replace('_', ' ', $key));
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
            $toCsv = $cooperado->getProducaoCooperativista()->getValues()->toArray();
            $toCsv['adiantamento'] = array_reduce($toCsv['adiantamento'], fn ($c, $i) => $c += $i['amount'], 0);
            $output[] = $this->csvstr($toCsv);
        }
        $output = implode("\n", $output);
        return $output;
    }

    private function csvstr(array $fields): string
    {
        $f = fopen('php://memory', 'r+');
        if (fputcsv($f, $fields) === false) {
            return '';
        }
        rewind($f);
        $csv_line = stream_get_contents($f);
        return rtrim($csv_line);
    }
}
