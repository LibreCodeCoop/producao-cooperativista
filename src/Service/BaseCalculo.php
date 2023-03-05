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

namespace KimaiClient\Service;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Exception;
use KimaiClient\DB\Database;
use KimaiClient\Service\Source\Customers;
use KimaiClient\Service\Source\Nfse;
use KimaiClient\Service\Source\Projects;
use KimaiClient\Service\Source\Timesheets;
use KimaiClient\Service\Source\Transactions;
use KimaiClient\Service\Source\Users;
use Psr\Log\LoggerInterface;

class BaseCalculo
{
    private array $custosPorCliente = [];
    private array $valoresPorProjeto = [];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private Customers $customers,
        private Nfse $nfse,
        private Projects $projects,
        private Timesheets $timesheets,
        private Transactions $transactions,
        private Users $users,
    )
    {
    }

    public function getData(DateTime $inicio, int $diasUteis, int $percentualMaximo, bool $forceUpdate): array
    {
        $inicio = $inicio
            ->modify('first day of this month')
            ->setTime(00, 00, 00);
        $fim = clone $inicio;
        $fim = $fim->modify('last day of this month')
            ->setTime(23, 59, 59);

        $inicioProximoMes = (clone $inicio)->modify('first day of next month');
        $fimProximoMes = (clone $fim)->modify('last day of next month');

        if ($forceUpdate) {
            $this->loadFromExternalSources($inicio, $inicioProximoMes);
        }

        // Funções que disparam exception são executadas primeiro para validação dos dados
        $totalCooperados = $this->getTotalPessoasMes($inicio, $fim);
        $totalNotas = $this->getTotalNotasEImpostos($inicioProximoMes, $fimProximoMes);
        $totalCustoCliente = $this->getTotalCustoCliente($inicioProximoMes, $fimProximoMes);

        $totalSegundosLibreCode = $this->getTotalSegundosLibreCode($inicio, $fim);
        $totalDispendios = $this->getTotalDispendios($inicioProximoMes, $fimProximoMes);
        $taxaMinima = $totalDispendios - $totalCustoCliente;
        $taxaMaxima = $taxaMinima * 2;
        $percentualNota = $totalNotas['notas'] * $percentualMaximo / 100;
        $trabalhoLibreCode = $totalSegundosLibreCode / 60 / 60 / 8 / $diasUteis;
        $percentualLibreCode = $trabalhoLibreCode / $totalCooperados * 100;
        $bruto = $totalNotas['notas'] - $totalNotas['impostos'] - $totalCustoCliente - $totalDispendios;
        $percentualImpostoNota = $totalNotas['impostos'] * 100 / $totalNotas['notas'];
        if ($totalNotas['notas'] * $percentualMaximo / 100 >= $taxaMaxima) {
            $taxaAdministrativa = $taxaMaxima;
        } elseif ($totalNotas['notas'] * $percentualMaximo / 100 >= $taxaMinima) {
            $taxaAdministrativa = $totalNotas['notas'] * $percentualMaximo / 100;
        } else {
            $taxaAdministrativa = $taxaMinima;
        }
        $percentualDispendio = $taxaAdministrativa / ($totalNotas['notas'] - $totalNotas['impostos'] - $totalCustoCliente) * 100;
        $totalLibrecodeSobreClientes = $bruto * $percentualLibreCode / 100;
        $brutoLibreCode = $this->getBrutoLibreCode(
            $inicio,
            $fim,
            $inicioProximoMes,
            $fimProximoMes,
            $totalLibrecodeSobreClientes,
            $percentualDispendio,
            $percentualLibreCode
        );
        $valoresPorProjeto = $this->getValoresPorProjeto(
            $inicioProximoMes,
            $fimProximoMes,
            $percentualDispendio,
            $percentualLibreCode
        );
        $trabalhadoPorCliente = $this->getTrabalhadoPorCliente(
            $inicio,
            $fim,
            $inicioProximoMes,
            $fimProximoMes
        );
        $brutoPorCooperado = $this->getBrutoPorCooperado(
            $trabalhadoPorCliente,
            $valoresPorProjeto,
            $brutoLibreCode
        );
        return $brutoPorCooperado;
    }

    private function loadFromExternalSources(
        DateTIme $inicio,
        DateTIme $inicioProximoMes
    ): void
    {
        $this->logger->debug('Baixando dados externos');
        $this->customers->updateDatabase();
        $this->nfse->updateDatabase($inicioProximoMes);
        $this->projects->updateDatabase();
        $this->timesheets->updateDatabase($inicio);
        $this->transactions->updateDatabase($inicioProximoMes);
        $this->users->updateDatabase();
    }

    private function getTotalSegundosLibreCode(DateTime $inicio, DateTime $fim): int
    {
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
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d H:i:s'),
        ]);
        $return = $result->fetchOne();
        $this->logger->debug('Total segundos LibreCode: {total}', ['total' => (int) $return]);
        return (int) $return;
    }

    /**
     * Retorna o total de pessoas que registraram horas no Kimai em um intervalo
     * de datas
     *
     * @throws Exception
     * @param DateTime $inicio
     * @param DateTime $fim
     * @return integer
     */
    private function getTotalPessoasMes(DateTime $inicio, DateTime $fim): int
    {
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
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
        ]);
        $return = $result->fetchOne();

        if (!$return) {
            $messagem = sprintf(
                'Sem registro de horas no Kimai entre os dias %s e %s.',
                $inicio->format(('Y-m-d')),
                $fim->format(('Y-m-d'))
            );
            throw new Exception($messagem);
        }
        $this->logger->debug('Total pessoas no mês: {total}', ['total' => (int) $return]);
        return (int) $return;
    }

    private function getTotalDispendios(DateTime $inicio, DateTime $fim): float
    {
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Total dispêndios
            SELECT sum(t.amount) AS total_dispendios
                FROM transactions t
            WHERE t.paid_at >= :inicio
                AND t.paid_at <= :fim
            --    AND t.category_id = 16
                AND t.category_type = 'expense'
                AND category_name NOT IN ('Produção cooperativista', 'Produção externa', 'Impostos', 'Cliente')
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
        ]);
        $return = $result->fetchOne();
        $this->logger->debug('Total dispêndios: {total}', ['total' => (int) $return]);
        return (float) $return;
    }

    /**
     * Retorna valor total de notas e de impostos em um mês
     *
     * @throws Exception
     * @param DateTime $inicio
     * @param DateTime $fim
     * @return array
     */
    private function getTotalNotasEImpostos(DateTime $inicio, DateTime $fim): array
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select
            ->select(
                'SUM(n.valor_servico) as notas',
                'SUM(n.valor_cofins + n.valor_ir + n.valor_pis + n.valor_iss) as impostos'
            )
            ->from('nfse', 'n')
            ->where('n.numero_substituta IS NULL')
            ->andWhere('data_emissao >= :inicio')
            ->andWhere('data_emissao <= :fim');
        if ($_ENV['IGNORAR_CNPJ']) {
            $listaCnpj = explode(',', $_ENV['IGNORAR_CNPJ']);
            $select
                ->where($select->expr()->notIn('cnpj', ':cnpj'))
                ->setParameter('cnpj', $listaCnpj, Connection::PARAM_STR_ARRAY);
        }
        $select
            ->setParameter('inicio', $inicio->format('Y-m-d'))
            ->setParameter('fim', $fim->format('Y-m-d'));
        $result = $select->executeQuery();
        $return = $result->fetchAssociative();
        if (is_null($return['notas'])) {
            $messagem = sprintf(
                'Sem notas entre os dias %s e %s.',
                $inicio->format(('Y-m-d')),
                $fim->format(('Y-m-d'))
            );
            throw new Exception($messagem);
        }
        $this->logger->debug('Total notas e impostos: {total}', ['total' => json_encode($return)]);
        return $return;
    }

    /**
     * Total de custos por cliente
     *
     * @throws Exception
     * @param DateTime $inicio
     * @param DateTime $fim
     * @return float
     */
    private function getTotalCustoCliente(DateTime $inicio, DateTime $fim): float
    {
        $rows = $this->getCustosPorCliente($inicio, $fim);
        $total = array_reduce($rows, function($total, $row): float {
            $total += $row['total_custos'];
            return $total;
        }, 0);
        $this->logger->debug('Total custos clientes: {total}', ['total' => $total]);
        return $total;
    }

    /**
     * Lista de clientes e seus custos em um mês
     *
     * @throws Exception
     * @param DateTime $inicio
     * @param DateTime $fim
     * @return array
     */
    private function getCustosPorCliente(DateTime $inicio, DateTime $fim): array
    {
        if ($this->custosPorCliente) {
            return $this->custosPorCliente;
        }
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Custos clientes
            SELECT t.reference,
                SUM(amount) AS total_custos,
                t.contact_name
            FROM transactions t
            WHERE t.paid_at >= :inicio
            AND t.paid_at <= :fim
            --    AND t.category_id = 
            AND t.category_type = 'expense'
            AND category_name IN ('Cliente')
            GROUP BY t.reference, t.contact_name
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
        ]);
        $this->custosPorCliente = [];
        while ($row = $result->fetchAssociative()) {
            if (!preg_match('/^\d+(\|\S+)?$/', $row['reference'])) {
                throw new Exception('Referência de cliente inválida no Akaunting: ' . json_encode($row));
            }
            $this->custosPorCliente[] = $row;
        }
        $this->logger->debug('Custos por clientes: {json}', ['json' => json_encode($this->custosPorCliente)]);
        return $this->custosPorCliente;
    }

    private function getValoresPorProjeto(
        DateTime $inicio,
        DateTime $fim,
        float $percentualDispendio,
        float $percentualLibreCode
    ): array
    {
        if ($this->valoresPorProjeto) {
            return $this->valoresPorProjeto;
        }
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Notas clientes
            SELECT c.name,
                ti.contact_reference,
                n.valor_servico,
                n.valor_cofins + n.valor_ir + n.valor_pis + n.valor_iss AS impostos,
                COALESCE(custos.total_custos, 0) AS total_custos
            FROM customers c
            JOIN transactions ti
                ON ti.contact_reference = c.vat_id
            AND ti.paid_at >= :data_inicio
            AND ti.paid_at <= :data_fim
            AND ti.category_type = 'income'
            AND category_name IN ('Recorrência', 'Serviço')
            JOIN nfse n ON n.numero = ti.reference
            LEFT JOIN (
                -- Custos clientes
                SELECT t.reference,
                    SUM(amount) AS total_custos
                FROM transactions t
                WHERE t.paid_at >= :data_inicio
                AND t.paid_at <= :data_fim
                --    AND t.category_id = 
                AND t.category_type = 'expense'
                AND category_name IN ('Cliente')
                GROUP BY t.reference
                ) custos ON custos.reference = ti.contact_reference
            SQL
        );
        $result = $stmt->executeQuery([
            'data_inicio' => $inicio->format('Y-m-d'),
            'data_fim' => $fim->format('Y-m-d'),
        ]);
        $this->valoresPorProjeto = [];
        while ($row = $result->fetchAssociative()) {
            $base = $row['valor_servico'] - $row['impostos'] - $row['total_custos'];
            $semDispendios = $base - ($base * $percentualDispendio / 100);
            $semPercentualLibreCode = $semDispendios - ($semDispendios * $percentualLibreCode / 100);
            $row['bruto'] = $semPercentualLibreCode;
            $this->valoresPorProjeto[] = $row;
        }
        $this->logger->debug('Valores por projetos: {valores}', ['valores' => json_encode($this->valoresPorProjeto)]);
        return $this->valoresPorProjeto;
    }

    private function getSobrasPorCliente(
        DateTime $inicio,
        DateTime $fim,
        DateTime $inicioProximoMes,
        DateTime $fimProximoMes
    ): array
    {
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Sobras por clientes
            SELECT COALESCE(sum(t.duration), 0) AS trabalhado,
                c.time_budget as contratado,
                c.id as customer_id,
                c.vat_id,
                c.name,
                CASE WHEN sum(t.duration) > c.time_budget THEN 0
                        WHEN sum(t.duration) IS NULL THEN 100
                        ELSE 100 - (sum(t.duration) * 100 / c.time_budget)
                        END AS percentual_sobras
            FROM customers c
            JOIN transactions tr
              ON tr.contact_reference = c.vat_id
            AND tr.paid_at >= :data_inicio_proximo_mes
            AND tr.paid_at <= :data_fim_proximo_mes
            AND tr.category_type = 'income'
            AND tr.category_name IN ('Recorrência', 'Serviço')
            LEFT JOIN projects p ON p.customer_id = c.id
            LEFT JOIN timesheet t ON t.project_id = p.id AND t.`begin` >= :data_inicio AND t.`end` <= :data_fim
            GROUP BY c.time_budget,
                    c.id,
                    c.name,
                    c.vat_id
            SQL
        );
        $result = $stmt->executeQuery([
            'data_inicio' => $inicio->format('Y-m-d'),
            'data_fim' => $fim->format('Y-m-d H:i:s'),
            'data_inicio_proximo_mes' => $inicioProximoMes->format('Y-m-d'),
            'data_fim_proximo_mes' => $fimProximoMes->format('Y-m-d'),
        ]);
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            if (!preg_match('/^\d+(\|\S+)?$/', $row['vat_id'])) {
                throw new Exception('Referência de cliente (campo vat_id) inválida no Kimai: ' . json_encode($row));
            }
            $rows[] = $row;
        }
        $this->logger->debug('Sobras por clientes: {sobras}', ['sobras' => json_encode($rows)]);
        return $rows;
    }

    private function getBrutoLibreCode(
        DateTime $inicio,
        DateTime $fim,
        DateTime $inicioProximoMes,
        DateTime $fimProximoMes,
        float $totalLibrecodeSobreClientes,
        float $percentualDispendio,
        float $percentualLibreCode
    ): float
    {
        $sobrasPorClientes = $this->getSobrasPorCliente($inicio, $fim, $inicioProximoMes, $fimProximoMes);
        $valoresPorProjeto = $this->getValoresPorProjeto(
            $inicioProximoMes,
            $fimProximoMes,
            $percentualDispendio,
            $percentualLibreCode
        );
        $percentual = [];
        foreach ($sobrasPorClientes as $row) {
            $percentual[$row['vat_id']] = $row['percentual_sobras'];
        }
        $totalSobras = 0;
        foreach ($valoresPorProjeto as $row) {
            $percentual[$row['contact_reference']] * $row['bruto'] / 100;
            $totalSobras += $percentual[$row['contact_reference']] * $row['bruto'] / 100;
        }
        $brutoLibreCode = $totalSobras + $totalLibrecodeSobreClientes;
        $this->logger->debug('Bruto LibreCode: {total}', ['total' => (int) $brutoLibreCode]);
        return $brutoLibreCode;
    }

    private function getTrabalhadoPorCliente(
        DateTime $inicio,
        DateTime $fim,
        DateTime $inicioProximoMes,
        DateTime $fimProximoMes
    ): array
    {
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Trabalhado por cliente
            SELECT u.alias,
                c.name,
                COALESCE(sum(t.duration), 0) * 100 / total_cliente.total AS percentual_trabalhado,
                c.vat_id,
                c.id as customer_id 
            FROM customers c
            JOIN projects p ON p.customer_id = c.id
            JOIN timesheet t ON t.project_id = p.id
            JOIN users u ON u.id = t.user_id
            JOIN (
                    -- Total horas por cliente
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
                        SELECT CASE WHEN setor IS NOT NULL THEN CONCAT(cnpj, '|', setor)
                                    ELSE cnpj END as codigo,
                                n.razao_social
                            FROM nfse n 
                        WHERE n.data_emissao >= :data_inicio_proximo_mes
                            AND n.data_emissao <= :data_fim_proximo_mes
                        GROUP BY n.razao_social,
                                    n.valor_servico,
                                    n.setor,
                                    n.cnpj
                    ) faturados_mes ON faturados_mes.codigo = c.vat_id
                WHERE u2.enabled = 1
                AND (faturados_mes.codigo IS NOT NULL OR c.id IN (1, 2))
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
            'data_inicio' => $inicio->format('Y-m-d'),
            'data_fim' => $fim->format('Y-m-d H:i:s'),
            'data_inicio_proximo_mes' => $inicioProximoMes->format('Y-m-d'),
            'data_fim_proximo_mes' => $fimProximoMes->format('Y-m-d'),
        ]);
        $rows = [];
        while ($row = $result->fetchAssociative()) {
            if (!$row['vat_id']) {
                continue;
            }
            $rows[] = $row;
        }
        $this->logger->debug('Trabalhado por cliente: {json}', ['json' => json_encode($rows)]);
        return $rows;
    }

    private function getBrutoPorCooperado(
        array $trabalhadoPorCliente,
        array $valoresPorProjeto,
        float $brutoLibreCode
    ): array
    {
        $totalPorCliente = [];
        foreach ($valoresPorProjeto as $row) {
            $totalPorCliente[$row['contact_reference']] = $row['bruto'];
        }

        // Inicia array com zero para poder incrementar com valores
        $cooperados = array_unique(array_column($trabalhadoPorCliente, 'alias'));
        $cooperados = array_combine(
            $cooperados,
            array_fill(0, count($cooperados), 0)
        );

        foreach ($trabalhadoPorCliente as $row) {
            if ($row['name'] === 'LibreCode') {
                $cooperados[$row['alias']] += $brutoLibreCode * $row['percentual_trabalhado'] / 100;
            } else {
                $cooperados[$row['alias']] += $totalPorCliente[$row['vat_id']] * $row['percentual_trabalhado'] / 100;
            }
        }
        $this->logger->debug('Bruto por cooperado: {json}', ['json' => json_encode($cooperados)]);
        return $cooperados;
    }
}