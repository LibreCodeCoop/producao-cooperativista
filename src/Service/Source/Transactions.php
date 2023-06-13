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

namespace ProducaoCooperativista\Service\Source;

use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Service\Source\Provider\Akaunting;
use Psr\Log\LoggerInterface;

class Transactions
{
    use Akaunting;
    private array $dictionaryParamsAtDescription = [
        'NFSe' => 'reference',
        'Transação do mês' => 'transaction_of_month',
    ];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
    }

    public function updateDatabase(DateTime $data): void
    {
        $this->logger->debug('Baixando dados de transactions');
        $list = $this->getFromApi($data);
        $this->saveToDatabase($list, $data);
    }

    public function getFromApi(DateTime $date, int $companyId = 1, ?int $categoryId = null): array
    {
        $transactions = $this->getTransactions($date, $companyId, $categoryId);
        return $transactions;
    }

    private function getTransactions(DateTime $date, int $companyId, ?int $categoryId): array
    {
        $begin = $date
            ->modify('first day of this month');
        $end = clone $begin;
        $end = $end->modify('last day of this month');

        $search = [];
        if ($categoryId) {
            $search[] = 'category_id:' . $categoryId;
        }
        $search[] = 'paid_at>=' . $begin->format('Y-m-d');
        $search[] = 'paid_at<=' . $end->format('Y-m-d');
        $transactions = $this->doRequestAkaunting('/api/transactions', [
            'company_id' => $companyId,
            'search' => implode(' ', $search),
        ]);
        $transactions = $this->parseTransactions($transactions);
        return $transactions;
    }

    private function parseTransactions(array $list): array
    {
        array_walk($list, function(&$row) {
            $row = $this->parseDescription($row);
            $row = $this->defineTransactionOfMonth($row);
        });
        return $list;
    }

    private function parseDescription(array $row): array {
        if (empty($row['description'])) {
            return $row;
        }
        $explodedDescription = explode("\n", $row['description']);
        $pattern = '/^(?<paramName>NFSe|Transação do mês): (?<paramValue>.*)$/';
        foreach ($explodedDescription as $rowOfDescription) {
            if (!preg_match($pattern, $rowOfDescription, $matches)) {
                continue;
            }
            $row[$this->dictionaryParamsAtDescription[$matches['paramName']]] = trim($matches['paramValue']);
        }
        return $row;
    }

    private function defineTransactionOfMonth(array $row): array
    {
        if (!array_key_exists('transaction_of_month', $row)) {
            $date = $this->convertDate($row['paid_at']);
            $row['transaction_of_month'] = $date->format('Y-m');
        }
        return $row;
    }

    public function saveToDatabase(array $list, DateTime $date, ?string $category = null): void
    {
        $begin = $date
            ->modify('first day of this month');
        $end = clone $begin;
        $end = $end->modify('last day of this month');

        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('transactions')
            ->where(
                $select->expr()->in(
                    'id',
                    $select->createNamedParameter(
                        array_column($list, 'id'),
                        ArrayParameterType::INTEGER
                    )
                )
            );
        $select->andWhere(
            $select->expr()->gte(
                'paid_at',
                $select->createNamedParameter($begin, Types::DATE_MUTABLE)
            )
        );
        $select->andWhere(
            $select->expr()->lte(
                'paid_at',
                $select->createNamedParameter($end, Types::DATE_MUTABLE)
            )
        );
        if ($category) {
            $select->andWhere(
                $select->expr()->eq(
                    'category_id',
                    $select->createNamedParameter($category)
                )
            );
        }
        $result = $select->executeQuery();
        $exists = [];
        while ($row = $result->fetchAssociative()) {
            $exists[] = $row['id'];
        }
        $insert = new QueryBuilder($this->db->getConnection());
        foreach ($list as $row) {
            if (in_array($row['id'], $exists)) {
                $update = new QueryBuilder($this->db->getConnection());
                $update->update('transactions')
                    ->set('type', $update->createNamedParameter($row['type']))
                    ->set('paid_at', $update->createNamedParameter($this->convertDate($row['paid_at']), Types::DATE_MUTABLE))
                    ->set('transaction_of_month', $update->createNamedParameter($row['transaction_of_month']))
                    ->set('amount', $update->createNamedParameter($row['amount'], Types::FLOAT))
                    ->set('currency_code', $update->createNamedParameter($row['currency_code']))
                    ->set('reference', $update->createNamedParameter($row['reference']))
                    ->set('contact_id', $update->createNamedParameter($row['contact_id'], ParameterType::INTEGER))
                    ->set('tax_number', $update->createNamedParameter($row['contact']['tax_number']))
                    ->set('contact_reference', $update->createNamedParameter($row['contact']['reference']
                        ?? $row['contact']['tax_number']
                        ?? $row['reference']))
                    ->set('contact_name', $update->createNamedParameter($row['contact']['name']))
                    ->set('contact_type', $update->createNamedParameter($row['contact']['type']))
                    ->set('category_id', $update->createNamedParameter($row['category_id'], ParameterType::INTEGER))
                    ->set('category_name', $update->createNamedParameter($row['category']['name']))
                    ->set('category_type', $update->createNamedParameter($row['category']['type']))
                    ->set('metadata', $update->createNamedParameter(json_encode($row)))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], ParameterType::INTEGER)))
                    ->executeStatement();
                continue;
            }
            $insert->insert('transactions')
                ->values([
                    'id' => $insert->createNamedParameter($row['id'], ParameterType::INTEGER),
                    'type' => $insert->createNamedParameter($row['type']),
                    'paid_at' => $insert->createNamedParameter($this->convertDate($row['paid_at']), Types::DATE_MUTABLE),
                    'transaction_of_month' => $insert->createNamedParameter($row['transaction_of_month']),
                    'amount' => $insert->createNamedParameter($row['amount'], Types::FLOAT),
                    'currency_code' => $insert->createNamedParameter($row['currency_code']),
                    'contact_id' => $insert->createNamedParameter($row['contact_id'], ParameterType::INTEGER),
                    'reference' => $insert->createNamedParameter($row['reference']),
                    'tax_number' => $insert->createNamedParameter($row['contact']['tax_number']),
                    'contact_reference' => $insert->createNamedParameter($row['contact']['reference']
                        ?? $row['contact']['tax_number']
                        ?? $row['reference']),
                    'contact_name' => $insert->createNamedParameter($row['contact']['name']),
                    'contact_type' => $insert->createNamedParameter($row['contact']['type']),
                    'category_id' => $insert->createNamedParameter($row['category_id'], ParameterType::INTEGER),
                    'category_name' => $insert->createNamedParameter($row['category']['name']),
                    'category_type' => $insert->createNamedParameter($row['category']['type']),
                    'metadata' => $insert->createNamedParameter(json_encode($row)),
                ])
                ->executeStatement();
        }
    }

    private function convertDate($date): ?DateTime
    {
        if (!$date) {
            return null;
        }
        $date = preg_replace('/-\d{2}:\d{2}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $date;
    }
}