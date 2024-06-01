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

namespace ProducaoCooperativista\Service;

use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use NumberFormatter;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\Colors;
use ProducaoCooperativista\Helper\Dates;
use ProducaoCooperativista\Provider\Akaunting\Request;
use ProducaoCooperativista\Service\Akaunting\Document\Taxes\Cofins;
use ProducaoCooperativista\Service\Akaunting\Document\Taxes\IrpfRetidoNaNota;
use ProducaoCooperativista\Service\Akaunting\Document\Taxes\Iss;
use ProducaoCooperativista\Service\Akaunting\Document\Taxes\Pis;
use ProducaoCooperativista\Service\Akaunting\Source\Categories;
use ProducaoCooperativista\Service\Akaunting\Source\Documents;
use ProducaoCooperativista\Service\Akaunting\Source\Taxes;
use ProducaoCooperativista\Service\Akaunting\Source\Transactions;
use ProducaoCooperativista\Service\Kimai\Source\Customers;
use ProducaoCooperativista\Service\Kimai\Source\Projects;
use ProducaoCooperativista\Service\Kimai\Source\Timesheets;
use ProducaoCooperativista\Service\Kimai\Source\Users;
use ProducaoCooperativista\Service\Source\Nfse;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;

class ProducaoCooperativista
{
    private array $custosPorCliente = [];
    private array $percentualTrabalhadoPorClienteExterno = [];
    private array $percentualTrabalhadoPorClienteInterno = [];
    private array $entradas = [];
    private array $saidas = [];
    private array $movimentacao = [];
    private array $dispendios = [];
    /** @var Cooperado[] */
    private array $cooperado = [];
    private array $categoriesList = [];
    private int $totalCooperados = 0;
    private float $totalPagoNotasClientes = 0;
    private float $totalBrutoNotasClientes = 0;
    private float $totalCustoCliente = 0;
    private float $totalDispendios = 0;
    private int $percentualMaximo = 0;
    private float $taxaMinima = 0;
    private float $taxaMaxima = 0;
    private float $taxaAdministrativa = 0;
    private float $percentualDispendios = 0;
    private bool $sobrasDistribuidas = false;

    public function __construct(
        private Database $db,
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
        private UrlGenerator $urlGenerator,
    ) {
    }

    /**
     * Valor reservado de cada projeto para pagar os dispêndios
     */
    private function percentualDispendios(): float
    {
        if ($this->percentualDispendios) {
            return $this->percentualDispendios;
        }
        /**
         * Para a taxa mínima utiliza-se o total de dispêndios apenas pois no total
         * de dispêndios já está sem o custo dos clientes.
         */
        $this->taxaMinima = $this->getTotalDispendios();
        $this->taxaMaxima = $this->taxaMinima * 2;
        if ($this->taxaMinima * $this->percentualMaximo / 100 >= $this->taxaMaxima) {
            $this->taxaAdministrativa = $this->taxaMaxima;
        } elseif ($this->taxaMinima * $this->percentualMaximo / 100 >= $this->taxaMinima) {
            $this->taxaAdministrativa = $this->taxaMinima * $this->percentualMaximo / 100;
        } else {
            $this->taxaAdministrativa = $this->taxaMinima;
        }

        if ($this->taxaMinima) {
            $this->percentualDispendios = $this->taxaAdministrativa / ($this->taxaMinima) * 100;
            return $this->percentualDispendios;
        }
        return 0;
    }

    private function getTotalHorasTrabalhadas(): float
    {
        $interno = $this->getPercentualTrabalhadoPorClienteInterno();
        $externo = $this->getPercentualTrabalhadoPorClienteExterno();
        $totalInterno = array_sum(array_column($interno, 'trabalhado'));
        $totalExterno = array_sum(array_column($externo, 'trabalhado'));
        return ($totalInterno + $totalExterno) / 60 / 60;
    }

    private function getTotalHorasPossiveis(): float
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
        $stmt = $this->db->getConnection()->prepare(
            <<<SQL
            -- Total pessoas que registraram horas no mês
            SELECT count(distinct t.user_id) as total_cooperados
                FROM timesheet t
                JOIN users u ON u.id = t.user_id 
            WHERE t.`begin` >= :inicio
                AND t.`end` <= :fim
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $this->dates->getInicio()->format('Y-m-d'),
            'fim' => $this->dates->getFim()->format('Y-m-d'),
        ]);
        $result = $result->fetchOne();

        if (!$result) {
            $messagem = sprintf(
                'Sem registro de horas no Kimai entre os dias %s e %s.',
                $this->dates->getInicio()->format(('Y-m-d')),
                $this->dates->getFim()->format(('Y-m-d'))
            );
            throw new Exception($messagem);
        }
        $this->totalCooperados = (int) $result;
        $this->logger->info('Total pessoas no mês: {total}', ['total' => $this->totalCooperados]);
        return $this->totalCooperados;
    }

    /**
     * Dispêndios internos
     *
     * São todos os dispêndios da cooperativa tirando dispêndios do cliente e do cooperado.
     */
    private function getTotalDispendiosInternos(): float
    {
        if ($this->totalDispendios) {
            return $this->totalDispendios;
        }
        $dispendiosInternos = $this->getChildrensCategories((int) getenv('AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID'));
        $dispendios = array_filter($this->getSaidas(), function ($i) use ($dispendiosInternos): bool {
            if ($i['transaction_of_month'] === $this->dates->getInicioProximoMes()->format('Y-m')) {
                if ($i['archive'] === 0) {
                    if (in_array($i['category_id'], $dispendiosInternos)) {
                        return true;
                    }
                }
            }
            return false;
        });
        $this->totalDispendios = array_reduce($dispendios, fn ($total, $i) => $total += $i['amount'], 0);
        $this->logger->info('Total dispêndios: {total}', ['total' => $this->totalDispendios]);
        return $this->totalDispendios;
    }

    public function getChildrensCategories(int $id): array
    {
        $childrens = [];
        foreach ($this->getCategories() as $category) {
            if ($category['parent_id'] === $id) {
                $childrens[] = $category['id'];
                $childrens = array_merge($childrens, $this->getChildrensCategories($category['id']));
            }
            if ($category['id'] === $id) {
                $childrens[] = $category['id'];
            }
        }
        return array_values(array_unique($childrens));
    }

    public function getCategories(): array
    {
        if (!empty($this->categoriesList)) {
            return $this->categoriesList;
        }
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('*')
            ->from('categories');
        $result = $select->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $this->categoriesList[] = $row;
        }
        if (empty($this->categoriesList)) {
            throw new Exception('Sem categorias');
        }
        return $this->categoriesList;
    }

    public function getCapitalSocial(): array
    {
        $stmt = $this->db->getConnection(Database::DB_AKAUNTING)->prepare(
            <<<SQL
            SELECT * FROM (
            -- Transactions
            SELECT contact_id, c.name, amount,
                paid_at AS due_at,
                t.id,
                'transaction' as 'table'
            FROM transactions t
            join contacts c on c.id = t.contact_id
            WHERE category_id = 25
            and document_id is null
            AND t.deleted_at IS NULL
            UNION
            -- Documents
            SELECT contact_id, contact_Name, amount, due_at,
                d.id,
                'documents' as 'table'
            FROM documents d
            WHERE d.category_id = 25
            AND d.deleted_at IS NULL
            UNION
            -- Documents with items
            SELECT d.contact_id, d.contact_Name, di.price, d.due_at,
                d.id,
                'documents_item' as 'table'
            FROM document_items as di
            JOIN documents as d on di.document_id = d.id
            where di.item_id = 11
            AND d.deleted_at IS NULL
            AND di.deleted_at IS NULL
            ) x
            ORDER BY x.name, x.due_at
            SQL
        );
        $result = $stmt->executeQuery();
        $return = [];
        while ($row = $result->fetchAssociative()) {
            $return[] = $row;
        }
        if (empty($return)) {
            throw new Exception('Sem capital social');
        }
        return $return;
    }

    public function getCapitalSocialSummarized(): array
    {
        $capitalSocial = $this->getCapitalSocial();
        $return = [];
        foreach ($capitalSocial as $row) {
            $return[$row['name']] = [
                'nome' => '<a href="' .
                    $this->urlGenerator->generate('CapitalSocial#index', [
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

    public function getSaidas(): array
    {
        $movimentacao = $this->getMovimentacaoFinanceira();
        $saidas = array_filter($movimentacao, fn ($i) => $i['category_type'] === 'expense');
        return $saidas;
    }

    public function getMovimentacaoFinanceira(): array
    {
        if (!empty($this->movimentacao)) {
            return $this->movimentacao;
        }
        $stmt = $this->db->getConnection()->prepare(
            <<<SQL
            SELECT 'transaction' AS 'table',
                t.id,
                t.type,
                t.transaction_of_month,
                t.discount_percentage,
                t.amount,
                (
                    SELECT SUM(price) as total
                    FROM JSON_TABLE(
                        i.metadata,
                        '$.items.data[*]' COLUMNS (
                            price DOUBLE PATH '$.price'
                        )
                    ) price
                ) bruto,
                t.customer_reference,
                t.contact_name,
                t.contact_type,
                t.category_id,
                t.category_name,
                t.category_type,
                t.archive,
                t.metadata
            FROM transactions t
            LEFT JOIN invoices i ON t.metadata->>'$.document_id' = i.id
            WHERE t.transaction_of_month = :ano_mes
                AND t.archive = 0
                AND i.id IS NULL
            UNION
            SELECT 'invoice_transaction' AS 'table',
                i.id,
                i.type,
                i.transaction_of_month,
                i.discount_percentage,
                i.amount,
                (
                    SELECT SUM(price) as total
                    FROM JSON_TABLE(
                        i.metadata,
                        '$.items.data[*]' COLUMNS (
                            price DOUBLE PATH '$.price'
                        )
                    ) price
                ) bruto,
                i.customer_reference,
                i.contact_name,
                i.contact_type,
                i.category_id,
                i.category_name,
                i.category_type,
                i.archive,
                i.metadata
            FROM transactions t
            JOIN invoices i ON t.metadata->>'$.document_id' = i.id
            WHERE t.transaction_of_month = :ano_mes
                AND t.archive = 0
            UNION
            SELECT 'invoice' AS 'table',
                i.id,
                i.type,
                i.transaction_of_month,
                i.discount_percentage,
                i.amount,
                (
                    SELECT SUM(price) as total
                    FROM JSON_TABLE(
                        i.metadata,
                        '$.items.data[*]' COLUMNS (
                            price DOUBLE PATH '$.price'
                        )
                    ) price
                ) bruto,
                i.customer_reference,
                i.contact_name,
                i.contact_type,
                i.category_id,
                i.category_name,
                i.category_type,
                i.archive,
                i.metadata
            FROM invoices i
            LEFT JOIN transactions t ON t.metadata->>'$.document_id' = i.id
            WHERE i.transaction_of_month = :ano_mes
                AND i.archive = 0
                AND t.id IS NULL
            SQL
        );
        $result = $stmt->executeQuery([
            'ano_mes' => $this->dates->getInicioProximoMes()->format('Y-m'),
        ]);
        $errors = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['customer_reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['customer_reference'])) {
                $errors[] = $row;
            }
            $this->movimentacao[$row['id']] = $row;
        }
        if (empty($this->movimentacao)) {
            throw new Exception('Sem transações');
        }

        if (count($errors)) {
            throw new Exception(sprintf(
                "Código de cliente inválido na movimentação do Akaunting.\n" .
                "Intervalo: %s a %s\n" .
                "Dados:\n%s",
                $this->dates->getInicioProximoMes()->format('Y-m-d'),
                $this->dates->getFimProximoMes()->format('Y-m-d'),
                json_encode($errors, JSON_PRETTY_PRINT)
            ));
        }

        $this->logger->debug('Movimentação', [$this->movimentacao]);
        $this->calculaBaseProducaoPorEntrada();
        return $this->movimentacao;
    }

    private function setMovimentacao($movimentacao): void
    {
        $this->movimentacao[$movimentacao['id']] = $movimentacao;
    }

    /**
     * Retorna valor total pago de notas em um mês
     *
     * @throws Exception
     */
    private function getTotalPagoNotasClientes(): float
    {
        if ($this->totalPagoNotasClientes) {
            return $this->totalPagoNotasClientes;
        }

        $this->totalPagoNotasClientes = array_reduce($this->getEntradasClientes(), fn ($total, $i) => $total + $i['amount'], 0);
        return $this->totalPagoNotasClientes;
    }

    /**
     * Retorna valor total bruto de notas em um mês
     *
     * @throws Exception
     */
    private function getTotalBrutoNotasClientes(): float
    {
        if ($this->totalBrutoNotasClientes) {
            return $this->totalBrutoNotasClientes;
        }

        $this->totalBrutoNotasClientes = array_reduce($this->getEntradasClientes(), fn ($total, $i) => $total + $i['bruto'], 0);
        return $this->totalBrutoNotasClientes;
    }

    private function getEntradasClientes(): array
    {
        $categoriasEntradasClientes = $this->getChildrensCategories((int) getenv('AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID'));
        $entradasClientes = array_filter($this->getEntradas(), fn ($i) => in_array($i['category_id'], $categoriasEntradasClientes));
        return $entradasClientes;
    }

    /**
     * Total de custos por cliente
     *
     * @throws Exception
     * @return float
     */
    private function getTotalDispendiosClientes(): float
    {
        if ($this->totalCustoCliente) {
            return $this->totalCustoCliente;
        }
        $custosPorCliente = $this->getCustosPorCliente();
        $this->totalCustoCliente = array_reduce($custosPorCliente, function ($total, $row): float {
            $total += $row['amount'];
            return $total;
        }, 0);
        $this->logger->info('Total custos clientes: {total}', ['total' => $this->totalCustoCliente]);
        return $this->totalCustoCliente;
    }

    private function getTotalDispendios(): float
    {
        return $this->getTotalDispendiosClientes() + $this->getTotalDispendiosInternos();
    }

    /**
     * Lista de clientes e seus custos em um mês
     *
     * @throws Exception
     * @return array
     */
    private function getCustosPorCliente(): array
    {
        if ($this->custosPorCliente) {
            return $this->custosPorCliente;
        }
        $categoriasCustosClientes = $this->getChildrensCategories((int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'));
        $this->custosPorCliente = array_filter($this->getSaidas(), fn ($i) => in_array($i['category_id'], $categoriasCustosClientes));
        $this->logger->debug('Custos por clientes: {json}', ['json' => json_encode($this->custosPorCliente)]);
        return $this->custosPorCliente;
    }

    private function calculaBaseProducaoPorEntrada(): self
    {
        $entradasClientes = $this->getEntradasClientes();

        if (count($entradasClientes)) {
            $current = current($entradasClientes);
            if (!empty($current['base_producao'])) {
                return $this;
            }
        }

        $percentualDispendio = $this->percentualDispendios();
        $custosPorCliente = $this->getCustosPorCliente();
        $custosPorCliente = array_column($custosPorCliente, 'amount', 'customer_reference');

        foreach ($entradasClientes as $row) {
            $base = $row['amount'] - ($custosPorCliente[$row['customer_reference']] ?? 0);
            if (!is_numeric($row['discount_percentage'])) {
                $row['discount_percentage'] = $percentualDispendio;
            }
            $row['base_producao'] = $base - ($base * $row['discount_percentage'] / 100);
            $this->setMovimentacao($row);
        }

        $this->logger->debug('Entradas no mês com base de produção', [json_encode($entradasClientes)]);
        return $this;
    }

    public function getEntradas(): array
    {
        $movimentacao = $this->getMovimentacaoFinanceira();
        $entradas = array_filter($movimentacao, fn ($i) => $i['category_type'] === 'income');
        return $entradas;
    }

    private function clientesInternos(): array
    {
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        $cnpjClientesInternos = array_unique($cnpjClientesInternos);
        sort($cnpjClientesInternos);
        return $cnpjClientesInternos;
    }

    public function getPercentualTrabalhadoPorClienteInterno(): array
    {
        if (count($this->percentualTrabalhadoPorClienteInterno)) {
            return $this->percentualTrabalhadoPorClienteInterno;
        }
        $clientesInternos = $this->clientesInternos();

        $qb = new QueryBuilder($this->db->getConnection());

        $subQuery = new QueryBuilder($this->db->getConnection());
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
            ->addSelect('u.akaunting_contact_id')
            ->addSelect("'Interno' as name")
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
            ->addGroupBy('u.akaunting_contact_id')
            ->addGroupBy('total_cliente.total')
            ->orderBy('u.alias');
        $result = $qb->executeQuery();
        $this->percentualTrabalhadoPorClienteInterno = [];
        while ($row = $result->fetchAssociative()) {
            $this->getCooperado($row['tax_number'])
                ->setName($row['alias'])
                ->setDependentes($row['dependents'])
                ->setTaxNumber($row['tax_number'])
                ->setAkauntingContactId($row['akaunting_contact_id']);
            $row['base_producao'] = 0;
            $row['percentual_trabalhado'] = (float) $row['percentual_trabalhado'];
            $this->percentualTrabalhadoPorClienteInterno[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($this->percentualTrabalhadoPorClienteInterno)]);
        return $this->percentualTrabalhadoPorClienteInterno;
    }

    private function clientesContabilizaveis(): array
    {
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        $clientesContabilizaveis = array_column($this->getEntradasClientes(), 'customer_reference');
        $clientesContabilizaveis = array_diff(
            array_values($clientesContabilizaveis),
            $cnpjClientesInternos
        );
        $clientesContabilizaveis = array_unique($clientesContabilizaveis);
        return $clientesContabilizaveis;
    }

    public function getPercentualTrabalhadoPorClienteExterno(): array
    {
        if (count($this->percentualTrabalhadoPorClienteExterno)) {
            return $this->percentualTrabalhadoPorClienteExterno;
        }
        $contabilizaveis = $this->clientesContabilizaveis();

        $qb = new QueryBuilder($this->db->getConnection());

        $projetosAtivosNoMes = new QueryBuilder($this->db->getConnection());
        $projetosAtivosNoMes->select('c.id as customer_id')
            ->addSelect('sum(p.time_budget) as time_budget')
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
            ->groupBy('c.id');

        $subQuery = new QueryBuilder($this->db->getConnection());
        $subQuery->select('c.vat_id')
            ->addSelect('c.id')
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
            ->join('c', 'projects', 'p', $subQuery->expr()->eq('p.customer_id', 'c.id'))
            ->join('project_time_budget', 'timesheet', 't', $subQuery->expr()->eq('t.project_Id', 'p.id'))
            ->where($subQuery->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($subQuery->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->groupBy('c.vat_id')
            ->addGroupBy('c.id');

        $qb->select('u.alias')
            ->addSelect('u.tax_number')
            ->addSelect('u.dependents')
            ->addSelect('u.akaunting_contact_id')
            ->addSelect('c.id as customer_id')
            ->addSelect('c.name')
            ->addSelect('c.vat_id as customer_reference')
            ->addSelect('COALESCE(sum(t.duration), 0) * 100 / total_cliente.total as percentual_trabalhado')
            ->addSelect('sum(t.duration) as trabalhado')
            ->addSelect('total_cliente.total as total_cliente')
            ->from('customers', 'c')
            ->join('c', '(' . $subQuery->getSQL() . ')', 'total_cliente', 'c.id = total_cliente.id')
            ->join('c', 'projects', 'p', $qb->expr()->eq('p.customer_id', 'c.id'))
            ->join('p', 'timesheet', 't', $qb->expr()->eq('t.project_Id', 'p.id'))
            ->join('t', 'users', 'u', $qb->expr()->eq('t.user_id', 'u.id'))
            ->where($qb->expr()->in('c.vat_id', $qb->createNamedParameter($contabilizaveis, ArrayParameterType::STRING)))
            ->andWhere($qb->expr()->gte('t.begin', $qb->createNamedParameter($this->dates->getInicio()->format('Y-m-d'))))
            ->andWhere($qb->expr()->lte('t.end', $qb->createNamedParameter($this->dates->getFim()->format('Y-m-d H:i:s'))))
            ->groupBy('u.alias')
            ->addGroupBy('u.tax_number')
            ->addGroupBy('u.dependents')
            ->addGroupBy('u.akaunting_contact_id')
            ->addGroupBy('c.id')
            ->addGroupBy('c.name')
            ->addGroupBy('c.vat_id')
            ->orderBy('c.id')
            ->addOrderBy('u.alias');
        $result = $qb->executeQuery();
        $this->percentualTrabalhadoPorClienteExterno = [];
        while ($row = $result->fetchAssociative()) {
            if (!$row['customer_reference']) {
                continue;
            }
            $this->getCooperado($row['tax_number'])
                ->setName($row['alias'])
                ->setDependentes($row['dependents'])
                ->setTaxNumber($row['tax_number'])
                ->setAkauntingContactId($row['akaunting_contact_id']);
            $row['base_producao'] = 0;
            $row['percentual_trabalhado'] = (float) $row['percentual_trabalhado'];
            $this->percentualTrabalhadoPorClienteExterno[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($this->percentualTrabalhadoPorClienteExterno)]);
        return $this->percentualTrabalhadoPorClienteExterno;
    }

    private function cadastraCooperadoQueProduziuNoAkaunting(): void
    {
        $produzidoNoMes = $this->getPercentualTrabalhadoPorClienteExterno();
        $exists = [];
        foreach ($produzidoNoMes as $row) {
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
        $connection = $this->db->getConnection(Database::DB_AKAUNTING);
        $insert = new QueryBuilder($connection);
        $insert->insert('contacts')
            ->values([
                'company_id' => $insert->createNamedParameter(getenv('AKAUNTING_COMPANY_ID'), ParameterType::INTEGER),
                'type' => $insert->createNamedParameter('vendor'),
                'name' => $insert->createNamedParameter($name),
                'tax_number' => $insert->createNamedParameter($taxNumber),
                'country' => $insert->createNamedParameter('BR'),
                'currency_code' => $insert->createNamedParameter('BRL'),
                'enabled' => 1,
                'created_at' => $insert->createNamedParameter($this->dates->getDataProcessamento()->format('Y-m-d H:i:s')),
                'updated_at' => $insert->createNamedParameter($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ]);
        $insert->executeStatement();
        $id = $connection->lastInsertId();
        return (int) $id;
    }

    public function setPercentualMaximo(int $percentualMaximo): void
    {
        $this->percentualMaximo = $percentualMaximo;
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
            db: $this->db,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $inssIrpf->saveMonthTaxes();

        $cofins = new Cofins(
            db: $this->db,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $cofins->setTotalBrutoNotasClientes($this->getTotalBrutoNotasClientes());
        $cofins->saveMonthTaxes();

        $pis = new Pis(
            db: $this->db,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $pis->setTotalBrutoNotasClientes($this->getTotalBrutoNotasClientes());
        $pis->saveMonthTaxes();

        $iss = new Iss(
            db: $this->db,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $iss->setTotalBrutoNotasClientes($this->getTotalBrutoNotasClientes());
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

        $this->getMovimentacaoFinanceira();
        $this->getCustosPorCliente();
        $this->getTotalDispendiosInternos();
        $this->calculaBaseProducaoPorEntrada();
        $this->cadastraCooperadoQueProduziuNoAkaunting();
        $this->distribuiProducaoExterna();
        $this->distribuiSobras();
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
        $percentualTrabalhadoPorCliente = $this->getPercentualTrabalhadoPorClienteInterno();
        $sobras = $this->getTotalSobrasDoMes();
        foreach ($percentualTrabalhadoPorCliente as $row) {
            $aReceberDasSobras = ($sobras * $row['percentual_trabalhado'] / 100);
            $values = $this->getCooperado($row['tax_number'])->getProducaoCooperativista()->getValues();
            $values->setBaseProducao($values->getBaseProducao() + $aReceberDasSobras);
        }
    }

    private function getCooperado(string $taxNumber): Cooperado
    {
        if (!isset($this->cooperado[$taxNumber])) {
            $this->cooperado[$taxNumber] = new Cooperado(
                anoFiscal: (int) $this->dates->getInicio()->format('Y'),
                mes: (int) $this->dates->getInicio()->format('m'),
                db: $this->db,
                dates: $this->dates,
                numberFormatter: $this->numberFormatter,
                documents: $this->documents,
                request: $this->request,
            );
        }
        return $this->cooperado[$taxNumber];
    }

    private function distribuiProducaoExterna(): void
    {
        if ($this->sobrasDistribuidas) {
            return;
        }
        $percentualTrabalhadoPorClienteExterno = $this->getPercentualTrabalhadoPorClienteExterno();
        $totalPorCliente = array_column($this->getEntradasClientes(), 'base_producao', 'customer_reference');
        $errorSemCodigoCliente = [];
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        foreach ($percentualTrabalhadoPorClienteExterno as $row) {
            if (in_array($row['customer_reference'], $cnpjClientesInternos)) {
                continue;
            }
            if (!isset($totalPorCliente[$row['customer_reference']])) {
                $errorSemCodigoCliente[] = $row;
                continue;
            }
            $brutoCliente = $totalPorCliente[$row['customer_reference']];
            $aReceber = $brutoCliente * $row['percentual_trabalhado'] / 100;
            $values = $this->getCooperado($row['tax_number'])->getProducaoCooperativista()->getValues();
            $values->setBaseProducao($values->getBaseProducao() + $aReceber);
        }
        if (count($errorSemCodigoCliente)) {
            throw new Exception(
                "O customer_reference trabalhado no Kimai não possui faturamento no mês " . $this->dates->getInicioProximoMes()->format('Y-m-d'). ".\n" .
                "Dados:\n" .
                json_encode($errorSemCodigoCliente, JSON_PRETTY_PRINT)
            );
        }
        $this->sobrasDistribuidas = true;
    }

    private function getTotalBaseProducao(): float
    {
        $baseProducao = array_reduce(
            $this->cooperado,
            fn ($carry, $cooperado) => $carry += $cooperado->getProducaoCooperativista()->getValues()->getBaseProducao(),
            0
        );
        return $baseProducao;
    }

    private function getTotalSobrasDoMes(): float
    {
        $this->distribuiProducaoExterna();
        return $this->getTotalPagoNotasClientes()
            - $this->getTotalDispendios();
    }

    private function getTotalSobrasDistribuidasNoMes(): float
    {
        $qb = new QueryBuilder($this->db->getConnection());
        $qb->select('SUM(i.amount) AS total')
            ->from('invoices', 'i')
            ->where($qb->expr()->eq('transaction_of_month', $qb->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))))
            ->andWhere($qb->expr()->eq('i.type', $qb->createNamedParameter('invoice')))
            ->andWhere($qb->expr()->eq('i.archive', $qb->createNamedParameter(0), ParameterType::INTEGER))
            ->andWhere($qb->expr()->eq('i.category_id', $qb->createNamedParameter(getenv('AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID')), ParameterType::INTEGER));
        $result = $qb->executeQuery();
        $total = $result->fetchOne();
        return (float) $total;
    }

    public function exportData(): array
    {
        $this->getProducaoCooperativista();
        $return = [
            'taxa_minima' => [
                'valor' => $this->taxaMinima,
                'formula' => '{taxa_minima} = {total_dispendios}'
            ],
            'taxa_maxima' => [
                'valor' => $this->taxaMaxima,
                'formula' => '{taxa_maxima} = {taxa_minima} * 2'
            ],
            'percentual_naximo' => ['valor' => $this->percentualMaximo],
            'taxa_administrativa' => [
                'valor' => $this->taxaAdministrativa,
                'formula' => <<<FORMULA
                    <pre>
                    SE ({total_dispendios} * {percentual_naximo} / 100 >= {taxa_maxima}) &lbrace;
                        {taxa_administrativa} = {taxa_maxima}
                    &rbrace; SENÃO SE ({total_dispendios} * {percentual_naximo} / 100 >= {taxa_minima}) &lbrace;
                        {taxa_administrativa} = {total_dispendios} * {percentual_naximo} / 100
                    &rbrace; SENÃO &lbrace;
                        {taxa_administrativa} = {taxa_minima}
                    &rbrace;
                    </pre>
                    FORMULA
            ],
            'total_notas_clientes' => [
                'valor' => $this->getTotalPagoNotasClientes(),
                'formula' => '{total_notas_clientes} = ' . implode(' + ', array_column($this->getEntradasClientes(), 'amount')) .
                ' <a href="' .
                $this->urlGenerator->generate('Invoices#index', [
                    'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    'entrada_cliente' => 'sim',
                    'category_type' => 'income',
                ]) .
                '">notas clientes</a>'
            ],
            'total_dispendios_clientes' => [
                'valor' => $this->getTotalDispendiosClientes(),
                'formula' => '{total_dispendios_clientes} = ' . implode(' + ', array_column($this->getCustosPorCliente(), 'amount')) .
                ' <a href="' .
                $this->urlGenerator->generate('Invoices#index', [
                    'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                    'custos_clientes' => 'sim',
                    'category_type' => 'expense',
                ]) .
                '">dispêndios clientes</a>'
            ],
            'total_dispendios_internos' => [
                'valor' => $this->getTotalDispendiosInternos(),
                'formula' => '{total_dispendios_internos} = somatório de ' .
                    '<a href="' .
                    $this->urlGenerator->generate('Invoices#index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'dispendio_interno' => 'sim',
                    ]) .
                    '">itens</a>' .
                    ' com ' .
                    '<a href="' .
                    $this->urlGenerator->generate('Categorias#index', [
                        'dispendio_interno' => 'sim',
                    ]) .
                    '">categoria</a>' .
                    ' de ' .
                    '<a href="' .
                    $this->urlGenerator->generate('Invoices#index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'dispendio_interno' => 'sim',
                    ]) .
                    '">dispêndio interno</a>'
            ],
            'total_dispendios' => [
                'valor' => $this->getTotalDispendios(),
                'formula' => '{total_dispendios} = {total_dispendios_clientes} + {total_dispendios_internos}',
            ],
            'total_sobras_distribuidas' => [
                'valor' => $this->getTotalSobrasDistribuidasNoMes(),
                'formula' => '{total_sobras_distribuidas}' .
                    ' <a href="' .
                    $this->urlGenerator->generate('Invoices#index', [
                        'ano-mes' => $this->dates->getInicio()->format('Y-m'),
                        'category_name' => 'Distribuição de sobras',
                    ]) .
                    '">disrtibuição de sobras</a>'
            ],
            'total_sobras_do_mes' => [
                'valor' => $this->getTotalSobrasDoMes(),
                'formula' => '{total_sobras_do_mes} = {total_notas_clientes} - {taxa_administrativa}'
            ],
            'base_producao' => ['valor' => $this->getTotalBaseProducao()],
            'total_horas_trabalhadas' => ['valor' => $this->getTotalHorasTrabalhadas()],
            'total_horas_possiveis' => [
                'valor' => $this->getTotalHorasPossiveis(),
                'formula' => '{total_horas_possiveis} = {total_cooperados} * 8 * {dias_uteis_no_mes}'
            ],
            'total_cooperados' => ['valor' => $this->getTotalCooperados()],
            'dias_uteis_no_mes' => ['valor' => $this->dates->getDiasUteisNoMes()],
            'transacao_do_mes' => ['valor' => $this->dates->getInicioProximoMes()->format('Y-m')],
            'ano_mes_trabalhado' => ['valor' => $this->dates->getInicio()->format('Y-m')],
        ];
        return $this->formatData($return);
    }

    private function listToNumber(array $list, int $max): array
    {
        $total = count($list);
        $return = [];
        $distance = floor($max / $total);
        $current = 0;
        while(count($return) < $total) {
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
