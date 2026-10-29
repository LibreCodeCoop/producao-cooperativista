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
use App\Service\Calculation\AdministrativeRate;
use App\Service\Calculation\CustomerCostAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;

class Movimentacao
{
    private array $movimentacao = [];
    /** @var array<string, int|string> */
    private array $movementKeysBySource = [];
    private ?array $customerReferenceRequiredCategories = null;
    private ?array $custosPorCliente = null;
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
        private CustomerCostAllocator $customerCostAllocator,
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
                CASE WHEN t.discount_percentage > 0 THEN 1 ELSE 0 END as percentual_desconto_fixo,
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
            SELECT 'invoice_transaction',
                i.id,
                i.type,
                payments.transaction_of_month,
                i.discount_percentage,
                CASE WHEN i.discount_percentage > 0 THEN 1 ELSE 0 END as percentual_desconto_fixo,
                payments.paid_amount AS amount,
                CASE WHEN i.amount > 0 THEN
                    COALESCE(
                        (
                            SELECT SUM(price)
                            FROM JSON_TABLE(
                                i.metadata,
                                '$.items.data[*]' COLUMNS (
                                    price DOUBLE PATH '$.price'
                                )
                            ) price
                        ),
                        i.amount
                    ) * payments.paid_amount / i.amount
                ELSE payments.paid_amount END AS bruto,
                i.customer_reference,
                i.contact_name,
                i.contact_type,
                i.category_id,
                i.category_name,
                i.category_type,
                i.archive,
                i.metadata,
                0 as base_producao
            FROM (
                SELECT t.metadata->>'$.document_id' AS document_id,
                    t.transaction_of_month,
                    SUM(t.amount) AS paid_amount
                FROM transactions t
                WHERE t.transaction_of_month = :ano_mes
                    AND t.archive = 0
                    AND t.metadata->>'$.document_id' IS NOT NULL
                GROUP BY t.metadata->>'$.document_id', t.transaction_of_month
            ) payments
            JOIN invoices i ON payments.document_id = i.id
            WHERE i.archive = 0
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
        $movimentacao = [];
        $errors = [];
        $ignoredUnpaidInvoices = [];
        while ($row = $stmt->fetchAssociative()) {
            // An issued document without a linked financial transaction is
            // eligible as customer income only when Akaunting confirms payment.
            // A sent/cancelled/draft document is not capital for distribution.
            if ($row['table'] === 'invoice'
                && $row['category_type'] === 'income'
                && $this->isCustomerReferenceRequired($row)) {
                $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
                if (($metadata['status'] ?? null) !== 'paid') {
                    $ignoredUnpaidInvoices[] = (int) $row['id'];
                    continue;
                }
            }
            if (
                $this->isCustomerReferenceRequired($row)
                && (empty($row['customer_reference']) || !preg_match('/^\d+(\|\S+)?$/', $row['customer_reference']))
            ) {
                // Return actionable identification rather than dumping full financial metadata.
                $errors[] = [
                    'origem' => $row['table'],
                    'id' => $row['id'],
                    'categoria' => $row['category_name'],
                    'codigo_cliente' => $row['customer_reference'],
                ];
            }
            $row['percentual_desconto_fixo'] = (bool) $row['percentual_desconto_fixo'];

            // Transaction and invoice IDs come from independent sequences.
            // Index both instead of silently replacing a movement with the same ID.
            $sourceKey = (string) $row['table'] . ':' . (string) $row['id'];
            if (array_key_exists($sourceKey, $this->movementKeysBySource)) {
                throw new Exception(sprintf(
                    'Movimentação repetida na competência %s: origem %s, ID %s. Verifique parcelas e lançamentos duplicados no Akaunting.',
                    $this->dates->getInicioProximoMes()->format('Y-m'),
                    $row['table'],
                    $row['id']
                ));
            }
            $key = (int) $row['id'];
            if (array_key_exists($key, $movimentacao)) {
                $key = $sourceKey;
            }

            $movimentacao[$key] = $row;
            $this->movementKeysBySource[$sourceKey] = $key;
        }
        if ($ignoredUnpaidInvoices !== []) {
            $this->logger->warning(
                'Não foram incluídas {total} faturas de clientes sem confirmação de pagamento na competência {competencia}. Consulte o Akaunting, filtre os documentos não pagos e confira o vínculo das transações.',
                [
                    'total' => count($ignoredUnpaidInvoices),
                    'competencia' => $this->dates->getInicioProximoMes()->format('Y-m'),
                    'document_ids' => array_slice($ignoredUnpaidInvoices, 0, 30),
                ]
            );
        }
        if (empty($movimentacao)) {
            throw new Exception(sprintf(
                'Sem movimentações financeiras elegíveis na competência %s. Confira faturas pagas, importação, transações vinculadas e categorias do Akaunting.',
                $this->dates->getInicioProximoMes()->format('Y-m')
            ));
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

        $this->movimentacao = $movimentacao;
        $this->calculaBaseProducao();
        $this->logger->debug('Movimentação', [$this->movimentacao]);
        return $this->movimentacao;
    }

    private function isCustomerReferenceRequired(array $row): bool
    {
        return in_array(
            (int) $row['category_id'],
            $this->getCustomerReferenceRequiredCategories(),
            true
        );
    }

    /**
     * @return int[]
     */
    private function getCustomerReferenceRequiredCategories(): array
    {
        if (is_array($this->customerReferenceRequiredCategories)) {
            return $this->customerReferenceRequiredCategories;
        }

        $this->customerReferenceRequiredCategories = array_map(intval(...), array_values(array_unique(array_merge(
            $this->categories->getChildrensCategories((int) getenv('AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID')),
            $this->categories->getChildrensCategories((int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID')),
        ))));

        return $this->customerReferenceRequiredCategories;
    }

    public function setMovimentacao($movimentacao): void
    {
        $sourceKey = (string) ($movimentacao['table'] ?? '') . ':' . (string) $movimentacao['id'];
        $key = $this->movementKeysBySource[$sourceKey] ?? (int) $movimentacao['id'];
        $this->movimentacao[$key] = $movimentacao;
        $this->invalidateDerivedTotals();
    }

    private function invalidateDerivedTotals(): void
    {
        $this->custosPorCliente = null;
        $this->percentualAdministrativo = 0.0;
        $this->taxaAdministrativa = 0.0;
        $this->taxaMaxima = 0.0;
        $this->taxaMinima = 0.0;
        $this->totalCustoCliente = 0.0;
        $this->totalBrutoNotasClientes = 0.0;
        $this->totalDispendios = 0.0;
        $this->totalNotasClientes = 0.0;
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
            $metadata = json_decode((string) ($row['metadata'] ?? '{}'), true);
            $taxes = is_array($metadata) ? ($metadata['item_taxes']['data'] ?? []) : [];
            $taxTotal = is_array($taxes) ? array_sum(array_column($taxes, 'amount')) : 0.0;
            if (($row['table'] ?? null) === 'invoice_transaction' && $taxTotal !== 0.0) {
                // Income and gross value were prorated to the received tranche.
                // Prorate the document's total retained taxes by the same ratio.
                $items = $metadata['items']['data'] ?? [];
                $invoiceGross = is_array($items) ? array_sum(array_column($items, 'price')) : 0.0;
                if ($invoiceGross <= 0 || !is_finite($invoiceGross)) {
                    throw new \DomainException(sprintf(
                        'Documento %s: bruto original ausente para ratear tributos de recebimento parcial. Confira itens e impostos no Akaunting.',
                        (string) $row['id']
                    ));
                }
                $fraction = (float) $row['bruto'] / $invoiceGross;
                if ($fraction < -0.000001 || $fraction > 1.000001) {
                    throw new \DomainException(sprintf(
                        'Documento %s: parcelas recebidas não correspondem ao bruto original. Confira pagamentos e retenções.',
                        (string) $row['id']
                    ));
                }
                $taxTotal *= $fraction;
            }
            $taxToPay = (float) $row['bruto'] - $taxTotal - (float) $row['amount'];
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
        $this->totalDispendios = array_reduce($dispendios, fn ($total, $i) => $total + $i['amount'], 0);
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
        if ($this->custosPorCliente === null) {
            $categorias = $this->categories->getChildrensCategories(
                (int) getenv('AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID')
            );
            $this->custosPorCliente = [];
            foreach ($this->getSaidas() as $row) {
                if (in_array((int) $row['category_id'], $categorias, true)) {
                    $this->custosPorCliente[(string) $row['customer_reference']][] = $row;
                }
            }
        }

        // Cache source records once; filter per call so mixed fixed/movable
        // scenarios do not reuse the result from a previous filter.
        $result = [];
        foreach ($this->custosPorCliente as $customerReference => $rows) {
            foreach ($rows as $row) {
                if ($percentualDescontoFixo !== null
                    && (bool) $row['percentual_desconto_fixo'] !== $percentualDescontoFixo) {
                    continue;
                }
                $result[$customerReference] = ($result[$customerReference] ?? 0.0) + (float) $row['amount'];
            }
        }

        return $result;
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

        $this->taxaMinima = $this->getTotalDispendiosInternos();
        if ($this->taxaMinima > 0) {
            if ($totalNotasParaPercentualMovelSemCustoCliente <= 0) {
                $costs = $this->getCustosPorCliente();
                $unmatched = $this->customerCostAllocator->unmatchedCosts(
                    $this->getEntradasClientes(),
                    $costs
                );
                throw new \DomainException(sprintf(
                    'Base administrativa indisponível na competência %s: receitas móveis elegíveis R$ %s, '
                    . 'custos por cliente R$ %s, base após custos R$ %s e dispêndios internos R$ %s. '
                    . 'Há %d %s com custos sem receita elegível, somando R$ %s. '
                    . 'Confira recebimentos pagos, despesas, categorias e competência no Akaunting; '
                    . 'não redistribua custos sem aprovação.',
                    $this->dates->getInicioProximoMes()->format('Y-m'),
                    number_format($this->totalNotasPercentualMovel(), 2, ',', '.'),
                    number_format(array_sum($costs), 2, ',', '.'),
                    number_format($totalNotasParaPercentualMovelSemCustoCliente, 2, ',', '.'),
                    number_format($this->taxaMinima, 2, ',', '.'),
                    count($unmatched),
                    count($unmatched) === 1 ? 'cliente' : 'clientes',
                    number_format(array_sum($unmatched), 2, ',', '.')
                ));
            }
        }

        $result = (new AdministrativeRate())->evaluate(
            $totalNotasParaPercentualMovelSemCustoCliente,
            $this->taxaMinima,
            $this->percentualMaximo,
        );
        $this->taxaMinima = $result['minimum'];
        $this->taxaMaxima = $result['maximum'];
        $this->taxaAdministrativa = $result['rate'];

        if ($this->taxaMinima > 0) {


            $this->percentualAdministrativo = $result['effective_percent'];
            if ($this->percentualAdministrativo > 100.0 + 0.000001) {
                $orphanCosts = $this->customerCostAllocator->unmatchedCosts(
                    $this->getEntradasClientes(),
                    $this->getCustosPorCliente()
                );
                throw new \DomainException(sprintf(
                    'Capital elegível insuficiente na competência %s: a base administrativa após custos por cliente é R$ %s, '
                    . 'mas os dispêndios internos somam R$ %s (déficit R$ %s). '
                    . 'A taxa necessária seria %s%%, acima de 100%% e da referência configurada de %d%%. '
                    . 'Há %d %s com custos sem receita elegível, totalizando R$ %s. '
                    . 'Confira no Akaunting os recebimentos efetivamente pagos, as despesas, os custos por cliente e as categorias; '
                    . 'não redistribua custos nem altere a taxa sem aprovação da política econômica.',
                    $this->dates->getInicioProximoMes()->format('Y-m'),
                    number_format($totalNotasParaPercentualMovelSemCustoCliente, 2, ',', '.'),
                    number_format($this->taxaMinima, 2, ',', '.'),
                    number_format($this->taxaAdministrativa - $totalNotasParaPercentualMovelSemCustoCliente, 2, ',', '.'),
                    number_format($this->percentualAdministrativo, 2, ',', '.'),
                    $this->percentualMaximo,
                    count($orphanCosts),
                    count($orphanCosts) === 1 ? 'cliente' : 'clientes',
                    number_format(array_sum($orphanCosts), 2, ',', '.')
                ));
            }
            if ($this->percentualAdministrativo > $this->percentualMaximo) {
                $this->logger->warning(
                    'Taxa efetiva {apurado}% superior à referência {limite}% para cobrir dispêndios, conforme contingência operacional.',
                    ['apurado' => $this->percentualAdministrativo, 'limite' => $this->percentualMaximo]
                );
            }
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
        if ($percentualMaximo < 0 || $percentualMaximo > 100) {
            throw new \InvalidArgumentException('Percentual administrativo máximo deve estar entre 0 e 100.');
        }
        if ($this->percentualMaximo !== $percentualMaximo) {
            $this->invalidateDerivedTotals();
        }
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
        $custosPorCliente = $this->getCustosPorCliente();
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
        $custosAlocados = $this->customerCostAllocator->allocate($entradasClientes, $custosPorCliente);

        foreach ($entradasClientes as $key => $row) {
            $base = (float) $row['amount'] - ($custosAlocados[$key] ?? 0.0);
            if (!$row['percentual_desconto_fixo']) {
                $row['discount_percentage'] = $percentualAdministrativo;
            }
            $row['base_producao'] = $base - ($base * $row['discount_percentage'] / 100);
            if ($row['base_producao'] < -0.000001) {
                throw new \DomainException(sprintf(
                    'Produção negativa no lançamento %s:%s da competência %s: receita R$ %s, custo alocado R$ %s, '
                    . 'taxa administrativa/desconto de %s%% e base calculada R$ %s. '
                    . 'Confira o documento, os custos do cliente e as condições aprovadas antes de distribuir.',
                    (string) ($row['table'] ?? 'movimentacao'),
                    (string) ($row['id'] ?? $key),
                    $this->dates->getInicioProximoMes()->format('Y-m'),
                    number_format((float) $row['amount'], 2, ',', '.'),
                    number_format((float) ($custosAlocados[$key] ?? 0), 2, ',', '.'),
                    number_format((float) $row['discount_percentage'], 2, ',', '.'),
                    number_format((float) $row['base_producao'], 2, ',', '.')
                ));
            }
            // Calculated output does not alter revenue or expenses. Preserve
            // cached aggregate totals; mutating input via setMovimentacao()
            // independently invalidates every derived total.
            $this->movimentacao[$key] = $row;
        }

        $this->logger->debug('Entradas no mês com base de produção', [json_encode($entradasClientes)]);
        return $this;
    }
}
