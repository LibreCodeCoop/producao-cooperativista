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

use Carbon\Carbon;
use Cmixin\BusinessDay;
use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use NumberFormatter;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Service\Source\Customers;
use ProducaoCooperativista\Service\Source\Invoices;
use ProducaoCooperativista\Service\Source\Nfse;
use ProducaoCooperativista\Service\Source\Projects;
use ProducaoCooperativista\Service\Source\Timesheets;
use ProducaoCooperativista\Service\Source\Transactions;
use ProducaoCooperativista\Service\Source\Users;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;

class ProducaoCooperativista
{
    private array $custosPorCliente = [];
    private array $valoresPorProjeto = [];
    private array $percentualTrabalhadoPorCliente = [];
    /** @var CooperadoProducao[] */
    private array $cooperado = [];
    private int $totalCooperados = 0;
    private float $totalNotas = 0;
    private float $totalCustoCliente = 0;
    private float $totalDispendios = 0;
    private int $totalSegundosLibreCode = 0;
    private float $baseCalculoDispendios = 0;
    private int $percentualMaximo = 0;
    private float $percentualDispendios = 0;
    private int $diasUteis = 0;
    private bool $sobrasDistribuidas = false;
    private bool $previsao = false;
    private ?DateTime $inicio = null;
    private DateTime $fim;
    private DateTime $inicioProximoMes;
    private DateTime $fimProximoMes;
    private int $pagamentoNoDiaUtil = 5;
    private DateTime $dataPagamento;
    private DateTime $dataProcessamento;
    private NumberFormatter $numberFormatter;
    private array $itemsIds;

    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private Customers $customers,
        private Nfse $nfse,
        private Projects $projects,
        private Invoices $invoices,
        private Timesheets $timesheets,
        private Transactions $transactions,
        private Users $users
    )
    {
        BusinessDay::enable('Carbon\Carbon', $_ENV['HOLYDAYS_LIST'] ?? 'br-national');
        $this->numberFormatter = new NumberFormatter(
            $_ENV['LOCALE'] ?? 'pt_BR',
            NumberFormatter:: CURRENCY
        );
        $this->itemsIds = json_decode($_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS'], true);
    }

    private function getBaseCalculoDispendios(): float
    {
        if ($this->baseCalculoDispendios) {
            return $this->baseCalculoDispendios;
        }
        $this->baseCalculoDispendios = $this->getTotalNotas() - $this->getTotalCustoCliente();
        return $this->baseCalculoDispendios;
    }

    public function setInicio(DateTime $inicio): void
    {
        if ($this->inicio) {
            return;
        }
        $this->inicio = $inicio
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $fim = clone $inicio;
        $this->fim = $fim->modify('last day of this month')
            ->setTime(23, 59, 59);

        $this->inicioProximoMes = (clone $inicio)->modify('first day of next month');
        $this->fimProximoMes = (clone $fim)->modify('last day of next month');
    }

    public function setDiaUtilPagamento(int $dia): void
    {
        $this->pagamentoNoDiaUtil = $dia;
    }

    private function getDataPagamento(): DateTime
    {
        try {
            return $this->dataPagamento;
        } catch (\Throwable $th) {
            $inicoMes = (clone $this->inicioProximoMes)->modify('first day of next month');
            $carbon = Carbon::parse($inicoMes);
            $dataPagamento = $carbon->addBusinessDays($this->pagamentoNoDiaUtil);
            $string = $dataPagamento->format('Y-m-d');
            $this->dataProcessamento = new DateTime();
            if ($string >= $this->dataProcessamento->format('Y-m-d')) {
                $this->dataPagamento = new DateTime($string);
            } else {
                $this->dataPagamento = $this->dataProcessamento;
            }
        }
        return $this->dataPagamento;
    }

    private function getDataProcessamento(): DateTime
    {
        $this->getDataPagamento();
        return $this->dataProcessamento;
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
        $totalPossivelDeHoras = $this->getTotalCooperados() * 8 * $this->getDiasUteisNoMes();

        $totalHorasLibreCode = $this->getTotalSegundosLibreCode() / 60 / 60;
        $percentualLibreCode = $totalHorasLibreCode * 100 / $totalPossivelDeHoras;

        return $percentualLibreCode;
    }

    public function loadFromExternalSources(DateTime $inicio): void
    {
        $this->setInicio($inicio);
        $this->logger->debug('Baixando dados externos');
        $this->customers->updateDatabase();
        $this->nfse->updateDatabase($this->inicioProximoMes);
        $this->projects->updateDatabase();
        $this->invoices->updateDatabase($this->inicioProximoMes, 'invoice');
        $this->invoices->updateDatabase($this->inicioProximoMes, 'bill');
        $this->timesheets->updateDatabase($this->inicio);
        $this->transactions->updateDatabase($this->inicioProximoMes);
        $this->users->updateDatabase();
    }

    private function getTotalSegundosLibreCode(): int
    {
        if ($this->totalSegundosLibreCode) {
            return $this->totalSegundosLibreCode;
        }
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Total horas LibreCode
            SELECT sum(t.duration) as total_segundos_librecode
                FROM timesheet t
                JOIN projects p ON t.project_id = p.id
                JOIN customers c ON p.customer_id = c.id
                JOIN users u ON u.id = t.user_id
            WHERE t.`begin` >= :inicio
                AND t.`end` <= :fim
                AND c.name = 'LibreCode'
                AND u.enabled = 1
            GROUP BY c.name
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $this->inicio->format('Y-m-d'),
            'fim' => $this->fim->format('Y-m-d H:i:s'),
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
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Total pessoas que registraram horas no mês
            SELECT count(distinct t.user_id) as total_cooperados
                FROM timesheet t
                JOIN users u ON u.id = t.user_id 
            WHERE t.`begin` >= :inicio
                AND t.`end` <= :fim
                AND u.enabled = 1
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $this->inicio->format('Y-m-d'),
            'fim' => $this->fim->format('Y-m-d'),
        ]);
        $result = $result->fetchOne();

        if (!$result) {
            $messagem = sprintf(
                'Sem registro de horas no Kimai entre os dias %s e %s.',
                $this->inicio->format(('Y-m-d')),
                $this->fim->format(('Y-m-d'))
            );
            throw new Exception($messagem);
        }
        $this->totalCooperados = (int) $result;
        $this->logger->debug('Total pessoas no mês: {total}', ['total' => $this->totalCooperados]);
        return $this->totalCooperados;
    }

    /**
     * Dispêndios da LibreCode
     * 
     * Desconsidera-se:
     * * Produção cooperativista (cliente interno)
     * * Produção externa (pagamento para quem trabalha diretamente para cliente externo)
     * * Impostos de pessoa física: IRPF, INSS
     * * Cliente: Todos os custos dos clientes
     * * Serviços de clientes: É algo que o cliente pagou, isto nem expense deveria ser
     * * Plano de saúde: Este valor é reembolsado pelo cooperado então não entra para ser dividido por todos
     */
    private function getTotalDispendios(): float
    {
        if ($this->totalDispendios) {
            return $this->totalDispendios;
        }
        if ($this->previsao) {
            $stmt = $this->db->getConnection()->prepare(<<<SQL
                -- Total dispêndios
                SELECT sum(amount) AS total_dispendios
                    FROM invoices i
                WHERE i.type = 'bill'
                    AND transaction_of_month = :ano_mes
                    AND category_type = 'expense'
                    AND category_name NOT IN (
                        'Produção cooperativista',
                        'Produção externa',
                        'Imposto Pessoa Física',
                        'Cliente',
                        'Serviços de clientes',
                        'Plano de saúde'
                    )
                SQL
            );
        } else {
            $stmt = $this->db->getConnection()->prepare(<<<SQL
                -- Total dispêndios
                SELECT sum(amount) AS total_dispendios
                    FROM transactions t
                WHERE transaction_of_month = :ano_mes
                    AND category_type = 'expense'
                    AND category_name NOT IN (
                        'Produção cooperativista',
                        'Produção externa',
                        'Imposto Pessoa Física',
                        'Cliente',
                        'Serviços de clientes',
                        'Plano de saúde'
                    )
                SQL
            );
        }
        $result = $stmt->executeQuery([
            'ano_mes' => $this->inicioProximoMes->format('Y-m'),
        ]);
        $this->totalDispendios = (float) $result->fetchOne();
        $this->logger->debug('Total dispêndios: {total}', ['total' => $this->totalDispendios]);
        return $this->totalDispendios;
    }

    /**
     * Retorna valor total de notas em um mês
     *
     * @throws Exception
     */
    private function getTotalNotas(): float
    {
        if ($this->totalNotas) {
            return $this->totalNotas;
        }

        $stmt = $this->db->getConnection()->prepare(<<<SQL
            SELECT SUM(amount) AS notas
            FROM invoices i
            WHERE type = 'invoice'
            AND category_name IN (
                'Cliente',
                'Serviços de clientes',
                'Recorrência',
                'Serviço'
            )
            AND transaction_of_month = :ano_mes
            SQL
        );
        $result = $stmt->executeQuery([
            'ano_mes' => $this->inicioProximoMes->format('Y-m'),
        ]);
        $this->totalNotas = (float) $result->fetchOne();
        if (!$this->totalNotas) {
            $messagem = sprintf(
                'Sem notas entre os dias %s e %s.',
                $this->inicioProximoMes->format(('Y-m-d')),
                $this->fimProximoMes->format(('Y-m-d'))
            );
            throw new Exception($messagem);
        }
        $this->logger->debug('Total notas: {total}', ['total' => $this->totalNotas]);
        return $this->totalNotas;
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
        $rows = $this->getCustosPorCliente();
        $this->totalCustoCliente = array_reduce($rows, function($total, $row): float {
            $total += $row['total_custos'];
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
        if ($this->previsao) {
            $stmt = $this->db->getConnection()->prepare(<<<SQL
                -- Custos clientes
                SELECT customer_reference as cliente_codigo,
                    SUM(amount) AS total_custos,
                    i.type,
                    'invoices' as 'table',
                    contact_name
                FROM invoices i
                WHERE i.type = 'bill'
                AND transaction_of_month = :ano_mes
                AND category_type = 'expense'
                AND category_name IN (
                    'Cliente',
                    'Serviços de clientes'
                )
                GROUP BY customer_reference, i.type, contact_name
                SQL
            );
        } else {
            $stmt = $this->db->getConnection()->prepare(<<<SQL
                -- Custos clientes
                SELECT customer_reference as cliente_codigo,
                    SUM(amount) AS total_custos,
                    t.type,
                    'transactions' as 'table',
                    contact_name
                FROM transactions t
                WHERE transaction_of_month = :ano_mes
                AND category_type = 'expense'
                AND category_name IN (
                    'Cliente',
                    'Serviços de clientes'
                )
                GROUP BY customer_reference, t.type, contact_name
                SQL
            );
        }
        $result = $stmt->executeQuery([
            'ano_mes' => $this->inicioProximoMes->format('Y-m'),
        ]);
        $this->custosPorCliente = [];
        $errors = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['cliente_codigo']) || !preg_match('/^\d+(\|\S+)?$/', $row['cliente_codigo'])) {
                $errors[] = $row;
            }
            $this->custosPorCliente[] = $row;
        }
        if (count($errors)) {
            throw new Exception(sprintf(
                "Código de cliente inválido no Akaunting para calcular custos por cliente.\n" .
                "Intervalo: %s a %s\n" .
                "Dados:\n%s",
                $this->inicioProximoMes->format('Y-m-d'),
                $this->fimProximoMes->format('Y-m-d'),
                json_encode($errors, JSON_PRETTY_PRINT)
            ));
        }
        $this->logger->debug('Custos por clientes: {json}', ['json' => json_encode($this->custosPorCliente)]);
        return $this->custosPorCliente;
    }

    private function getPercentualDesconto(): float
    {
        $percentualDesconto = $this->percentualDispendios() + $this->percentualLibreCode();
        return $percentualDesconto;
    }

    private function getValoresPorProjeto(): array
    {
        if ($this->valoresPorProjeto) {
            return $this->valoresPorProjeto;
        }

        $percentualDesconto = $this->getPercentualDesconto();
        $custosPorCliente = $this->getCustosPorCliente();
        $custosPorCliente = array_column($custosPorCliente, 'total_custos', 'cliente_codigo');

        if ($this->previsao) {
            $stmt = $this->db->getConnection()->prepare(<<<SQL
                SELECT i.id,
                    i.amount,
                    i.type,
                    'invoices' as 'table',
                    i.category_type,
                    i.category_name,
                    i.customer_reference,
                    i.nfse,
                    i.issued_at,
                    transaction_of_month
                FROM invoices i
                WHERE transaction_of_month = :ano_mes
                AND i.type = 'invoice'
                AND category_name IN (
                    'Cliente',
                    'Serviços de clientes',
                    'Recorrência',
                    'Serviço'
                )
                SQL
            );
        } else {
            $stmt = $this->db->getConnection()->prepare(<<<SQL
                SELECT ti.id,
                    ti.amount,
                    ti.type,
                    'transactions' as 'table',
                    ti.category_type,
                    ti.category_name,
                    ti.customer_reference,
                    ti.nfse,
                    ti.paid_at,
                    transaction_of_month
                FROM transactions ti
                WHERE transaction_of_month = :ano_mes
                AND ti.category_type = 'income'
                AND category_name IN (
                    'Cliente',
                    'Serviços de clientes',
                    'Recorrência',
                    'Serviço'
                )
                SQL
            );
        }
        $result = $stmt->executeQuery([
            'ano_mes' => $this->inicioProximoMes->format('Y-m'),
        ]);
        $errorsSemContactReference = [];
        $this->valoresPorProjeto = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['customer_reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['customer_reference'])) {
                $errorsSemContactReference[] = $row;
            }

            $valoresPorProjeto = [];
            $base = $row['amount'] - ($custosPorCliente[$row['customer_reference']] ?? 0);
            $valoresPorProjeto = [
                'base_producao' => $base - ($base * $percentualDesconto / 100),
                'customer_reference' => $row['customer_reference'],
            ];
            $this->valoresPorProjeto[] = $valoresPorProjeto;
        }
        if (count($errorsSemContactReference)) {
            throw new Exception(
                "Cliente da transação não possui referência de contato válida no Akaunting.\n" .
                "Dados: \n" .
                json_encode($errorsSemContactReference, JSON_PRETTY_PRINT)
            );
        }

        $this->logger->debug('Valores por projetos: {valores}', ['valores' => json_encode($this->valoresPorProjeto)]);
        return $this->valoresPorProjeto;
    }

    private function clientesContabilizaveis(): array
    {
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
        $clientes = $this->getValoresPorProjeto();
        $clientesContabilizaveis = array_column($clientes, 'customer_reference');
        $clientesContabilizaveis = array_merge(
            array_values($clientesContabilizaveis),
            $cnpjClientesInternos
        );
        return $clientesContabilizaveis;
    }

    private function getPercentualTrabalhadoPorCliente(): array
    {
        if (count($this->percentualTrabalhadoPorCliente)) {
            return $this->percentualTrabalhadoPorCliente;
        }
        $contabilizaveis = $this->clientesContabilizaveis();
        $cnpjClientesInternos = "'" . implode("','", $contabilizaveis) . "'";
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Percentual trabalhado por cliente
            SELECT u.alias,
                u.tax_number,
                u.dependents,
                u.akaunting_contact_id,
                u.health_insurance,
                c.id as customer_id,
                c.name,
                c.vat_id as cliente_codigo,
                COALESCE(sum(t.duration), 0) * 100 / total_cliente.total AS percentual_trabalhado
            FROM customers c
            JOIN projects p ON p.customer_id = c.id
            JOIN timesheet t ON t.project_id = p.id
            JOIN users u ON u.id = t.user_id
            JOIN (
                -- Total minutos faturados por cliente
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
                WHERE u2.enabled = 1
                AND c.vat_id IN ($cnpjClientesInternos)
                GROUP BY c.id,
                        c.name,
                        c.vat_id
                ) total_cliente ON total_cliente.customer_id = c.id
            WHERE t.`begin` >= :data_inicio
            AND t.`end` <= :data_fim
            AND u.enabled = 1
            GROUP BY u.alias,
                    u.tax_number,
                    u.dependents,
                    u.akaunting_contact_id,
                    u.health_insurance,
                    c.id,
                    c.name,
                    c.vat_id
            ORDER BY c.id,
                    u.alias
            SQL
        );
        $stmt->bindValue('data_inicio', $this->inicio->format('Y-m-d'));
        $stmt->bindValue('data_fim', $this->fim->format('Y-m-d H:i:s'));
        $stmt->bindValue('ano_mes', $this->inicioProximoMes->format('Y-m'));
        $result = $stmt->executeQuery();
        $this->percentualTrabalhadoPorCliente = [];
        while ($row = $result->fetchAssociative()) {
            if (!$row['cliente_codigo']) {
                continue;
            }
            $this->getCooperado($row['tax_number'])
                ->setName($row['alias'])
                ->setTaxNumber($row['tax_number'])
                ->setAkauntingContactId($row['akaunting_contact_id'])
                ->setBaseProducao(0)
                ->setDependentes($row['dependents'])
                ->setHealthInsurance($row['health_insurance']);
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

    private function cadastraCooperadoNoAkaunting(string $name, string $taxNumber): int {
        $connection = $this->db->getConnection(Database::DB_AKAUNTING);
        $insert = new QueryBuilder($connection);
        $insert->insert('contacts')
            ->values([
                'company_id' => $insert->createNamedParameter($_ENV['AKAUNTING_COMPANY_ID'], ParameterType::INTEGER),
                'type' => $insert->createNamedParameter('vendor'),
                'name' => $insert->createNamedParameter($name),
                'tax_number' => $insert->createNamedParameter($taxNumber),
                'country' => 'BR',
                'currency_code' => 'BRL',
                'enabled' => 1,
                'created_at' => $insert->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')),
                'updated_at' => $insert->createNamedParameter((new DateTime())->format('Y-m-d H:i:s'))
            ]);
        $insert->executeStatement();
        $id = $connection->lastInsertId();
        return (int) $id;
    }

    public function setDiasUteis(int $diasUteis): void
    {
        $this->diasUteis = $diasUteis;
    }

    private function getDiasUteisNoMes(): int
    {
        if ($this->diasUteis === 0) {
            $date = Carbon::getMonthBusinessDays($this->inicio);
            $this->diasUteis = count($date);
        }
        return $this->diasUteis;
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
        $this->coletaDadosDaProducaoDoMes();
        $producao = $this->getProducaoCooprativista();
        $haveNewProduction = false;
        foreach ($producao as $cooperado) {
            $invoice = new AkautingInvoieProducao();

            $invoice->setType('bill')
                ->setCategoryId((int) $_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'])
                ->setDocumentNumber(
                    'PDC_' .
                    $cooperado->getTaxNumber() .
                    '-' .
                    $this->getDataPagamento()->format('Y-m')
                )
                ->setSearch('type:bill')
                ->setStatus('draft')
                ->setIssuedAt($this->getDataProcessamento()->format('Y-m-d H:i:s'))
                ->setDueAt($this->getDataPagamento()->format('Y-m-d H:i:s'))
                ->setAccountId(1)
                ->setCurrencyCode('BRL')
                ->setNote('Data geração', $this->getDataProcessamento()->format('Y-m-d'))
                ->setNote('Produção realizada no mês', $this->inicio->format('Y-m'))
                ->setNote('Notas dos clientes pagas no mês', $this->inicioProximoMes->format('Y-m'))
                ->setNote('Previsão de pagamento', sprintf('%sº dia útil', $this->diasUteis))
                ->setNote('Previsão de pagamento no dia', $this->getDataPagamento()->format('Y-m-d'))
                ->setNote('Base de cálculo', $this->numberFormatter->format($cooperado->getBaseProducao()))
                ->setNote('FRRA', $this->numberFormatter->format($cooperado->getFrra()))
                ->setContactId($cooperado->getAkauntingContactId())
                ->setContactName($cooperado->getName())
                ->setContactTaxNumber($cooperado->getTaxNumber());
            $this->insereHealthInsurance($invoice);
            $this->aplicaAdiantamentos($invoice);
            $invoice->setItem(
                itemId: $this->itemsIds['Auxílio'],
                name: 'Ajuda de custo',
                price: $cooperado->getAuxilio()
            );
            $invoice->setItem(
                itemId: $this->itemsIds['bruto'],
                name: 'Bruto produção',
                price: $cooperado->getBruto()
            );
            $invoice->setItem(
                itemId: $this->itemsIds['INSS'],
                name: 'INSS',
                price: $cooperado->getInss() * -1
            );
            $invoice->setItem(
                itemId: $this->itemsIds['IRPF'],
                name: 'IRPF',
                price: $cooperado->getIrpf() * -1
            );
            try {
                if (empty($cooperado->getBillId())) {
                    $this->invoices->sendData(
                        endpoint: '/api/documents',
                        body: $invoice->toArray()
                    );
                    $haveNewProduction = true;
                } else {
                    try {
                        $bill = $this->invoices->sendData(
                            endpoint: '/api/documents/' . $cooperado->getBillId(),
                            query: [
                                'search' => implode(' ', [
                                    'type:bill',
                                    'status:draft',
                                ]),
                            ],
                            method: 'GET'
                        );
                    } catch (\Throwable $th) {
                        // status != draft
                        continue;
                    }
                    $this->invoices->sendData(
                        endpoint: '/api/documents/' . $cooperado->getBillId(),
                        body: $invoice->toArray(),
                        method: 'PATCH'
                    );
                }
            } catch (ClientException $e) {
                $response = $e->getResponse();
                $content = $response->toArray(false);
                throw new Exception(json_encode($content));
            }
        }
        if ($haveNewProduction) {
            $begin = (clone $this->getDataProcessamento())
                ->modify('first day of this month')
                ->setTime(00, 00, 00);
            $this->invoices->updateDatabase($begin, 'bill');
        }
    }

    private function insereHealthInsurance(AkautingInvoieProducao $invoice): void
    {
        $taxNumber = $invoice->getContactTaxNumber();

        $cooperado = $this->getCooperado($taxNumber);

        if ($cooperado->getHealthInsurance()) {
            $invoice->setItem(
                itemId: $this->itemsIds['Plano'],
                name: 'Plano de saúde',
                price: -$cooperado->getHealthInsurance(),
                order: 10
            );
        }
    }

    private function aplicaAdiantamentos(AkautingInvoieProducao $invoice): void
    {
        $taxNumber = $invoice->getContactTaxNumber();

        $select = new QueryBuilder($this->db->getConnection());
        $select->select('amount')
            ->addSelect('document_number')
            ->addSelect('due_at')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere("metadata->>'$.status' = 'paid'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_ADIANTAMENTO_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('tax_number', $select->createNamedParameter($taxNumber)))
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->inicioProximoMes->format('Y-m'))));

        $result = $select->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $invoice->setItem(
                itemId: $this->itemsIds['desconto'],
                name: 'Adiantamento',
                description: sprintf('Número: %s, data: %s', $row['document_number'], $row['due_at']),
                price: -$row['amount'],
                order: 20
            );
        }
    }

    private function coletaDadosDaProducaoDoMes(): void
    {
        $producao = $this->getProducaoCooprativista();

        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere($select->expr()->eq('category_id', ':category_id'))
            ->setParameter('category_id', (int) $_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'], ParameterType::INTEGER)
            ->andWhere($select->expr()->in('tax_number', ':tax_number'))
            ->setParameter('tax_number', array_keys($producao), ArrayParameterType::STRING)
            ->andWhere($select->expr()->eq('transaction_of_month', $select->createNamedParameter($this->getDataPagamento()->format('Y-m'))));

        $result = $select->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $this->getCooperado($row['tax_number'])
                ->setBillId($row['id'])
                ->setDocumentNumber($row['document_number']);
        }
    }

    /**
     * @return CooperadoProducao[]
     */
    public function getProducaoCooprativista(): array
    {
        if ($this->cooperado) {
            return $this->cooperado;
        }

        $this->cadastraCooperadoQueProduziuNoAkaunting();
        $this->distribuiProducaoExterna();
        $this->distribuiSobras();
        $this->logger->debug('Produção por cooperado ooperado: {json}', ['json' => json_encode($this->cooperado)]);
        return $this->cooperado;
    }

    private function distribuiSobras(): void
    {
        $percentualTrabalhadoPorCliente = $this->getPercentualTrabalhadoPorCliente();
        $sobras = $this->getTotalSobras();
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
        foreach ($percentualTrabalhadoPorCliente as $row) {
            if (!in_array($row['cliente_codigo'], $cnpjClientesInternos)) {
                continue;
            }
            $aReceberDasSobras = ($sobras * $row['percentual_trabalhado'] / 100);
            $cooperado = $this->getCooperado($row['tax_number']);
            $cooperado->setBaseProducao($cooperado->getBaseProducao() + $aReceberDasSobras);
        }
    }

    private function getCooperado(string $taxNumber): CooperadoProducao
    {
        if (!isset($this->cooperado[$taxNumber])) {
            $this->cooperado[$taxNumber] = new CooperadoProducao(
                anoFiscal: (int) $this->inicio->format('Y')
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
        $totalPorCliente = array_column($this->getValoresPorProjeto(), 'base_producao', 'customer_reference');
        $errorSemCodigoCliente = [];
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
        foreach ($percentualTrabalhadoPorCliente as $row) {
            if (in_array($row['cliente_codigo'], $cnpjClientesInternos)) {
                continue;
            }
            if (!isset($totalPorCliente[$row['cliente_codigo']])) {
                $errorSemCodigoCliente[] = $row;
                continue;
            }
            $brutoCliente = $totalPorCliente[$row['cliente_codigo']];
            $aReceber = $brutoCliente * $row['percentual_trabalhado'] / 100;
            $cooperado = $this->getCooperado($row['tax_number']);
            $cooperado->setBaseProducao($cooperado->getBaseProducao() + $aReceber);
        }
        if (count($errorSemCodigoCliente)) {
            throw new Exception(
                "O cliente_codigo trabalhado no Kimai não possui faturamento no mês " . $this->inicioProximoMes->format('Y-m-d'). ".\n" .
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
            fn($carry, $cooperado) => $carry += $cooperado->getBaseProducao(),
            0
        );
        return $baseProducao;
    }

    private function getTotalSobras(): float
    {
        $this->distribuiProducaoExterna();
        return $this->getBaseCalculoDispendios() - $this->getTotalDispendios() - $this->getTotalDistribuido();
    }

    public function exportToCsv(): string
    {
        $list = $this->getProducaoCooprativista();
        // header
        $cooperado = current($list);
        $output[] = $this->csvstr(array_keys($cooperado->toArray()));
        // body
        foreach ($list as $cooperado) {
            $output[] = $this->csvstr($cooperado->toArray());
        }
        $output = implode("\n", $output);
        return $output;
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

    public function exportToOds(): void
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Ods();
        $spreadsheet = $reader->load(__DIR__ . '/../assets/base.ods');
        $spreadsheet->getSheetByName('valores calculados')
            ->setCellValue('B1', $this->inicio->format('Y-m-d H:i:s'))
            ->setCellValue('B2', $this->fim->format('Y-m-d H:i:s'))
            ->setCellValue('B3', $this->inicioProximoMes->format('Y-m-d H:i:s'))
            ->setCellValue('B4', $this->fimProximoMes->format('Y-m-d H:i:s'))
            ->setCellValue('B5', $this->getTotalCooperados())
            ->setCellValue('B6', $this->getTotalNotas())
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
            ->fromArray($this->getValoresPorProjeto(), null, 'A2');

        $spreadsheet->createSheet()
            ->setTitle('Trabalhado por cliente')
            ->fromArray(['Cooperado', 'Cliente', 'Percentual trabalhado', 'cliente codigo', 'customer id'])
            ->fromArray($this->getPercentualTrabalhadoPorCliente(), null, 'A2');

        $producao = $spreadsheet->getSheetByName('mês')
            ->setTitle($this->inicio->format('Y-m'));
        $cooperados = $this->getProducaoCooprativista();
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
        $writer->save($this->inicio->format('Y-m-d') . '.ods');
    }
}