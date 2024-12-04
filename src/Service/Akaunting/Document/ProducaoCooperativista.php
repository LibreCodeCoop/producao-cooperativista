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

namespace ProducaoCooperativista\Service\Akaunting\Document;

use DateTime;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use UnexpectedValueException;

class ProducaoCooperativista extends ADocument
{
    protected string $whoami = 'PDC';
    public function save(): self
    {
        $this->somaItensExtras();
        $this->populateProducaoCooperativistaWithDefault();
        parent::save();
        $this->getCooperado()
            ->getInssIrpf()
            ->saveFromDocument($this);
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

    public function updateHealthInsurance(): self
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('metadata->>"$.notes" as notes')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) getenv('AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'), ParameterType::INTEGER)))
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))));
        $result = $select->executeQuery();
        $text = $result->fetchOne();
        if (!$text) {
            return $this;
        }
        $return = [];
        if (empty($text)) {
            return $return;
        }

        $explodedText = explode("\n", $text);
        $pattern = '/^Cooperado: .*CPF: (?<CPF>\d+)[,;]? Valor: (R\$ ?)?(?<value>.*)$/i';
        foreach ($explodedText as $row) {
            if (!preg_match($pattern, $row, $matches)) {
                continue;
            }
            if ($matches['CPF'] === $this->getCooperado()->getTaxNumber()) {
                $value = str_replace('.', '', $matches['value']);
                $value = str_replace(',', '.', $value);
                $value = (float) $value;
                $this->values->setHealthInsurance($value);
            }
        }
        return $this;
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
            ->insereHealthInsurance()
            ->aplicaAdiantamentos()
            ->setItem(
                code: 'Auxílio',
                name: 'Ajuda de custo',
                price: $values->getAuxilio()
            )
            ->setItem(
                code: 'bruto',
                name: 'Bruto produção',
                price: $values->getBruto()
            )
            ->setTaxes()
            ->coletaInvoiceNaoPago();
        if ($this->dates->getDataPagamento()->format('m') === '12') {
            $this->setItem(
                code: 'frra',
                name: 'FRRA',
                price: $values->getFrra()
            );
        } else {
            $this->setNote('FRRA', $this->numberFormatter->format($values->getFrra()));
        }
        if ($this->getDueAt()->format('Y-m-d H:i:s') < $this->getIssuedAt()) {
            $this->setIssuedAt($this->getDueAt()->format('Y-m-d H:i:s'));
        }
        return $this;
    }

    private function insereHealthInsurance(): self
    {
        $healthInsurance = $this->values->getHealthInsurance();

        if ($healthInsurance) {
            $this->setItem(
                itemId: $this->itemsIds['Plano'],
                name: 'Plano de saúde',
                price: -$healthInsurance,
                order: 10
            );
        }
        return $this;
    }

    public function atualizaAdiantamentos(): self
    {
        $taxNumber = $this->getContactTaxNumber();

        $select = new QueryBuilder($this->db->getConnection());
        $select->select('amount')
            ->addSelect('document_number')
            ->addSelect('due_at')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("metadata->>'$.status' = 'paid'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) getenv('AKAUNTING_ADIANTAMENTO_CATEGORY_ID'), ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('tax_number', $select->createNamedParameter($taxNumber)))
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))));

        $result = $select->executeQuery();
        while ($row = $result->fetchAssociative()) {
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
