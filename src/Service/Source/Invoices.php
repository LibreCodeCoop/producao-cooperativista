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

class Invoices
{
    use Akaunting;

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
    }

    public function updateDatabase(DateTime $data, string $type = 'invoice'): void
    {
        $this->logger->debug('Baixando dados de invoices');
        $list = $this->getFromApi($data, type: $type);
        $this->saveToDatabase($list, $data);
    }

    public function getFromApi(DateTime $date, int $companyId = 1, string $type = 'invoice'): array
    {
        $invoices = $this->getInvoices($date, $companyId, $type);
        return $invoices;
    }

    private function getInvoices(DateTime $date, int $companyId = 1, string $type = 'invoice'): array
    {
        $begin = $date
            ->modify('first day of this month');
        $end = clone $begin;
        $end = $end->modify('last day of this month');

        $search = [];
        $search[] = 'type:' . $type;
        $search[] = 'invoiced_at>=' . $begin->format('Y-m-d');
        $search[] = 'invoiced_at<=' . $end->format('Y-m-d');
        $documents = $this->doRequestAkaunting('/api/documents', [
            'company_id' => $companyId,
            'search' => implode(' ', $search),
        ]);
        return $documents;

    }

    public function saveToDatabase(array $list, DateTime $date): void
    {
        $begin = $date
            ->modify('first day of this month');
        $end = clone $begin;
        $end = $end->modify('last day of this month');

        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('invoices')
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
                'issued_at',
                $select->createNamedParameter($begin, Types::DATE_MUTABLE)
            )
        );
        $select->andWhere(
            $select->expr()->lte(
                'issued_at',
                $select->createNamedParameter($end, Types::DATE_MUTABLE)
            )
        );
        $result = $select->executeQuery();
        $exists = [];
        while ($row = $result->fetchAssociative()) {
            $exists[] = $row['id'];
        }
        $insert = new QueryBuilder($this->db->getConnection());
        foreach ($list as $row) {
            if (in_array($row['id'], $exists)) {
                $update = new QueryBuilder($this->db->getConnection());
                $update->update('invoices')
                    ->set('type', $update->createNamedParameter($row['type']))
                    ->set('issued_at', $update->createNamedParameter($this->convertDate($row['issued_at']), Types::DATE_MUTABLE))
                    ->set('due_at', $update->createNamedParameter($this->convertDate($row['due_at']), Types::DATE_MUTABLE))
                    ->set('amount', $update->createNamedParameter($row['amount'], Types::FLOAT))
                    ->set('currency_code', $update->createNamedParameter($row['currency_code']))
                    ->set('document_number', $update->createNamedParameter($row['document_number']))
                    ->set('tax_number', $update->createNamedParameter($row['contact']['tax_number']))
                    ->set('contact_id', $update->createNamedParameter($row['contact_id'], ParameterType::INTEGER))
                    ->set('contact_reference', $update->createNamedParameter($row['contact']['reference']
                        ?? $row['contact']['tax_number']
                        ?? $row['document_number']))
                    ->set('contact_name', $update->createNamedParameter($row['contact']['name']))
                    ->set('contact_type', $update->createNamedParameter($row['contact']['type']))
                    ->set('metadata', $update->createNamedParameter(json_encode($row)))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], ParameterType::INTEGER)))
                    ->executeStatement();
                continue;
            }
            $insert->insert('invoices')
                ->values([
                    'id' => $insert->createNamedParameter($row['id'], ParameterType::INTEGER),
                    'type' => $insert->createNamedParameter($row['type']),
                    'issued_at' => $insert->createNamedParameter($this->convertDate($row['issued_at']), Types::DATE_MUTABLE),
                    'due_at' => $insert->createNamedParameter($this->convertDate($row['due_at']), Types::DATE_MUTABLE),
                    'amount' => $insert->createNamedParameter($row['amount'], Types::FLOAT),
                    'currency_code' => $insert->createNamedParameter($row['currency_code']),
                    'document_number' => $insert->createNamedParameter($row['document_number']),
                    'tax_number' => $insert->createNamedParameter($row['contact']['tax_number']),
                    'contact_id' => $insert->createNamedParameter($row['contact_id'], ParameterType::INTEGER),
                    'contact_reference' => $insert->createNamedParameter($row['contact']['reference']
                        ?? $row['contact']['tax_number']
                        ?? $row['document_number']),
                    'contact_name' => $insert->createNamedParameter($row['contact']['name']),
                    'contact_type' => $insert->createNamedParameter($row['contact']['type']),
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