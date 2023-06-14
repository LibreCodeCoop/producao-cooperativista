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
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Service\Source\Customers;
use ProducaoCooperativista\Service\Source\Invoices;
use ProducaoCooperativista\Service\Source\Nfse;
use ProducaoCooperativista\Service\Source\Projects;
use ProducaoCooperativista\Service\Source\Timesheets;
use ProducaoCooperativista\Service\Source\Transactions;
use ProducaoCooperativista\Service\Source\Users;
use Psr\Log\LoggerInterface;

class BaseCalculo
{
    private array $custosPorCliente = [];
    private array $valoresPorProjeto = [];
    private array $percentualTrabalhadoPorCliente = [];
    private array $brutoPorCooperado = [];
    private int $totalCooperados = 0;
    private float $totalNotas = 0;
    private float $totalCustoCliente = 0;
    private float $totalDispendios = 0;
    private int $totalSegundosLibreCode = 0;
    private float $baseCalculoDispendios = 0;
    private int $percentualMaximo = 0;
    private float $percentualDispendios = 0;
    private int $diasUteis;
    private bool $sobrasDistribuidas = false;
    private bool $previsao = false;
    private ?DateTime $inicio = null;
    private DateTime $fim;
    private DateTime $inicioProximoMes;
    private DateTime $fimProximoMes;

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
        $totalPossivelDeHoras = $this->getTotalCooperados() * 8 * $this->diasUteis;

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
     * * Impostos em geral: nota fiscal, IRPF, INSS
     * * Cliente: Todos os custos dos clientes
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
                'bruto' => $base - ($base * $percentualDesconto / 100),
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

    private function getPercentualTrabalhadoPorCliente(): array
    {
        if (count($this->percentualTrabalhadoPorCliente)) {
            return $this->percentualTrabalhadoPorCliente;
        }
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
        $cnpjClientesInternos = '"' . implode('","', $cnpjClientesInternos) . '"';
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Percentual trabalhado por cliente
            SELECT u.alias,
                c.name,
                COALESCE(sum(t.duration), 0) * 100 / total_cliente.total AS percentual_trabalhado,
                c.vat_id as cliente_codigo,
                c.id as customer_id 
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
                LEFT JOIN (
                    SELECT customer_reference as codigo,
                        contact_name
                    FROM invoices
                    WHERE category_type = 'income'
                    AND transaction_of_month = :ano_mes
                    GROUP BY customer_reference,
                    contact_name
                    ) faturados_mes ON faturados_mes.codigo = c.vat_id
                WHERE u2.enabled = 1
                AND (faturados_mes.codigo IS NOT NULL OR c.vat_id IN ($cnpjClientesInternos))
                GROUP BY c.id,
                        c.name,
                        c.vat_id
                ) total_cliente ON total_cliente.customer_id = c.id
            WHERE t.`begin` >= :data_inicio
            AND t.`end` <= :data_fim
            AND u.enabled = 1
            GROUP BY c.time_budget,
                    c.id,
                    c.name,
                    c.vat_id,
                    u.alias
            ORDER BY c.id,
                    u.alias
            SQL
        );
        $result = $stmt->executeQuery([
            'data_inicio' => $this->inicio->format('Y-m-d'),
            'data_fim' => $this->fim->format('Y-m-d H:i:s'),
            'ano_mes' => $this->inicioProximoMes->format('Y-m'),
        ]);
        $this->percentualTrabalhadoPorCliente = [];
        while ($row = $result->fetchAssociative()) {
            if (!$row['cliente_codigo']) {
                continue;
            }
            // Inicializa o bruto com zero
            $this->setBrutoCooperado($row['alias'], 0);
            $this->percentualTrabalhadoPorCliente[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($this->percentualTrabalhadoPorCliente)]);
        return $this->percentualTrabalhadoPorCliente;
    }

    public function setDiasUteis(int $diasUteis): void
    {
        $this->diasUteis = $diasUteis;
    }

    public function setPercentualMaximo(int $percentualMaximo): void
    {
        $this->percentualMaximo = $percentualMaximo;
    }

    public function setPrevisao(bool $previsao): void
    {
        $this->previsao = $previsao;
    }

    public function getBrutoPorCooperado(): array
    {
        if ($this->brutoPorCooperado) {
            return $this->brutoPorCooperado;
        }

        $this->distribuiProducaoExterna();
        $this->distribuiSobras();
        $this->logger->debug('Bruto por cooperado: {json}', ['json' => json_encode($this->brutoPorCooperado)]);
        return $this->brutoPorCooperado;
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
            $this->setBrutoCooperado(
                $row['alias'],
                $this->getBrutoCooperado($row['alias']) + $aReceberDasSobras
            );
        }
    }

    private function setBrutoCooperado(string $cooperado, float $bruto): void
    {
        $this->brutoPorCooperado[$cooperado] = $bruto;
    }

    private function getBrutoCooperado(string $cooperado): float
    {
        if (!array_key_exists($cooperado, $this->brutoPorCooperado)) {
            throw new Exception(sprintf(
                'Cooperado %s não encontrado',
                [$cooperado]
            ));
        }
        return $this->brutoPorCooperado[$cooperado];
    }

    private function distribuiProducaoExterna(): void
    {
        if ($this->sobrasDistribuidas) {
            return;
        }
        $percentualTrabalhadoPorCliente = $this->getPercentualTrabalhadoPorCliente();
        $totalPorCliente = array_column($this->getValoresPorProjeto(), 'bruto', 'customer_reference');
        $errors = [];
        $cnpjClientesInternos = explode(',', $_ENV['CNPJ_CLIENTES_INTERNOS']);
        foreach ($percentualTrabalhadoPorCliente as $row) {
            if (in_array($row['cliente_codigo'], $cnpjClientesInternos)) {
                continue;
            }
            if (!isset($totalPorCliente[$row['cliente_codigo']])) {
                $errors[] = $row;
                continue;
            }
            $brutoCliente = $totalPorCliente[$row['cliente_codigo']];
            $aReceber = $brutoCliente * $row['percentual_trabalhado'] / 100;
            $this->setBrutoCooperado(
                $row['alias'],
                $this->getBrutoCooperado($row['alias']) + $aReceber
            );
        }
        if (count($errors)) {
            throw new Exception(
                "CNPJ não encontrado em uma transação no mês.\n" .
                "Dados:\n" .
                json_encode($errors, JSON_PRETTY_PRINT)
            );
        }
        $this->sobrasDistribuidas = true;
    }

    private function getTotalDistribuido(): float
    {
        return array_sum($this->brutoPorCooperado);
    }

    private function getTotalSobras(): float
    {
        $this->distribuiProducaoExterna();
        return $this->getBaseCalculoDispendios() - $this->getTotalDispendios() - $this->getTotalDistribuido();
    }

    public function saveOds(): void
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
            ->fromArray(['Cliente', 'referência', 'valor do serviço', 'impostos', 'total dos custos', 'bruto'])
            ->fromArray($this->getValoresPorProjeto(), null, 'A2');

        $spreadsheet->createSheet()
            ->setTitle('Trabalhado por cliente')
            ->fromArray(['Cooperado', 'Cliente', 'Percentual trabalhado', 'cliente codigo', 'customer id'])
            ->fromArray($this->getPercentualTrabalhadoPorCliente(), null, 'A2');

        $producao = $spreadsheet->getSheetByName('mês')
            ->setTitle($this->inicio->format('Y-m'));
        $brutoCooperado = $this->getBrutoPorCooperado();
        $row = 4;
        foreach ($brutoCooperado as $nome => $bruto) {
            $producao->setCellValue('A' . $row, $nome);
            $producao->setCellValue('N' . $row, $nome);
            $producao->setCellValue('O' . $row, 'Produção cooperativista');
            $producao->setCellValue('P' . $row, $bruto);
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