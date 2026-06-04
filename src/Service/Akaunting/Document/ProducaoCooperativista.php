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

namespace App\Service\Akaunting\Document;

use DateTime;
use Doctrine\DBAL\ParameterType;
use UnexpectedValueException;

class ProducaoCooperativista extends ADocument
{
    protected string $whoami = 'PDC';

    /**
     * @var array<int, array{category_id: int, category_name: string, amount: float, document_number: string, due_at: ?string}>
     */
    private array $liquidDiscounts = [];

    public function save(): self
    {
        $this->somaItensExtras();
        $this->populateProducaoCooperativistaWithDefault();
        parent::save();
        $cooperado = $this->getCooperado();
        if (strlen($cooperado->getTaxNumber()) === 11) {
            $cooperado
                ->getInssIrpf()
                ->saveFromDocument($this);
        }
        return $this;
    }

    protected function somaItensExtras(): void
    {
        $extras = array_reduce($this->getItems(), function (float $total, array $item) {
            $exclude = [
                $this->getItemsIds()['Auxílio'],
                $this->getItemsIds()['bruto'],
            ];
            if (!in_array($item['item_id'], $exclude) && $item['total'] > 0) {
                $total += $item['total'];
            }
            return $total;
        }, 0);

        // FRRA do mês não deve ser calculado em cima dos extras para
        // para evitar de calcular FRRA em cima de FRRA
        // Trava o cálculo do FRRA para que ele não seja alterado
        $this->getValues()->setLockFrra(true);

        $bruto = $this->getValues()->getBruto();

        // Adiciona os itens extras para calcular os impostos
        $baseProducao = $this->getValues()->getBaseProducao();
        $this->getValues()->setBaseProducao(
            $baseProducao
            + $extras,
            false
        );

        $this->getValues()->calculaImpostos();

        // Tira os itens extras do bruto para não impactar no somatório dos
        // itens no Akaunting. Isto só deve ser feito se o bruto não for zero.
        //
        // Caso em que o bruto foi zero que gerou esta observação:
        // Criado nota de FRRA de dezembro, não tem bruto ainda, só os FRRA de
        // cada mês, logo, o cálculo será errado se subtrair os extras do bruto.
        if ($bruto !== 0) {
            $this->getValues()->setBruto(
                $this->getValues()->getBruto()
                - $extras
            );
        }
        // Marca os valores como atualizados para que o bruto não seja
        // recalculado
        $this->getValues()->setUpdated();
    }

    protected function setUp(): self
    {
        try {
            $this->getDueAt();
        } catch (UnexpectedValueException $e) {
            $this->changeDueAt($this->dates->getDataPagamento());
        }
        return parent::setUp();
    }

    public function updateLiquidDiscounts(): self
    {
        $this->liquidDiscounts = [];
        $this->values->setLiquidDiscount(0);

        $categoryIds = $this->getLiquidDiscountCategoryIds();
        if (empty($categoryIds)) {
            return $this;
        }

        $select = $this->entityManager->getConnection()->createQueryBuilder();
        $select->select('i.id')
            ->addSelect('i.category_id')
            ->addSelect('i.category_name')
            ->addSelect('i.document_number')
            ->addSelect('i.due_at')
            ->addSelect('i.metadata')
            ->from('invoices', 'i')
            ->where("i.type = 'bill'")
            ->andWhere($select->expr()->eq('i.transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))))
            ->orderBy('i.category_id', 'ASC')
            ->addOrderBy('i.id', 'DESC');

        $categoryParameters = array_map(
            fn (int $categoryId): string => $select->createNamedParameter($categoryId, ParameterType::INTEGER),
            $categoryIds
        );
        $select->andWhere($select->expr()->in('i.category_id', $categoryParameters));

        $rows = $select->executeQuery()->fetchAllAssociative();
        if (empty($rows)) {
            return $this;
        }

        $selectedDiscounts = [];
        foreach ($rows as $row) {
            $categoryId = (int) ($row['category_id'] ?? 0);
            if ($categoryId <= 0 || isset($selectedDiscounts[$categoryId])) {
                continue;
            }

            $metadata = $this->decodeInvoiceMetadata($row['metadata'] ?? null);
            $notes = $metadata['notes'] ?? null;
            if (!is_string($notes) || $notes === '') {
                continue;
            }

            foreach ($this->extractLiquidDiscountMatches($notes) as $match) {
                if ($match['CPF'] !== $this->getCooperado()->getTaxNumber()) {
                    continue;
                }

                $selectedDiscounts[$categoryId] = [
                    'category_id' => $categoryId,
                    'category_name' => (string) ($row['category_name'] ?? ''),
                    'amount' => $match['value'],
                    'document_number' => (string) ($row['document_number'] ?? ''),
                    'due_at' => isset($row['due_at']) ? (string) $row['due_at'] : null,
                ];
                break;
            }
        }

        if (empty($selectedDiscounts)) {
            return $this;
        }

        $this->liquidDiscounts = array_values($selectedDiscounts);
        $totalLiquidDiscount = array_reduce(
            $this->liquidDiscounts,
            fn (float $carry, array $discount): float => $carry + $discount['amount'],
            0.0
        );
        $this->values->setLiquidDiscount($totalLiquidDiscount);

        return $this;
    }

    /**
     * @return int[]
     */
    private function getLiquidDiscountCategoryIds(): array
    {
        $liquidDiscountParentId = (int) getenv('AKAUNTING_PARENT_DESCONTO_LIQUIDO_CATEGORY_ID');
        if ($liquidDiscountParentId <= 0) {
            return [];
        }

        $categoryIds = $this->getDescendantCategoryIds($liquidDiscountParentId);
        $categoryIds = array_map('intval', $categoryIds);
        $categoryIds = array_filter($categoryIds, fn (int $id): bool => $id > 0);
        return array_values(array_unique($categoryIds));
    }

    /**
     * @return int[]
     */
    private function getDescendantCategoryIds(int $rootCategoryId): array
    {
        $select = $this->entityManager->getConnection()->createQueryBuilder();
        $rows = $select->select('c.id', 'c.parent_id')
            ->from('categories', 'c')
            ->where($select->expr()->eq('c.type', $select->createNamedParameter('expense')))
            ->andWhere($select->expr()->eq('c.enabled', $select->createNamedParameter(1, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        $childrenByParent = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'];
            if ($parentId === null) {
                continue;
            }
            $childrenByParent[(int) $parentId][] = (int) $row['id'];
        }

        $pending = $childrenByParent[$rootCategoryId] ?? [];
        $descendants = [];
        while (!empty($pending)) {
            $currentId = array_pop($pending);
            if (isset($descendants[$currentId])) {
                continue;
            }
            $descendants[$currentId] = $currentId;
            foreach ($childrenByParent[$currentId] ?? [] as $childId) {
                $pending[] = $childId;
            }
        }
        return array_values($descendants);
    }

    private function decodeInvoiceMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (!is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * @return array<int, array{CPF: string, value: float}>
     */
    private function extractLiquidDiscountMatches(string $text): array
    {
        $matches = [];
        $pattern = '/^Cooperado: .*CPF: (?<CPF>\d+)[,;]? Valor: (R\$ ?)?(?<value>.*)$/i';
        foreach (explode("\n", $text) as $row) {
            if (!preg_match($pattern, $row, $match)) {
                continue;
            }
            $matches[] = [
                'CPF' => $match['CPF'],
                'value' => $this->parseMoneyValue($match['value']),
            ];
        }
        return $matches;
    }

    private function parseMoneyValue(string $value): float
    {
        $value = trim($value);
        $value = preg_replace('/[^0-9,.-]/', '', $value) ?? '';
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        return (float) $value;
    }

    protected function getDocumentNumber(): string
    {
        if (empty($this->documentNumber)) {
            $this->setDocumentNumber(
                'PDC_' .
                $this->getCooperado()->getTaxNumber() .
                '-' .
                $this->dates->getInicioProximoMes()->format('Y-m')
            );
        }
        return $this->documentNumber;
    }

    private function populateProducaoCooperativistaWithDefault(): self
    {
        $cooperado = $this->getCooperado();
        $values = $this->getValues();
        $this
            ->setType('bill')
            ->setCategoryId((int) getenv('AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'))
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setCurrencyCode('BRL')
            ->setNote('Data geração', $this->dates->getDataProcessamento()->format('Y-m-d'))
            ->setNote('Produção realizada no mês', $this->dates->getInicio()->format('Y-m'))
            ->setNote('Competência', $this->dates->getInicioProximoMes()->format('Y-m'))
            ->setNote('Notas dos clientes pagas no mês', $this->dates->getInicioProximoMes()->format('Y-m'))
            ->setNote('Dia útil padrão de pagamento', sprintf('%sº', $this->dates->getPagamentoNoDiaUtil()))
            ->setNote('Previsão de pagamento no dia', $this->dates->getDataPagamento()->format('Y-m-d'))
            ->setNote('Base de cálculo', $this->numberFormatter->format($values->getBaseProducao()))
            ->setContactId($cooperado->getAkauntingContactId())
            ->setContactName($cooperado->getName())
            ->setContactTaxNumber($cooperado->getTaxNumber())
            ->insereLiquidDiscounts()
            ->aplicaAdiantamentos()
            ->setItem(
                code: 'bruto',
                name: 'Bruto produção',
                price: $values->getBruto()
            )
            ->setTaxes()
            ->coletaInvoiceNaoPago();
        if (strlen($cooperado->getTaxNumber()) <= 11) {
            if ($this->dates->getDataPagamento()->format('m') === '12') {
                $this->setItem(
                    code: 'frra',
                    name: 'FRRA',
                    description: sprintf('Referente ao ano/mês: %s', $this->dates->getInicio()->format('Y-m')),
                    price: $values->getFrra()
                );
            } else {
                $this->setNote('FRRA', $this->numberFormatter->format($values->getFrra()));
            }
            $this
                ->setItem(
                    code: 'Auxílio',
                    name: 'Ajuda de custo',
                    price: $values->getAuxilio()
                );
        }
        if ($this->getDueAt()->format('Y-m-d H:i:s') < $this->getIssuedAt()) {
            $this->setIssuedAt($this->getDueAt()->format('Y-m-d H:i:s'));
        }
        return $this;
    }

    private function insereLiquidDiscounts(): self
    {
        if (empty($this->liquidDiscounts)) {
            $totalLiquidDiscount = $this->values->getLiquidDiscount();
            if ($totalLiquidDiscount) {
                $this->setItem(
                    itemId: $this->itemsIds['desconto'],
                    name: 'Desconto líquido',
                    price: -$totalLiquidDiscount,
                    order: 10
                );
            }
            return $this;
        }

        foreach ($this->liquidDiscounts as $discount) {
            $description = $this->buildLiquidDiscountDescription($discount);
            $this->setItem(
                itemId: $this->itemsIds['desconto'],
                name: $this->getLiquidDiscountItemName($discount),
                description: $description,
                price: -$discount['amount'],
                order: 10
            );
        }
        return $this;
    }

    /**
     * @param array{category_id: int, category_name: string, amount: float, document_number: string, due_at: ?string} $discount
     */
    private function getLiquidDiscountItemName(array $discount): string
    {
        if ($discount['category_name'] === '') {
            return 'Desconto líquido';
        }

        $parts = array_values(array_filter(
            array_map('trim', explode('>', $discount['category_name'])),
            fn (string $part): bool => $part !== ''
        ));
        $itemName = array_pop($parts);
        if (!is_string($itemName) || $itemName === '') {
            return 'Desconto líquido';
        }

        return $itemName;
    }

    /**
     * @param array{category_id: int, category_name: string, amount: float, document_number: string, due_at: ?string} $discount
     */
    private function buildLiquidDiscountDescription(array $discount): string
    {
        $parts = [];
        if ($discount['category_name'] !== '') {
            $parts[] = 'Categoria: ' . $discount['category_name'];
        } else {
            $parts[] = 'Categoria ID: ' . $discount['category_id'];
        }
        if ($discount['document_number'] !== '') {
            $parts[] = 'Número: ' . $discount['document_number'];
        }
        if ($discount['due_at']) {
            $dueAt = new DateTime($discount['due_at']);
            $parts[] = 'Data: ' . $dueAt->format('Y-m-d');
        }
        return implode(', ', $parts);
    }

    public function atualizaAdiantamentos(): self
    {
        $taxNumber = $this->getCooperado()->getTaxNumber();

        $select = $this->entityManager->getConnection()->createQueryBuilder();
        $select->select('i.amount')
            ->addSelect('i.document_number')
            ->addSelect('i.due_at')
            ->from('invoices', 'i')
            ->where("i.type = 'bill'")
            ->andWhere("JSON_UNQUOTE(JSON_EXTRACT(i.metadata, '$.status')) = 'paid'")
            ->andWhere($select->expr()->eq('i.category_id', $select->createNamedParameter((int) getenv('AKAUNTING_ADIANTAMENTO_CATEGORY_ID'), ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('i.tax_number', $select->createNamedParameter($taxNumber)))
            ->andWhere($select->expr()->gte('i.transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))));

        $stmt = $select->executeQuery();
        while ($row = $stmt->fetchAssociative()) {
            $this->values->setAdiantamento(array_merge(
                $this->values->getAdiantamento(),
                [$row]
            ));
        }
        return $this;
    }

    private function aplicaAdiantamentos(): self
    {
        foreach ($this->values->getAdiantamento() as $adiantamento) {
            $dueAt = new DateTime($adiantamento['due_at']);
            $this->setItem(
                itemId: $this->itemsIds['desconto'],
                name: 'Adiantamento',
                description: sprintf('Número: %s, data: %s', $adiantamento['document_number'], $dueAt->format('Y-m-d')),
                price: abs($adiantamento['amount']) * -1,
                order: 20
            );
        }
        return $this;
    }
}
