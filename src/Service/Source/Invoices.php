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
use Exception;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Service\Source\Provider\Akaunting;
use Psr\Log\LoggerInterface;

/**
 * @method Invoices setCompanyId(int $value)
 * @method int getCompanyId();
 * @method Invoices setDate(DateTime $value)
 * @method Invoices setType(string $value)
 * @method string getType()
 */
class Invoices
{
    use MagicGetterSetterTrait;
    use Akaunting;
    private ?DateTime $date;
    private string $type;
    private int $companyId;
    private array $invoices = [];
    private array $dictionaryParamsAtNotes = [
        'NFSe' => 'nfse',
        'Transação do mês' => 'transaction_of_month',
        'CNPJ cliente' => 'customer',
        'Setor' => 'sector',
        'setor' => 'sector',
    ];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
        $this->type = 'invoice';
        $this->companyId = (int) $_ENV['AKAUNTING_COMPANY_ID'];
    }

    private function getDate(): DateTime
    {
        if (!$this->date instanceof DateTime) {
            throw new Exception('You need to set the start date of month that you want to get invoices');
        }
        return $this->date;
    }

    public function updateDatabase(): void
    {
        $this->logger->debug('Baixando dados de invoices');
        $this->saveList();
    }

    public function getList(): array
    {
        if (isset($this->invoices[$this->getType()])) {
            return $this->invoices[$this->getType()];
        }
        $this->logger->debug('Baixando dados de invoices');

        $begin = $this->getDate()
            ->modify('first day of this month');
        $end = clone $begin;
        /**
         * Is necessary to get from next month because the payment of invoices will be registered at the next month of
         * payment and this data is used to register the "produção cooperativista"
         */
        $end = $end->modify('last day of next month');

        $search = [];
        $search[] = 'type:' . $this->getType();
        $search[] = 'invoiced_at>=' . $begin->format('Y-m-d');
        $search[] = 'invoiced_at<=' . $end->format('Y-m-d');
        $this->invoices[$this->getType()] = $this->getDataList('/api/documents', [
            'company_id' => $this->getCompanyId(),
            'search' => implode(' ', $search),
        ]);
        $this->parseDocuments();
        return $this->invoices[$this->getType()];
    }

    private function parseDocuments(): void
    {
        array_walk($this->invoices[$this->getType()], function(&$row) {
            $row = $this->parseNotes($row);
            $row = $this->defineTransactionOfMonth($row);
            $row = $this->defineCustomerReference($row);
        });
    }

    private function parseNotes(array $row): array {
        if (empty($row['notes'])) {
            return $row;
        }
        $explodedNotes = explode("\n", $row['notes']);
        $pattern = '/^(?<paramName>' . implode('|', array_keys($this->dictionaryParamsAtNotes)) . '): (?<paramValue>.*)$/i';
        foreach ($explodedNotes as $rowOfNotes) {
            if (!preg_match($pattern, $rowOfNotes, $matches)) {
                continue;
            }
            $row[$this->dictionaryParamsAtNotes[$matches['paramName']]] = strtolower(trim($matches['paramValue']));
        }
        return $row;
    }

    private function defineTransactionOfMonth(array $row): array
    {
        if (!array_key_exists('transaction_of_month', $row)) {
            $date = $this->convertDate($row['issued_at']);
            $row['transaction_of_month'] = $date->format('Y-m');
        }
        return $row;
    }

    private function defineCustomerReference(array $row): array
    {
        if (!empty($row['contact']['reference'])) {
            $row['customer_reference'] = $row['contact']['reference'];
        } elseif (!empty($row['contact']['tax_number'])) {
            $row['customer_reference'] = $row['contact']['tax_number'];
        } elseif (!empty($row['customer'])) {
            $row['customer_reference'] = $row['customer'];
            if (!empty($row['sector'])) {
                $row['customer_reference'] = $row['customer_reference'] . '|' . strtolower($row['sector']);
            }
        } else {
            $row['customer_reference'] = null;
        }
        return $row;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->invoices as $type => $list) {
            foreach ($list as $row) {
                $this->saveRow($row);
            }
        }
        return $this;
    }

    public function saveRow(array $row): self
    {
        $row = $this->parseNotes($row);
        $row = $this->defineTransactionOfMonth($row);
        $row = $this->defineCustomerReference($row);
        try {
            $insert = new QueryBuilder($this->db->getConnection());
            $insert->insert('invoices')
                ->values([
                    'id' => $insert->createNamedParameter($row['id'], ParameterType::INTEGER),
                    'type' => $insert->createNamedParameter($row['type']),
                    'issued_at' => $insert->createNamedParameter($this->convertDate($row['issued_at']), Types::DATE_MUTABLE),
                    'due_at' => $insert->createNamedParameter($this->convertDate($row['due_at']), Types::DATE_MUTABLE),
                    'transaction_of_month' => $insert->createNamedParameter($row['transaction_of_month']),
                    'amount' => $insert->createNamedParameter($row['amount'], Types::FLOAT),
                    'currency_code' => $insert->createNamedParameter($row['currency_code']),
                    'nfse' => $insert->createNamedParameter($row['nfse'] ?? null),
                    'document_number' => $insert->createNamedParameter($row['document_number']),
                    'tax_number' => $insert->createNamedParameter($row['contact']['tax_number'] ?? $row['contact_tax_number']),
                    'customer_reference' => $insert->createNamedParameter($row['customer_reference']),
                    'contact_id' => $insert->createNamedParameter($row['contact_id'], ParameterType::INTEGER),
                    'contact_reference' => $insert->createNamedParameter($row['contact']['reference']),
                    'contact_name' => $insert->createNamedParameter($row['contact']['name']),
                    'contact_type' => $insert->createNamedParameter($row['contact']['type']),
                    'category_id' => $insert->createNamedParameter($row['category_id'], ParameterType::INTEGER),
                    'category_name' => $insert->createNamedParameter($row['category']['name']),
                    'category_type' => $insert->createNamedParameter($row['category']['type']),
                    'metadata' => $insert->createNamedParameter(json_encode($row)),
                ])
                ->executeStatement();
        } catch (\Throwable $th) {
            $update = new QueryBuilder($this->db->getConnection());
            $update->update('invoices')
                ->set('type', $update->createNamedParameter($row['type']))
                ->set('issued_at', $update->createNamedParameter($this->convertDate($row['issued_at']), Types::DATE_MUTABLE))
                ->set('due_at', $update->createNamedParameter($this->convertDate($row['due_at']), Types::DATE_MUTABLE))
                ->set('transaction_of_month', $update->createNamedParameter($row['transaction_of_month']))
                ->set('amount', $update->createNamedParameter($row['amount'], Types::FLOAT))
                ->set('currency_code', $update->createNamedParameter($row['currency_code']))
                ->set('nfse', $update->createNamedParameter($row['nfse'] ?? null))
                ->set('document_number', $update->createNamedParameter($row['document_number']))
                ->set('tax_number', $update->createNamedParameter($row['contact']['tax_number'] ?? $row['contact_tax_number']))
                ->set('customer_reference', $update->createNamedParameter($row['customer_reference']))
                ->set('contact_id', $update->createNamedParameter($row['contact_id'], ParameterType::INTEGER))
                ->set('contact_reference', $update->createNamedParameter($row['contact']['reference']))
                ->set('contact_name', $update->createNamedParameter($row['contact']['name']))
                ->set('contact_type', $update->createNamedParameter($row['contact']['type']))
                ->set('category_id', $update->createNamedParameter($row['category_id'], ParameterType::INTEGER))
                ->set('category_name', $update->createNamedParameter($row['category']['name']))
                ->set('category_type', $update->createNamedParameter($row['category']['type']))
                ->set('metadata', $update->createNamedParameter(json_encode($row)))
                ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'], ParameterType::INTEGER)))
                ->executeStatement();
        }
        return $this;
    }

    private function convertDate($date): ?DateTime
    {
        if (!$date) {
            return null;
        }
        $date = preg_replace('/[+-]\d{2}:\d{2}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $date);
        return $date;
    }
}