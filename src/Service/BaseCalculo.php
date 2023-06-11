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
    private float $totalImpostos = 0;
    private float $totalCustoCliente = 0;
    private float $totalDispendios = 0;
    private int $totalSegundosLibreCode = 0;
    private float $baseCalculoDispendios = 0;
    private int $percentualMaximo = 0;
    private float $percentualConselhoAdministrativo = 0;
    private int $diasUteis;
    private bool $sobrasDistribuidas = false;
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
        $this->baseCalculoDispendios = $this->getTotalNotas() - $this->getTotalImpostos() - $this->getTotalCustoCliente();
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
     * Valor reservado de cada projeto para pagar o Conselho Administrativo
     */
    private function percentualConselhoAdministrativo(): float
    {
        if ($this->percentualConselhoAdministrativo) {
            return $this->percentualConselhoAdministrativo;
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

        $this->percentualConselhoAdministrativo = $taxaAdministrativa / ($this->getBaseCalculoDispendios()) * 100;
        return $this->percentualConselhoAdministrativo;
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
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Total dispêndios
            SELECT sum(t.amount) AS total_dispendios
                FROM transactions t
            WHERE t.paid_at >= :inicio
                AND t.paid_at <= :fim
            --    AND t.category_id = 16
                AND t.category_type = 'expense'
                AND category_name NOT IN (
                    'Produção cooperativista',
                    'Produção externa',
                    'Impostos',
                    'Cliente',
                    'Plano de saúde'
                )
            SQL
        );
        $result = $stmt->executeQuery([
            'inicio' => $this->inicioProximoMes->format('Y-m-d'),
            'fim' => $this->fimProximoMes->format('Y-m-d'),
        ]);
        $this->totalDispendios = (float) $result->fetchOne();
        $this->logger->debug('Total dispêndios: {total}', ['total' => $this->totalDispendios]);
        return $this->totalDispendios;
    }

    /**
     * Retorna valor total de notas e de impostos em um mês
     *
     * @throws Exception
     */
    private function totalNotasEImpostos(): void
    {
        if ($this->totalNotas) {
            return;
        }
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
            $cnpjIgnorados = explode(',', $_ENV['IGNORAR_CNPJ']);
            $select
                ->andWhere($select->expr()->notIn('cnpj', ':cnpj'))
                ->setParameter('cnpj', $cnpjIgnorados, ArrayParameterType::STRING);
        }
        $select
            ->setParameter('inicio', $this->inicioProximoMes->format('Y-m-d'))
            ->setParameter('fim', $this->fimProximoMes->format('Y-m-d'));
        $result = $select->executeQuery();
        $return = $result->fetchAssociative();
        if (is_null($return['notas'])) {
            $messagem = sprintf(
                'Sem notas entre os dias %s e %s.',
                $this->inicio->format(('Y-m-d')),
                $this->fim->format(('Y-m-d'))
            );
            throw new Exception($messagem);
        }
        $this->logger->debug('Total notas e impostos: {total}', ['total' => json_encode($return)]);
        $this->totalNotas = $return['notas'];
        $this->totalImpostos = $return['impostos'];
    }

    private function getTotalNotas(): float
    {
        $this->totalNotasEImpostos();
        return $this->totalNotas;
    }

    private function getTotalImpostos(): float
    {
        $this->totalNotasEImpostos();
        return $this->totalImpostos;
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
            'inicio' => $this->inicioProximoMes->format('Y-m-d'),
            'fim' => $this->fimProximoMes->format('Y-m-d'),
        ]);
        $this->custosPorCliente = [];
        $errors = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['reference'])) {
                $errors[] = $row;
            }
            $this->custosPorCliente[] = $row;
        }
        if (count($errors)) {
            throw new Exception(
                "Referência de cliente inválida no Akaunting para calcular custos por cliente: \n"
                . json_encode($errors, JSON_PRETTY_PRINT)
            );
        }
        $this->logger->debug('Custos por clientes: {json}', ['json' => json_encode($this->custosPorCliente)]);
        return $this->custosPorCliente;
    }

    private function getPercentualDesconto(): float
    {
        $percentualDesconto = $this->percentualConselhoAdministrativo() + $this->percentualLibreCode();
        return $percentualDesconto;
    }

    private function getValoresPorProjeto(): array
    {
        if ($this->valoresPorProjeto) {
            return $this->valoresPorProjeto;
        }

        $percentualDesconto = $this->getPercentualDesconto();

        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Notas clientes
            SELECT c.name,
                ti.id,
                ti.contact_reference,
                ti.reference,
                ti.paid_at,
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
            LEFT JOIN nfse n ON n.numero = ti.reference
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
            'data_inicio' => $this->inicioProximoMes->format('Y-m-d'),
            'data_fim' => $this->fimProximoMes->format('Y-m-d'),
        ]);
        $this->valoresPorProjeto = [];
        $errors = [];
        while ($row = $result->fetchAssociative()) {
            if (empty($row['reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['reference'])) {
                $errors[] = $row;
            }
            $base = $row['valor_servico'] - $row['impostos'] - $row['total_custos'];
            $row['bruto'] = $base - ($base * $percentualDesconto / 100);
            $this->valoresPorProjeto[] = $row;
        }
        if (count($errors)) {
            throw new Exception(
                "Referência de cliente inválida no Akaunting para calcular valores por projeto: \n" .
                json_encode($errors, JSON_PRETTY_PRINT)
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
        $stmt = $this->db->getConnection()->prepare(<<<SQL
            -- Percentual trabalhado por cliente
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
            'data_inicio' => $this->inicio->format('Y-m-d'),
            'data_fim' => $this->fim->format('Y-m-d H:i:s'),
            'data_inicio_proximo_mes' => $this->inicioProximoMes->format('Y-m-d'),
            'data_fim_proximo_mes' => $this->fimProximoMes->format('Y-m-d'),
        ]);
        $this->percentualTrabalhadoPorCliente = [];
        while ($row = $result->fetchAssociative()) {
            if (!$row['vat_id']) {
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
        foreach ($percentualTrabalhadoPorCliente as $row) {
            if ($row['name'] !== 'LibreCode') {
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
        $totalPorCliente = array_column($this->getValoresPorProjeto(), 'bruto', 'contact_reference');
        $errors = [];
        foreach ($percentualTrabalhadoPorCliente as $row) {
            if ($row['name'] === 'LibreCode') {
                continue;
            }
            if (!isset($totalPorCliente[$row['vat_id']])) {
                $errors[] = $row;
                continue;
            }
            $brutoCliente = $totalPorCliente[$row['vat_id']];
            $aReceber = $brutoCliente * $row['percentual_trabalhado'] / 100;
            $this->setBrutoCooperado(
                $row['alias'],
                $this->getBrutoCooperado($row['alias']) + $aReceber
            );
        }
        if (count($errors)) {
            throw new Exception(
                "Cnpj (vat_id) não encontrado, provavelmente sem nota fiscal ou sem transação no mês: \n" .
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
            ->setCellValue('B7', $this->getTotalImpostos())
            ->setCellValue('B8', $this->getTotalCustoCliente())
            ->setCellValue('B9', $this->getTotalSegundosLibreCode() / 60 / 60)
            ->setCellValue('B10', $this->percentualLibreCode())
            ->setCellValue('B11', $this->percentualConselhoAdministrativo())
            ->setCellValue('B12', $this->getPercentualDesconto());

        $spreadsheet->createSheet()
        ->fromArray(['Referência', 'Custo', 'Fornecedor'])
        ->setTitle('Custo por cliente')
            ->fromArray($this->getCustosPorCliente(), null, 'A2');

        $spreadsheet->createSheet()
            ->setTitle('Valores por projeto')
            ->fromArray(['Cliente', 'referência', 'valor do serviço', 'impostos', 'total dos custos', 'bruto'])
            ->fromArray($this->getValoresPorProjeto(), null, 'A2');

        $spreadsheet->createSheet()
            ->setTitle('Trabalhado por cliente')
            ->fromArray(['Cooperado', 'Cliente', 'Percentual trabalhado', 'vat_id', 'customer id'])
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