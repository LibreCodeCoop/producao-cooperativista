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

namespace App\Service\Akaunting\Source;

use App\Entity\Producao\Invoice;
use DateTime;
use Exception;
use App\Helper\Dates;
use App\Helper\MagicGetterSetterTrait;
use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\ParseText;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * @method self setCompanyId(int $value)
 * @method int getCompanyId();
 * @method self setDate(DateTime $value)
 * @method self setType(string $value)
 * @method string getType()
 */
class Documents
{
    use MagicGetterSetterTrait;
    private ?DateTime $date;
    private string $type;
    private int $companyId;
    /** @var Invoice[][] */
    private array $list = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ParseText $parseText,
        private Dataset $dataset,
        private Dates $dates,
    ) {
        $this->type = 'invoice';
        $this->companyId = (int) getenv('AKAUNTING_COMPANY_ID');
    }

    /**
     * @return Invoice[][]
     */
    public function getList(): array
    {
        if (isset($this->list[$this->getType()])) {
            return $this->list[$this->getType()];
        }
        $this->logger->info('Baixando dados do akaunting. Tipo: {tipo}', [
            'tipo' => $this->getType(),
        ]);

        $begin = (clone $this->getDate())
            ->modify('first day of this month');
        $end = (clone $this->dates->getPrevisaoPagamentoFrra())
            ->modify('last day of this month');

        $search = [];
        $search[] = 'type:' . $this->getType();
        $search[] = 'due_at>=' . $begin->format('Y-m-d');
        $search[] = 'due_at<=' . $end->format('Y-m-d');
        $list = $this->dataset->list('/api/documents', [
            'company_id' => $this->getCompanyId(),
            'search' => implode(' ', $search),
        ]);
        foreach ($list as $row) {
            $invoice = $this->fromArray($row);
            $this->list[$this->getType()][] = $invoice;
        }
        $this->logger->info('Dados de {tipo} salvos com sucesso. Total: {total}', [
            'total' => count($this->list[$this->getType()]),
            'tipo' => $this->getType(),
        ]);
        return $this->list[$this->getType()] ?? [];
    }

    public function fromArray(array $array): Invoice
    {
        $array = array_merge($array, $this->parseText->do((string) $array['notes']));
        $array = $this->defineTransactionOfMonth($array);
        $array = $this->calculateFixedDiscountPercentage($array);
        $array = $this->defineCustomerReference($array);
        $array = $this->convertFields($array);
        $entity = $this->entityManager->find(Invoice::class, $array['id']);
        if (!$entity instanceof Invoice) {
            $entity = new Invoice();
        }
        $entity->fromArray($array);
        return $entity;
    }

    public function setType(string $value): self
    {
        $this->list = [];
        $this->type = $value;
        return $this;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->list as $list) {
            foreach ($list as $row) {
                $this->saveRow($row);
            }
        }
        return $this;
    }

    public function saveRow(Invoice $invoice): self
    {
        $em = $this->entityManager;
        $em->persist($invoice);
        $em->flush();
        return $this;
    }

    private function getDate(): DateTime
    {
        if (!$this->date instanceof DateTime) {
            throw new Exception('You need to set the start date of month that you want to get invoices');
        }
        return $this->date;
    }

    private function defineTransactionOfMonth(array $row): array
    {
        if (!array_key_exists('transaction_of_month', $row)) {
            $date = $this->convertDate($row['due_at']);
            $row['transaction_of_month'] = $date->format('Y-m');
        }
        return $row;
    }

    private function calculateFixedDiscountPercentage(array $row): array
    {
        if (array_key_exists('discount_percentage', $row)) {
            $row['discount_percentage'] = (float) $row['discount_percentage'];
        }
        return $row;
    }

    private function defineCustomerReference(array $row): array
    {
        if (!empty($row['customer_reference'])) {
            return $row;
        }
        if (!empty($row['customer'])) {
            $row['customer_reference'] = $row['customer'];
            if (!empty($row['sector'])) {
                $row['customer_reference'] = $row['customer_reference'] . '|' . strtolower($row['sector']);
            }
        } elseif (!empty($row['contact']['reference'])) {
            $row['customer_reference'] = $row['contact']['reference'];
        } elseif (!empty($row['contact']['tax_number'])) {
            $row['customer_reference'] = $row['contact']['tax_number'];
        } else {
            $row['customer_reference'] = getenv('CNPJ_COMPANY');
        }
        return $row;
    }

    private function convertFields(array $row): array
    {
        $row['archive'] = strtolower($row['archive'] ?? 'não') === 'sim' ? 1 : 0;
        $row['category_name'] = $row['category']['name'];
        $row['category_type'] = $row['category']['type'];
        $row['contact_name'] = $row['contact']['name'];
        $row['contact_reference'] = $row['contact']['reference'];
        $row['contact_type'] = $row['contact']['type'];
        $row['metadata'] = $row;
        $row['nfse'] = !empty($row['nfse']) ? (int) $row['nfse'] : null;
        $row['tax_number'] = $row['contact']['tax_number'] ?? $row['contact_tax_number'];
        return $row;
    }

    private function convertDate(string $date): DateTime
    {
        $date = preg_replace('/[+-]\d{2}:\d{2}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $date;
    }
}
