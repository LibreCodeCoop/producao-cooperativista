<?php

/**
 * @copyright Copyright (c) 2025, Vitor Mattos <vitor@php.rio>
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

use App\Helper\Dates;
use App\Service\Akaunting\Source\Categories;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

class Movimentacao
{
    private array $movimentacao = [];
    private array $custosPorCliente = [];
    private float $percentualAdministrativo = 0;
    private float $taxaAdministrativa = 0;
    private float $taxaMaxima = 0;
    private float $taxaMinima = 0;
    private float $totalCustoCliente = 0;
    private float $totalBrutoNotasClientes = 0;
    private float $totalDispendios = 0;
    private float $totalNotasClientes = 0;
    private int $percentualMaximo = 0;
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        public Dates $dates,
        private Categories $categories,
    ) {
    }

    public function getMovimentacaoFinanceira(): array
    {
        if (!empty($this->movimentacao)) {
            return $this->movimentacao;
        }
        $sql = <<<SQL
            SELECT 'transaction' AS `table`,
                t.id,
                t.type,
                t.transaction_of_month,
                t.discount_percentage,
                CASE WHEN i.discount_percentage > 0 THEN 1 ELSE 0 END as percentual_desconto_fixo,
                t.amount,
                COALESCE(
                    (
                        SELECT SUM(price) as total
                        FROM JSON_TABLE(
                            i.metadata,
                            '$.items.data[*]' COLUMNS (
                                price DOUBLE PATH '$.price'
                            )
                        ) price
                    ), t.amount
                ) bruto,
                t.customer_reference,
                t.contact_name,
                t.contact_type,
                t.category_id,
                t.category_name,
                t.category_type,
                t.archive,
                t.metadata,
                0 as base_producao
            FROM transactions t
            LEFT JOIN invoices i ON t.metadata->>'$.document_id' = i.id
            WHERE t.transaction_of_month = :ano_mes
                AND t.archive = 0
                AND i.id IS NULL
            UNION
            SELECT 'invoice_transaction' AS `table`,
                i.id,
                i.type,
                i.transaction_of_month,
                i.discount_percentage,
                CASE WHEN i.discount_percentage > 0 THEN 1 ELSE 0 END as percentual_desconto_fixo,
                i.amount,
                COALESCE(
                    (
                        SELECT SUM(price) as total
                        FROM JSON_TABLE(
                            i.metadata,
                            '$.items.data[*]' COLUMNS (
                                price DOUBLE PATH '$.price'
                            )
                        ) price
                    ), i.amount
                ) bruto,
                i.customer_reference,
                i.contact_name,
                i.contact_type,
                i.category_id,
                i.category_name,
                i.category_type,
                i.archive,
                i.metadata,
                0 as base_producao
            FROM transactions t
            JOIN invoices i ON t.metadata->>'$.document_id' = i.id
            WHERE t.transaction_of_month = :ano_mes
                AND t.archive = 0
            UNION
            SELECT 'invoice' AS `table`,
                i.id,
                i.type,
                i.transaction_of_month,
                i.discount_percentage,
                CASE WHEN i.discount_percentage > 0 THEN 1 ELSE 0 END as percentual_desconto_fixo,
                i.amount,
                COALESCE(
                    (
                        SELECT SUM(price) as total
                        FROM JSON_TABLE(
                            i.metadata,
                            '$.items.data[*]' COLUMNS (
                                price DOUBLE PATH '$.price'
                            )
                        ) price
                    ), i.amount
                ) bruto,
                i.customer_reference,
                i.contact_name,
                i.contact_type,
                i.category_id,
                i.category_name,
                i.category_type,
                i.archive,
                i.metadata,
                0 as base_producao
            FROM invoices i
            LEFT JOIN transactions t ON t.metadata->>'$.document_id' = i.id
            WHERE i.transaction_of_month = :ano_mes
                AND i.archive = 0
                AND t.id IS NULL
            SQL;
        $stmt = $this->entityManager->getConnection()->executeQuery($sql, [
            'ano_mes' => $this->dates->getInicioProximoMes()->format('Y-m')
        ]);
        $errors = [];
        while ($row = $stmt->fetchAssociative()) {
            if (empty($row['customer_reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['customer_reference'])) {
                $errors[] = $row;
            }
            $row['percentual_desconto_fixo'] = (bool) $row['percentual_desconto_fixo'];

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

        $this->calculaBaseProducao();
        $this->logger->debug('Movimentação', [$this->movimentacao]);
        return $this->movimentacao;
    }

    public function setMovimentacao($movimentacao): void
    {
        $this->movimentacao[$movimentacao['id']] = $movimentacao;
    }

    public function getSaidas(): array
    {
        $movimentacao = $this->getMovimentacaoFinanceira();
        $saidas = array_filter($movimentacao, fn ($i) => $i['category_type'] === 'expense');
        return $saidas;
    }

    public function getEntradas(): array
    {
        $movimentacao = $this->getMovimentacaoFinanceira();
        $entradas = array_filter($movimentacao, fn ($i) => $i['category_type'] === 'income');
        return $entradas;
    }

    public function getEntradasClientes(?bool $percentualDescontoFixo = null): array
    {
        $categoriasEntradasClientes = $this->categories->getChildrensCategories((int) getenv('AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID'));
        $entradasClientes = array_filter($this->getEntradas(), fn ($i) => in_array($i['category_id'], $categoriasEntradasClientes));
        if (is_bool($percentualDescontoFixo)) {
            $entradasClientes = array_filter(
                $entradasClientes,
                fn ($i) =>
                $i['percentual_desconto_fixo'] === $percentualDescontoFixo
            );
        }
        return $entradasClientes;
    }

    public function getTotalBrutoNotasClientes(): float
    {
        if ($this->totalBrutoNotasClientes) {
            return $this->totalBrutoNotasClientes;
        }

        $this->totalBrutoNotasClientes = array_reduce($this->getEntradasClientes(), fn ($total, $i) => $total + $i['bruto'], 0);
        return $this->totalBrutoNotasClientes;
    }

    public function getBaseProducao(): float
    {
        $baseProducao = array_sum(array_column($this->getEntradasClientes(), 'base_producao'));
        return $baseProducao;
    }

    public function totalPercentualDescontoFixo(): float
    {
        $entradasClientes = $this->getEntradasClientes();
        $entradasComPercentualFixo = array_filter($entradasClientes, fn ($i) => $i['percentual_desconto_fixo'] === true);
        $total = 0;
        foreach ($entradasComPercentualFixo as $row) {
            $total += $row['amount'] * $row['discount_percentage'] / 100;
        }
        return $total;
    }

    /**
     * Caso o cliente tenha retido algum imposto indevidamente ou deixado de
     * pagar algum imposto, isto deve ser levado em consideração para calcular
     * sobras. Além do amount que é o líquido, é preciso tirar os impostos que
     * não foram retidos e que teremos de pagar.
     */
    public function getTotalSobrasClientesPercentualFixo(): float
    {
        $entradasClientes = $this->getEntradasClientes();
        $entradasComPercentualFixo = array_filter($entradasClientes, fn ($i) => $i['percentual_desconto_fixo'] === true);
        $total = 0;
        foreach ($entradasComPercentualFixo as $row) {
            $metadata = json_decode($row['metadata'], true);
            $taxTotal = array_sum(array_column($metadata['item_taxes']['data'], 'amount'));
            $taxToPay = $row['bruto'] - $taxTotal - $row['amount'];
            $total += $row['amount'] * $row['discount_percentage'] / 100 - $taxToPay;
        }
        return $total;
    }

    /**
     * Dispêndios internos
     *
     * São todos os dispêndios da cooperativa tirando dispêndios do cliente e do cooperado.
     */
    public function getTotalDispendiosInternos(): float
    {
        if ($this->totalDispendios) {
            return $this->totalDispendios;
        }
        $dispendiosInternos = $this->categories->getChildrensCategories((int) getenv('AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID'));
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

    public function totalNotasPercentualMovel(): float
    {
        $entradasClientes = $this->getEntradasClientes();
        // Remove as notas que tem percentual administrativo fixo
        $entradasComPercentualMovel = array_filter($entradasClientes, fn ($i) => $i['percentual_desconto_fixo'] === false);
        // Soma todas as notas sem perecentual administrativo fixo
        $totalNotasParaPercentualMovel = array_reduce($entradasComPercentualMovel, fn ($total, $i) => $total + $i['amount'], 0);
        return $totalNotasParaPercentualMovel;
    }

    /**
     * Lista de clientes e seus custos em um mês
     *
     * @throws Exception
     *
     * @return float[]
     */
    public function getCustosPorCliente(?bool $percentualDescontoFixo = null): array
    {
        if (!$this->custosPorCliente) {
            $categoriasCustosClientes = $this->categories->getChildrensCategories((int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID'));
            $custosPorCliente = array_filter($this->getSaidas(), fn ($i) => in_array($i['category_id'], $categoriasCustosClientes));
            if (is_bool($percentualDescontoFixo)) {
                $custosPorCliente = array_filter(
                    $custosPorCliente,
                    fn ($i) =>
                    $i['percentual_desconto_fixo'] === $percentualDescontoFixo
                );
            }
            foreach ($custosPorCliente as $row) {
                $this->custosPorCliente[$row['customer_reference']][] = $row;
            }

            $this->logger->debug('Custos por clientes: {json}', ['json' => json_encode($this->custosPorCliente)]);
        }
        $custosPorCliente = [];
        foreach ($this->custosPorCliente as $customerReference => $data) {
            $custosPorCliente[$customerReference] = array_sum(
                array_column($data, 'amount')
            );
        }
        return $custosPorCliente;
    }

    public function totalNotasParaPercentualMovelSemCustoCliente(): float
    {
        $totalNotasParaPercentualMovel = $this->totalNotasPercentualMovel();
        $custosPorCliente = $this->getCustosPorCliente();
        $totalNotasParaPercentualMovelSemCustoCliente = $totalNotasParaPercentualMovel - array_sum($custosPorCliente);
        return $totalNotasParaPercentualMovelSemCustoCliente;
    }

    /**
     * Valor reservado de cada projeto para pagar os dispêndios
     */
    public function percentualAdministrativo(): float
    {
        if ($this->percentualAdministrativo) {
            return $this->percentualAdministrativo;
        }

        $totalNotasParaPercentualMovelSemCustoCliente = $this->totalNotasParaPercentualMovelSemCustoCliente();

        $valorSeguranca = $totalNotasParaPercentualMovelSemCustoCliente * $this->percentualMaximo / 100;

        $this->taxaMinima = $this->getTotalDispendiosInternos();

        $this->taxaMaxima = $this->taxaMinima * 2;
        if ($this->taxaMinima >= $valorSeguranca) {
            $this->taxaAdministrativa = $this->taxaMinima;
        } elseif ($this->taxaMaxima >= $valorSeguranca) {
            $this->taxaAdministrativa = $valorSeguranca;
        } else {
            $this->taxaAdministrativa = $this->taxaMaxima;
        }

        if ($this->taxaMinima) {
            $this->percentualAdministrativo = ($this->taxaAdministrativa) * 100 / $totalNotasParaPercentualMovelSemCustoCliente;
            return $this->percentualAdministrativo;
        }
        return 0;
    }

    public function getTaxaAdministrativa(): float
    {
        return $this->taxaAdministrativa;
    }

    public function setPercentualMaximo(int $percentualMaximo): void
    {
        $this->percentualMaximo = $percentualMaximo;
    }

    public function getPercentualMaximo(): float
    {
        return $this->percentualMaximo;
    }

    public function getTaxaMinima(): float
    {
        return $this->taxaMinima;
    }

    public function getTaxaMaxima(): float
    {
        return $this->taxaMaxima;
    }

    /**
     * Retorna valor total pago de notas em um mês
     *
     * @throws Exception
     */
    public function getTotalNotasClientes(): float
    {
        if ($this->totalNotasClientes) {
            return $this->totalNotasClientes;
        }

        $this->totalNotasClientes = array_reduce($this->getEntradasClientes(), fn ($total, $i) => $total + $i['amount'], 0);
        return $this->totalNotasClientes;
    }

    /**
     * Total de custos por cliente
     *
     * @throws Exception
     */
    public function getTotalDispendiosClientesPercentualMovel(): float
    {
        if ($this->totalCustoCliente) {
            return $this->totalCustoCliente;
        }
        $custosPorCliente = $this->getCustosPorCliente(percentualDescontoFixo: false);
        $this->totalCustoCliente = array_sum($custosPorCliente);
        $this->logger->info('Total custos clientes: {total}', ['total' => $this->totalCustoCliente]);
        return $this->totalCustoCliente;
    }

    private function calculaBaseProducao(): self
    {
        $entradasClientes = $this->getEntradasClientes();

        if (count($entradasClientes)) {
            $current = current($entradasClientes);
            if (!empty($current['base_producao'])) {
                return $this;
            }
        }

        $percentualAdministrativo = $this->percentualAdministrativo();
        $custosPorCliente = $this->getCustosPorCliente();

        foreach ($entradasClientes as $row) {
            if (isset($custosPorCliente[$row['customer_reference']])) {
                $base = $row['amount'] - $custosPorCliente[$row['customer_reference']];
                unset($custosPorCliente[$row['customer_reference']]);
            } else {
                $base = $row['amount'];
            }
            if (!$row['percentual_desconto_fixo']) {
                $row['discount_percentage'] = $percentualAdministrativo;
            }
            $row['base_producao'] = $base - ($base * $row['discount_percentage'] / 100);
            $this->setMovimentacao($row);
        }

        $this->logger->debug('Entradas no mês com base de produção', [json_encode($entradasClientes)]);
        return $this;
    }
}
