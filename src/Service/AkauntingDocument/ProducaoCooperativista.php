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

namespace ProducaoCooperativista\Service\AkauntingDocument;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

class ProducaoCooperativista extends AAkauntingDocument
{
    public function save(): self
    {
        $this->populateProducaoCooperativistaWithDefault();
        parent::save();
        $this->getCooperado()
            ->getInssIrpf()
            ->saveFromDocument($this);
        return $this;
    }

    public function updateHealthInsurance(): self
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('metadata->>"$.notes" as notes')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_PLANO_DE_SAUDE_CATEGORY_ID'], ParameterType::INTEGER)))
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

    private function populateProducaoCooperativistaWithDefault(): self
    {
        $cooperado = $this->getCooperado();
        $values = $this->getValues();
        $this
            ->setType('bill')
            ->setCategoryId((int) $_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'])
            ->setDocumentNumber(
                'PDC_' .
                $cooperado->getTaxNumber() .
                '-' .
                $this->dates->getInicio()->format('Y-m')
            )
            ->setSearch('type:bill')
            ->setStatus('draft')
            ->setIssuedAt($this->dates->getDataProcessamento()->format('Y-m-d H:i:s'))
            ->setDueAt($this->dates->getDataPagamento()->format('Y-m-d H:i:s'))
            ->setCurrencyCode('BRL')
            ->setNote('Data geração', $this->dates->getDataProcessamento()->format('Y-m-d'))
            ->setNote('Produção realizada no mês', $this->dates->getInicio()->format('Y-m'))
            ->setNote('Notas dos clientes pagas no mês', $this->dates->getInicioProximoMes()->format('Y-m'))
            ->setNote('Dia útil padrão de pagamento', sprintf('%sº', $this->dates->getPagamentoNoDiaUtil()))
            ->setNote('Previsão de pagamento no dia', $this->dates->getDataPagamento()->format('Y-m-d'))
            ->setNote('Base de cálculo', $this->numberFormatter->format($values->getBaseProducao()))
            ->setNote('FRRA', $this->numberFormatter->format($values->getFrra()))
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
            ->coletaNaoPago();
        return $this;
    }

    private function insereHealthInsurance(): self
    {
        $healthInsurance = $this->values->getHealthInsurance();

        if ($healthInsurance) {
            $this->setItem(
                itemId: $this->itemsIds['Plano'],
                name: 'Plano de saúde',
                price: - $healthInsurance,
                order: 10
            );
        }
        return $this;
    }

    private function aplicaAdiantamentos(): self
    {
        $taxNumber = $this->getContactTaxNumber();

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
            ->andWhere($select->expr()->gte('transaction_of_month', $select->createNamedParameter($this->dates->getInicioProximoMes()->format('Y-m'))));

        $result = $select->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $this->setItem(
                itemId: $this->itemsIds['desconto'],
                name: 'Adiantamento',
                description: sprintf('Número: %s, data: %s', $row['document_number'], $row['due_at']),
                price: -$row['amount'],
                order: 20
            );
        }
        return $this;
    }

    private function coletaNaoPago(): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->addSelect('tax_number')
            ->addSelect('document_number')
            ->from('invoices')
            ->where("type = 'bill'")
            ->andWhere("category_type = 'expense'")
            ->andWhere($select->expr()->eq('category_id', $select->createNamedParameter((int) $_ENV['AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID'], ParameterType::INTEGER)))
            ->andWhere($select->expr()->in('tax_number', $select->createNamedParameter($this->getContactTaxNumber(), ParameterType::INTEGER)))
            ->andWhere($select->expr()->eq('document_number', $select->createNamedParameter(
                'PDC_' .
                $this->getCooperado()->getTaxNumber() .
                '-' .
                $this->dates->getInicio()->format('Y-m')
            )));

        $result = $select->executeQuery();
        $row = $result->fetchAssociative();
        if (!$row) {
            return;
        }
        $this->setId($row['id']);
    }
}
