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
    private array $percentualTrabalhadoPorCliente = [];
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
    private int $totalSegundosLibreCode = 0;
    private float $baseCalculoDispendios = 0;
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

    private function getBaseCalculoDispendios(): float
    {
        if (!empty($this->baseCalculoDispendios)) {
            return $this->baseCalculoDispendios;
        }
        $this->baseCalculoDispendios = $this->getTotalPagoNotasClientes() - $this->getTotalDispendiosClientes();
        return $this->baseCalculoDispendios;
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
        $this->taxaMinima = $this->getTotalDispendiosInternos();
        $this->taxaMaxima = $this->taxaMinima * 2;
        if ($this->getBaseCalculoDispendios() * $this->percentualMaximo / 100 >= $this->taxaMaxima) {
            $this->taxaAdministrativa = $this->taxaMaxima;
        } elseif ($this->getBaseCalculoDispendios() * $this->percentualMaximo / 100 >= $this->taxaMinima) {
            $this->taxaAdministrativa = $this->getBaseCalculoDispendios() * $this->percentualMaximo / 100;
        } else {
            $this->taxaAdministrativa = $this->taxaMinima;
        }

        if ($this->getBaseCalculoDispendios()) {
            $this->percentualDispendios = $this->taxaAdministrativa / ($this->getBaseCalculoDispendios()) * 100;
            return $this->percentualDispendios;
        }
        return 0;
    }

    /**
     * O percentual LibreCode é utilizado para pagar quem trabalha diretamente
     * para a LibreCode, neste cenário entra Conselho Administrativo e quem
     * trabalha executando atividades em projetos internos.
     *
     * Este percentual é a proporção entre total de horas trabalhadas para a
     * LibreCode versus o total hipotético de trabalho 100% das horas pelo total
     * de cooperados que registraram horas no Kimai.
     */
    private function percentualLibreCode(): float
    {
        $totalPossivelDeHoras = $this->getTotalHorasPossiveis();

        $totalHorasLibreCode = $this->getTotalHorasLibreCode();
        $percentualLibreCode = $totalHorasLibreCode * 100 / $totalPossivelDeHoras;

        return $percentualLibreCode;
    }

    private function getTotalHorasPossiveis(): float
    {
        return $this->getTotalCooperados() * 8 * $this->dates->getDiasUteisNoMes();
    }

    private function getTotalHorasLibreCode(): float
    {
        return $this->getTotalSegundosLibreCode() / 60 / 60;
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

    private function getTotalSegundosLibreCode(): int
    {
        if ($this->totalSegundosLibreCode) {
            return $this->totalSegundosLibreCode;
        }
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        $cnpjClientesInternos = "'" . implode("','", $cnpjClientesInternos) . "'";
        $stmt = $this->db->getConnection()->prepare(
            <<<SQL
            -- Total horas LibreCode
            SELECT sum(t.duration) as total_segundos_librecode
                FROM timesheet t
                JOIN projects p ON t.project_id = p.id
                JOIN customers c ON p.customer_id = c.id
                JOIN users u ON u.id = t.user_id
            WHERE t.`begin` >= :inicio
                AND t.`end` <= :fim
                AND c.vat_id IN ($cnpjClientesInternos)
            GROUP BY c.name
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $this->dates->getInicio()->format('Y-m-d'),
            'fim' => $this->dates->getFim()->format('Y-m-d H:i:s'),
        ]);
        $this->totalSegundosLibreCode = (int) $result->fetchOne();
        $this->logger->info('Total segundos LibreCode: {total}', ['total' => $this->totalSegundosLibreCode]);
        return $this->totalSegundosLibreCode;
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

    private function getPercentualDesconto(): float
    {
        $percentualDesconto = $this->percentualDispendios() + $this->percentualLibreCode();
        return $percentualDesconto;
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

        $percentualDesconto = $this->getPercentualDesconto();
        $custosPorCliente = $this->getCustosPorCliente();
        $custosPorCliente = array_column($custosPorCliente, 'amount', 'customer_reference');

        foreach ($entradasClientes as $row) {
            $base = $row['amount'] - ($custosPorCliente[$row['customer_reference']] ?? 0);
            if (!is_numeric($row['discount_percentage'])) {
                $row['discount_percentage'] = $percentualDesconto;
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

    private function clientesContabilizaveis(): array
    {
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        $clientesContabilizaveis = array_column($this->getEntradasClientes(), 'customer_reference');
        $clientesContabilizaveis = array_merge(
            array_values($clientesContabilizaveis),
            $cnpjClientesInternos
        );
        $clientesContabilizaveis = array_unique($clientesContabilizaveis);
        return $clientesContabilizaveis;
    }

    public function getPercentualTrabalhadoPorCliente(): array
    {
        if (count($this->percentualTrabalhadoPorCliente)) {
            return $this->percentualTrabalhadoPorCliente;
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
        $this->percentualTrabalhadoPorCliente = [];
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
            $this->percentualTrabalhadoPorCliente[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($this->percentualTrabalhadoPorCliente)]);
        return $this->percentualTrabalhadoPorCliente;
    }

    private function cadastraCooperadoQueProduziuNoAkaunting(): void
    {
        $produzidoNoMes = $this->getPercentualTrabalhadoPorCliente();
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
        $percentualTrabalhadoPorCliente = $this->getPercentualTrabalhadoPorCliente();
        $sobras = $this->getTotalSobrasDoMes();
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        foreach ($percentualTrabalhadoPorCliente as $row) {
            if (!in_array($row['customer_reference'], $cnpjClientesInternos)) {
                continue;
            }
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
        $percentualTrabalhadoPorCliente = $this->getPercentualTrabalhadoPorCliente();
        $totalPorCliente = array_column($this->getEntradasClientes(), 'base_producao', 'customer_reference');
        $errorSemCodigoCliente = [];
        $cnpjClientesInternos = explode(',', getenv('CNPJ_CLIENTES_INTERNOS'));
        foreach ($percentualTrabalhadoPorCliente as $row) {
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
        return $this->getBaseCalculoDispendios()
            + $this->getTotalSobrasDistribuidasNoMes()
            - $this->getTotalDispendiosInternos()
            - $this->getTotalBaseProducao();
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
            'percentual_dispendios' => [
                'valor' => $this->percentualDispendios(),
                'formula' => <<<FORMULA
                    <pre>
                    SE ({base_calculo_dispendios} > 0) &lbrace;
                        {percentual_dispendios} = {taxa_administrativa} / {base_calculo_dispendios} * 100
                    &rbrace; SENÃO &lbrace;
                        {percentual_dispendios} = 0
                    &rbrace;
                    </pre>
                    FORMULA
            ],
            'taxa_minima' => [
                'valor' => $this->taxaMinima,
                'formula' => '{taxa_minima} = {total_dispendios_internos}'
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
            'taxa_maxima' => [
                'valor' => $this->taxaMaxima,
                'formula' => '{taxa_minima} * 2 = {taxa_maxima}'
            ],
            'percentual_naximo' => ['valor' => $this->percentualMaximo],
            'taxa_administrativa' => [
                'valor' => $this->taxaAdministrativa,
                'formula' => <<<FORMULA
                    <pre>
                    SE ({base_calculo_dispendios} * {percentual_naximo} / 100 >= {taxa_maxima}) &lbrace;
                        {taxa_administrativa} = {taxa_maxima}
                    &rbrace; SENÃO SE ({base_calculo_dispendios} * {percentual_naximo} / 100 >= {taxa_minima}) &lbrace;
                        {taxa_administrativa} = {base_calculo_dispendios} * {percentual_naximo} / 100
                    &rbrace; SENÃO &lbrace;
                        {taxa_administrativa} = {taxa_minima}
                    &rbrace;
                    </pre>
                    FORMULA
            ],
            'base_calculo_dispendios' => [
                'valor' => $this->getBaseCalculoDispendios(),
                'formula' => '{base_calculo_dispendios} = {total_notas_clientes} - {total_dispendios_clientes}',
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
                    'entrada_cliente' => 'sim',
                    'category_type' => 'income',
                ]) .
                '">dispêndios clientes</a>'
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
                'formula' => '{total_sobras_do_mes} = {base_calculo_dispendios} + {total_sobras_distribuidas} - {total_dispendios_internos} - {base_producao}'
            ],
            'base_producao' => ['valor' => $this->getTotalBaseProducao()],
            'percentual_desconto' => [
                'valor' => $this->getPercentualDesconto(),
                'formula' => '{percentual_desconto} = {percentual_dispendios} + {percentual_librecode}'
            ],
            'percentual_librecode' => [
                'valor' => $this->percentualLibreCode(),
                'formula' => '{percentual_librecode} = {total_horas_librecode} * 100 / {total_horas_possiveis}',
            ],
            'total_horas_possiveis' => [
                'valor' => $this->getTotalHorasPossiveis(),
                'formula' => '{total_horas_possiveis} = {total_cooperados} * 8 * {dias_uteis_no_mes}'
            ],
            'total_cooperados' => ['valor' => $this->getTotalCooperados()],
            'dias_uteis_no_mes' => ['valor' => $this->dates->getDiasUteisNoMes()],
            'total_horas_librecode' => [
                'valor' => $this->getTotalHorasLibreCode(),
                'formula' => '{total_horas_librecode} = {total_segundos_librecode} / 60 / 60'
            ],
            'total_segundos_librecode' => ['valor' => $this->getTotalSegundosLibreCode()],
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

    public function exportToOds(): void
    {
        throw new Exception('Need to be fixed');
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Ods();
        $spreadsheet = $reader->load(__DIR__ . '/../../resources/assets/base.ods');
        $spreadsheet->getSheetByName('valores calculados')
            ->setCellValue('B1', $this->dates->getInicio()->format('Y-m-d H:i:s'))
            ->setCellValue('B2', $this->dates->getFim()->format('Y-m-d H:i:s'))
            ->setCellValue('B3', $this->dates->getInicioProximoMes()->format('Y-m-d H:i:s'))
            ->setCellValue('B4', $this->dates->getFimProximoMes()->format('Y-m-d H:i:s'))
            ->setCellValue('B5', $this->getTotalCooperados())
            ->setCellValue('B6', $this->getTotalPagoNotasClientes())
            ->setCellValue('B7', $this->getTotalDispendiosClientes())
            ->setCellValue('B8', $this->getTotalSegundosLibreCode() / 60 / 60)
            ->setCellValue('B9', $this->percentualLibreCode())
            ->setCellValue('B10', $this->percentualDispendios())
            ->setCellValue('B11', $this->getPercentualDesconto());

        $spreadsheet->createSheet()
        ->fromArray(['Código cliente', 'Custo', 'Fornecedor'])
        ->setTitle('Custo por cliente')
            ->fromArray($this->getCustosPorCliente(), null, 'A2');

        $spreadsheet->createSheet()
            ->setTitle('Trabalhado por cliente')
            ->fromArray(['Cooperado', 'Cliente', 'Percentual trabalhado', 'cliente codigo', 'customer id'])
            ->fromArray($this->getPercentualTrabalhadoPorCliente(), null, 'A2');

        $producao = $spreadsheet->getSheetByName('mês')
            ->setTitle($this->dates->getInicio()->format('Y-m'));
        $cooperados = $this->getProducaoCooperativista();
        $row = 4;
        foreach ($cooperados as $cooperado) {
            $producao->setCellValue('A' . $row, $cooperado->getName());
            $producao->setCellValue('N' . $row, $cooperado->getName());
            $producao->setCellValue('O' . $row, 'Produção cooperativista');
            $producao->setCellValue('P' . $row, $cooperado->getBaseProducao());
            $producao->setCellValue('Q' . $row, 1);
            $row++;
        }
        if ($row < 35) {
            for ($i = $row;$i <= 35;$i++) {
                $producao->setCellValue('A' . $i, '');
                $producao->setCellValue('N' . $i, '');
                $producao->setCellValue('O' . $i, '');
                $producao->setCellValue('P' . $i, '');
                $producao->setCellValue('Q' . $i, '');
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
        $writer->save($this->dates->getInicio()->format('Y-m-d') . '.ods');
    }
}
