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

use DateTime;
use Doctrine\DBAL\ParameterType;
use Exception;
use App\Entity\Producao\Transactions as EntityTransactions;
use App\Helper\MagicGetterSetterTrait;
use App\Provider\Akaunting\Dataset;
use App\Provider\Akaunting\ParseText;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * @method self setCategoryId(int $value)
 * @method int getCategoryId();
 * @method self setCompanyId(int $value)
 * @method int getCompanyId();
 * @method self setDate(DateTime $value)
 */
class Transactions
{
    use MagicGetterSetterTrait;
    private ?DateTime $date;
    private int $companyId;
    private ?int $categoryId = null;
    /** @var EntityTransactions[] */
    private array $list = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ParseText $parseText,
        private Dataset $dataset,
    ) {
        $this->companyId = (int) getenv('AKAUNTING_COMPANY_ID');
    }

    /**
     * @return EntityTransactions[]
     */
    public function getList(): array
    {
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->logger->info('Baixando dados de transactions');
        $begin = $this->getDate()
            ->modify('first day of this month');
        $today = new DateTime();
        if ($begin->format('m') <= $today->format('m')) {
            $end = $today->modify('last day of this month');
        } else {
            $end = clone $begin;
            $end = $end->modify('last day of this month');
        }

        $search = [];
        if ($this->getCategoryId()) {
            $search[] = 'category_id:' . $this->getCategoryId();
        }
        $search[] = 'paid_at>=' . $begin->format('Y-m-d');
        $search[] = 'paid_at<=' . $end->format('Y-m-d');
        $list = $this->dataset->list('/api/transactions', [
            'company_id' => $this->getCompanyId(),
            'search' => implode(' ', $search),
        ]);
        foreach ($list as $row) {
            $transaction = $this->fromArray($row);
            $this->list[] = $transaction;
        }
        $this->logger->info('Dados de transactions salvos com sucesso. Total: {total}', [
            'total' => count($this->list),
        ]);
        return $this->list;
    }

    public function fromArray(array $array): EntityTransactions
    {
        $array = $this->getDataFromAssociatedDocument($array);
        $array = array_merge($array, $this->parseText->do((string) $array['description']));
        $array = $this->defineTransactionOfMonth($array);
        $array = $this->defineCustomerReference($array);
        $array = $this->convertFields($array);
        $entity = $this->entityManager->find(EntityTransactions::class, $array['id']);
        if (!$entity instanceof EntityTransactions) {
            $entity = new EntityTransactions();
        }
        $entity->fromArray($array);
        return $entity;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->list as $row) {
            $this->saveRow($row);
        }
        return $this;
    }

    public function saveRow(EntityTransactions $invoice): self
    {
        $em = $this->entityManager;
        $em->persist($invoice);
        $em->flush();
        return $this;
    }

    private function getDate(): DateTime
    {
        if (!$this->date instanceof DateTime) {
            throw new Exception('You need to set the start date of month that you want to get transactions');
        }
        return $this->date;
    }

    private function getDataFromAssociatedDocument(array $item): array
    {
        $item['invoice_notes'] = null;
        if (!$item['document_id']) {
            return $item;
        }
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $qb->select('i.id')
            ->addSelect('i.customer_reference')
            ->addSelect("i.metadata as metadata")
            ->from('invoices', 'i')
            ->where($qb->expr()->eq('i.id', $qb->createNamedParameter($item['document_id'], ParameterType::INTEGER)))
            ->andWhere('i.customer_reference IS NOT NULL');
        $row = $qb->executeQuery()->fetchAssociative();
        if (!$row) {
            return $item;
        }
        if (json_validate($row['metadata'])) {
            $metadata = json_decode($row['metadata'], true);
            if (isset($metadata['notes']) && is_string($metadata['notes'])) {
                $item = array_merge($item, $this->parseText->do($metadata['notes']));
            }
        }
        if (empty($item['customer_reference'])) {
            $item['customer_reference'] = $row['customer_reference'];
        }
        return $item;
    }

    private function defineTransactionOfMonth(array $row): array
    {
        if (!array_key_exists('transaction_of_month', $row)) {
            $date = $this->convertDate($row['paid_at']);
            $row['transaction_of_month'] = $date->format('Y-m');
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
        $row['category_id'] = $row['category_id'];
        $row['category_name'] = $row['category']['name'];
        $row['category_type'] = $row['category']['type'];
        $row['contact_name'] = $row['contact']['name'];
        $row['contact_reference'] = $row['contact']['reference'];
        $row['contact_type'] = $row['contact']['type'];
        $row['customer_reference'] = $row['customer_reference'];
        $row['metadata'] = $row;
        $row['nfse'] = !empty($row['nfse']) ? (int) $row['nfse'] : null;
        $row['tax_number'] = $row['contact']['tax_number'] ?? $row['customer_reference'];
        return $row;
    }

    private function convertDate(string $date): DateTime
    {
        $date = preg_replace('/-\d{2}:\d{2}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $date;
    }
}
