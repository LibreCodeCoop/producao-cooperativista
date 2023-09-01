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
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use NumberFormatter;
use ProducaoCooperativista\DB\Database;
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

class ProducaoCooperativista
{
    private array $custosPorCliente = [];
    private array $valoresPorProjeto = [];
    private array $percentualTrabalhadoPorCliente = [];
    private array $entradas = [];
    private array $saidas = [];
    private array $dispendios = [];
    /** @var Cooperado[] */
    private array $cooperado = [];
    private array $categoriesList = [];
    private int $totalCooperados = 0;
    private float $totalNotasClientes = 0;
    private float $totalCustoCliente = 0;
    private float $totalDispendios = 0;
    private int $totalSegundosLibreCode = 0;
    private float $baseCalculoDispendios = 0;
    private int $percentualMaximo = 0;
    private float $percentualDispendios = 0;
    private bool $sobrasDistribuidas = false;
    private bool $previsao = false;

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
    ) {
    }

    private function getBaseCalculoDispendios(): float
    {
        if (!empty($this->baseCalculoDispendios)) {
            return $this->baseCalculoDispendios;
        }
        $this->baseCalculoDispendios = $this->getTotalNotasClientes() - $this->getTotalCustoCliente();
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
        $taxaMinima = $this->getTotalDispendios();
        $taxaMaxima = $taxaMinima * 2;
        if ($this->getBaseCalculoDispendios() * $this->percentualMaximo / 100 >= $taxaMaxima) {
            $taxaAdministrativa = $taxaMaxima;
        } elseif ($this->getBaseCalculoDispendios() * $this->percentualMaximo / 100 >= $taxaMinima) {
            $taxaAdministrativa = $this->getBaseCalculoDispendios() * $this->percentualMaximo / 100;
        } else {
            $taxaAdministrativa = $taxaMinima;
        }

        $this->percentualDispendios = $taxaAdministrativa / ($this->getBaseCalculoDispendios()) * 100;
        return $this->percentualDispendios;
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
        $totalPossivelDeHoras = $this->getTotalCooperados() * 8 * $this->dates->getDiasUteisNoMes();

        $totalHorasLibreCode = $this->getTotalSegundosLibreCode() / 60 / 60;
        $percentualLibreCode = $totalHorasLibreCode * 100 / $totalPossivelDeHoras;

        return $percentualLibreCode;
    }

    public function loadFromExternalSources(DateTime $inicio): void
    {
        $this->dates->setInicio($inicio);
        $this->logger->debug('Baixando dados externos');
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
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
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
        $this->logger->debug('Total segundos LibreCode: {total}', ['total' => $this->totalSegundosLibreCode]);
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
        $this->logger->debug('Total pessoas no mês: {total}', ['total' => $this->totalCooperados]);
        return $this->totalCooperados;
    }

    /**
     * Dispêndios internos
     *
     * São todos os dispêndios da cooperativa tirando dispêndios do cliente e do cooperado.
     */
    private function getTotalDispendios(): float
    {
        if ($this->totalDispendios) {
            return $this->totalDispendios;
        }
        $dispendiosInternos = $this->getChildrensCategories((int) $_ENV['AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID']);
        $this->dispendios = array_filter($this->saidas, function ($i) use ($dispendiosInternos): bool {
            if ($i['transaction_of_month'] === $this->dates->getInicioProximoMes()->format('Y-m')) {
                if ($i['archive'] === 0) {
                    if (in_array($i['category_id'], $dispendiosInternos)) {
                        return true;
                    }
                }
            }
            return false;
        });
        $this->totalDispendios = array_reduce($this->dispendios, fn ($total, $i) => $total += $i['amount'], 0);
        $this->logger->debug('Total dispêndios: {total}', ['total' => $this->totalDispendios]);
        return $this->totalDispendios;
    }

    private function getChildrensCategories(int $id): array
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

    private function getCategories(): array
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
        return $this->categoriesList;
    }

    private function getSaidas(): array
    {
        if ($this->saidas) {
            return $this->saidas;
        }
        if ($this->previsao) {
            $stmt = $this->db->getConnection()->prepare(
                <<<SQL
                -- Saídas
                SELECT *
                    FROM invoices i
                WHERE i.type = 'bill'
                    AND transaction_of_month = :ano_mes
                    AND archive = 0
                    AND category_type = 'expense'
                SQL
            );
        } else {
            $stmt = $this->db->getConnection()->prepare(
                <<<SQL
                -- Saídas
                SELECT *
                    FROM transactions t
                WHERE transaction_of_month = :ano_mes
                    AND archive = 0
                    AND category_type = 'expense'
                SQL
            );
        }
        $result = $stmt->executeQuery([
            'ano_mes' => $this->dates->getInicioProximoMes()->format('Y-m'),
        ]);
        $errors = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['customer_reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['customer_reference'])) {
                $errors[] = $row;
            }
            $this->saidas[] = $row;
        }

        if (count($errors)) {
            throw new Exception(sprintf(
                "Código de cliente inválido na transação de saída no Akaunting.\n" .
                "Intervalo: %s a %s\n" .
                "Dados:\n%s",
                $this->dates->getInicioProximoMes()->format('Y-m-d'),
                $this->dates->getFimProximoMes()->format('Y-m-d'),
                json_encode($errors, JSON_PRETTY_PRINT)
            ));
        }

        $this->logger->debug('Saídas', [$this->saidas]);
        return $this->saidas;
    }

    /**
     * Retorna valor total de notas em um mês
     *
     * @throws Exception
     */
    private function getTotalNotasClientes(): float
    {
        if ($this->totalNotasClientes) {
            return $this->totalNotasClientes;
        }

        $this->totalNotasClientes = array_reduce($this->getEntradasClientes(), fn ($total, $i) => $total + $i['amount'], 0);
        return $this->totalNotasClientes;
    }

    private function getEntradasClientes(): array
    {
        $this->atualizaEntradas();
        $categoriasEntradasClientes = $this->getChildrensCategories((int) $_ENV['AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID']);
        $entradasClientes = array_filter($this->entradas, fn ($i) => in_array($i['category_id'], $categoriasEntradasClientes));
        return $entradasClientes;
    }

    /**
     * Total de custos por cliente
     *
     * @throws Exception
     * @return float
     */
    private function getTotalCustoCliente(): float
    {
        if ($this->totalCustoCliente) {
            return $this->totalCustoCliente;
        }
        $custosPorCliente = $this->getCustosPorCliente();
        $this->totalCustoCliente = array_reduce($custosPorCliente, function ($total, $row): float {
            $total += $row['amount'];
            return $total;
        }, 0);
        $this->logger->debug('Total custos clientes: {total}', ['total' => $this->totalCustoCliente]);
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
        $categoriasCustosClientes = $this->getChildrensCategories((int) $_ENV['AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID']);
        $this->custosPorCliente = array_filter($this->saidas, fn ($i) => in_array($i['category_id'], $categoriasCustosClientes));
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
        $this->atualizaEntradas();

        if (count($this->entradas)) {
            $current = current($this->entradas);
            if (!empty($current['base_producao'])) {
                return $this;
            }
        }

        $percentualDesconto = $this->getPercentualDesconto();
        $custosPorCliente = $this->getCustosPorCliente();
        $custosPorCliente = array_column($custosPorCliente, 'total_custos', 'customer_reference');

        foreach ($this->entradas as $key => $row) {
            $base = $row['amount'] - ($custosPorCliente[$row['customer_reference']] ?? 0);
            $this->entradas[$key]['base_producao'] = $base - ($base * $percentualDesconto / 100);
        }

        $this->logger->debug('Entradas no mês com base de produção', [json_encode($this->entradas)]);
        return $this;
    }

    private function atualizaEntradas(): void
    {
        if ($this->entradas) {
            return;
        }

        if ($this->previsao) {
            $stmt = $this->db->getConnection()->prepare(
                <<<SQL
                -- Entradas
                SELECT 'invoices' as 'table',
                    i.*
                FROM invoices i
                WHERE transaction_of_month = :ano_mes
                AND i.type = 'invoice'
                AND archive = 0
                SQL
            );
        } else {
            $stmt = $this->db->getConnection()->prepare(
                <<<SQL
                -- Entradas
                SELECT 'transactions' as 'table',
                    t.*
                FROM transactions t
                WHERE transaction_of_month = :ano_mes
                AND t.category_type = 'income'
                AND archive = 0
                SQL
            );
        }
        $result = $stmt->executeQuery([
            'ano_mes' => $this->dates->getInicioProximoMes()->format('Y-m'),
        ]);
        $errorsSemContactReference = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['customer_reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['customer_reference'])) {
                $errorsSemContactReference[] = $row;
            }

            $this->entradas[] = $row;
        }
        if (count($errorsSemContactReference)) {
            throw new Exception(
                "Cliente da transação não possui referência de contato válida no Akaunting.\n" .
                "Dados: \n" .
                json_encode($errorsSemContactReference, JSON_PRETTY_PRINT)
            );
        }

        $this->logger->debug('Entradas no mês', [json_encode($this->entradas)]);
        return;
    }

    private function clientesContabilizaveis(): array
    {
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
        $clientesContabilizaveis = array_column($this->getEntradasClientes(), 'customer_reference');
        $clientesContabilizaveis = array_merge(
            array_values($clientesContabilizaveis),
            $cnpjClientesInternos
        );
        $clientesContabilizaveis = array_unique($clientesContabilizaveis);
        return $clientesContabilizaveis;
    }

    private function getPercentualTrabalhadoPorCliente(): array
    {
        if (count($this->percentualTrabalhadoPorCliente)) {
            return $this->percentualTrabalhadoPorCliente;
        }
        $contabilizaveis = $this->clientesContabilizaveis();
        $cnpjContabilizaveis = "'" . implode("','", $contabilizaveis) . "'";
        $stmt = $this->db->getConnection()->prepare(
            <<<SQL
            -- Percentual trabalhado por cliente
            SELECT u.alias,
                u.tax_number,
                u.dependents,
                u.akaunting_contact_id,
                c.id as customer_id,
                c.name,
                c.vat_id as customer_reference,
                COALESCE(sum(t.duration), 0) * 100 / total_cliente.total AS percentual_trabalhado
            FROM customers c
            JOIN projects p ON p.customer_id = c.id
            JOIN timesheet t ON t.project_id = p.id
            JOIN users u ON u.id = t.user_id
            JOIN (
                -- Total minutos a faturar por cliente
                SELECT c.id as customer_id,
                    c.name,
                    c.vat_id,
                    CASE WHEN sum(t.duration) > c.time_budget THEN sum(t.duration)
                            ELSE c.time_budget
                            END as total
                FROM customers c
                JOIN projects p ON p.customer_id = c.id
                JOIN timesheet t ON t.project_id = p.id AND t.`begin` >= :data_inicio AND t.`end` <= :data_fim
                JOIN users u2 ON u2.id = t.user_id
                WHERE c.vat_id IN ($cnpjContabilizaveis)
                GROUP BY c.id,
                        c.name,
                        c.vat_id
                ) total_cliente ON total_cliente.customer_id = c.id
            WHERE t.`begin` >= :data_inicio
            AND t.`end` <= :data_fim
            GROUP BY u.alias,
                    u.tax_number,
                    u.dependents,
                    u.akaunting_contact_id,
                    c.id,
                    c.name,
                    c.vat_id
            ORDER BY c.id,
                    u.alias
            SQL
        );
        $stmt->bindValue('data_inicio', $this->dates->getInicio()->format('Y-m-d'));
        $stmt->bindValue('data_fim', $this->dates->getFim()->format('Y-m-d H:i:s'));
        $result = $stmt->executeQuery();
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
                'company_id' => $insert->createNamedParameter($_ENV['AKAUNTING_COMPANY_ID'], ParameterType::INTEGER),
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

    public function setPrevisao(bool $previsao): void
    {
        $this->previsao = $previsao;
    }

    public function updateProducao(): void
    {
        $producao = $this->getProducaoCooperativista();
        foreach ($producao as $cooperado) {
            $cooperado
                ->getProducaoCooperativista()
                ->save();

            $valueFrra = $cooperado->getProducaoCooperativista()
                ->getValues()
                ->getFrra();

            $frra = $cooperado->getFrra();
            $frra->getValues()->setBaseProducao($valueFrra);
            $frra->save();
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
        $cofins->setTotalNotasClientes($this->getTotalNotasClientes());
        $cofins->saveMonthTaxes();

        $pis = new Pis(
            db: $this->db,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $pis->setTotalNotasClientes($this->getTotalNotasClientes());
        $pis->saveMonthTaxes();

        $iss = new Iss(
            db: $this->db,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
        );
        $iss->setTotalNotasClientes($this->getTotalNotasClientes());
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

        $this->atualizaEntradas();
        $this->getSaidas();
        $this->getCustosPorCliente();
        $this->getTotalDispendios();
        $this->calculaBaseProducaoPorEntrada();
        $this->cadastraCooperadoQueProduziuNoAkaunting();
        $this->distribuiProducaoExterna();
        $this->distribuiSobras();
        $this->atualizaPlanoDeSaude();
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

    private function distribuiSobras(): void
    {
        $percentualTrabalhadoPorCliente = $this->getPercentualTrabalhadoPorCliente();
        $sobras = $this->getTotalSobrasDoMes();
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
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
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
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

    private function getTotalDistribuido(): float
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
            - $this->getTotalDispendios()
            - $this->getTotalDistribuido();
    }

    private function getTotalSobrasDistribuidasNoMes(): float
    {
        $qb = new QueryBuilder($this->db->getConnection());
        $qb->select('SUM(i.amount) AS total')
            ->from('invoices', 'i')
            ->where($qb->expr()->eq('transaction_of_month', $qb->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))))
            ->andWhere($qb->expr()->eq('i.type', $qb->createNamedParameter('invoice')))
            ->andWhere($qb->expr()->eq('i.archive', $qb->createNamedParameter(0), ParameterType::INTEGER))
            ->andWhere($qb->expr()->eq('i.category_id', $qb->createNamedParameter($_ENV['AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID']), ParameterType::INTEGER));
        $result = $qb->executeQuery();
        $total = $result->fetchOne();
        return $total;
    }

    public function exportToCsv(): string
    {
        $list = $this->getProducaoCooperativista();
        // header
        $cooperado = current($list);
        $output[] = $this->csvstr(array_keys($cooperado->getProducaoCooperativista()->getValues()->toArray()));
        // body
        foreach ($list as $cooperado) {
            $output[] = $this->csvstr($cooperado->getProducaoCooperativista()->getValues()->toArray());
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
        $spreadsheet = $reader->load(__DIR__ . '/../assets/base.ods');
        $spreadsheet->getSheetByName('valores calculados')
            ->setCellValue('B1', $this->dates->getInicio()->format('Y-m-d H:i:s'))
            ->setCellValue('B2', $this->dates->getFim()->format('Y-m-d H:i:s'))
            ->setCellValue('B3', $this->dates->getInicioProximoMes()->format('Y-m-d H:i:s'))
            ->setCellValue('B4', $this->dates->getFimProximoMes()->format('Y-m-d H:i:s'))
            ->setCellValue('B5', $this->getTotalCooperados())
            ->setCellValue('B6', $this->getTotalNotasClientes())
            ->setCellValue('B7', $this->getTotalCustoCliente())
            ->setCellValue('B8', $this->getTotalSegundosLibreCode() / 60 / 60)
            ->setCellValue('B9', $this->percentualLibreCode())
            ->setCellValue('B10', $this->percentualDispendios())
            ->setCellValue('B11', $this->getPercentualDesconto());

        $spreadsheet->createSheet()
        ->fromArray(['Código cliente', 'Custo', 'Fornecedor'])
        ->setTitle('Custo por cliente')
            ->fromArray($this->getCustosPorCliente(), null, 'A2');

        $spreadsheet->createSheet()
            ->setTitle('Valores por projeto')
            ->fromArray(['Cliente', 'referência', 'valor do serviço', 'impostos', 'total dos custos', 'base producao'])
            ->fromArray([]/*$this->getValoresPorProjeto()*/, null, 'A2');

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
            for ($i = $row;$i<=35;$i++) {
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
