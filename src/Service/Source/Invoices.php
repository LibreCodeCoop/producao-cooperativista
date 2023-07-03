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
use Exception;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\DB\Entity\Invoices as InvoicesEntity;
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
        'Arquivar' => 'archive',
    ];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {
        $this->type = 'invoice';
        $this->companyId = (int) $_ENV['AKAUNTING_COMPANY_ID'];
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
        $list = $this->getDataList('/api/documents', [
            'company_id' => $this->getCompanyId(),
            'search' => implode(' ', $search),
        ]);
        foreach ($list as $row) {
            $invoice = $this->fromArray($row);
            $this->invoices[$this->getType()][] = $invoice;
        }
        return $this->invoices[$this->getType()] ?? [];
    }

    public function fromArray(array $array): InvoicesEntity
    {
        $array = $this->parseNotes($array);
        $array = $this->defineTransactionOfMonth($array);
        $array = $this->defineCustomerReference($array);
        $array = $this->convertFields($array);
        $invoice = $this->db->getEntityManager()->find(\ProducaoCooperativista\DB\Entity\Invoices::class, $array['id']);
        if (!$invoice) {
            $invoice = new InvoicesEntity();
        }
        $invoice->fromArray($array);
        return $invoice;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->invoices as $list) {
            foreach ($list as $row) {
                $this->saveRow($row);
            }
        }
        return $this;
    }

    public function saveRow(InvoicesEntity $invoice): self
    {
        $em = $this->db->getEntityManager();
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

    private function parseNotes(array $row): array
    {
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

    private function convertFields(array $row): array
    {
        $row['nfse'] = !empty($row['nfse']) ? (int) $row['nfse'] : null;
        $row['category_name'] = $row['category']['name'];
        $row['category_type'] = $row['category']['type'];
        $row['contact_name'] = $row['contact']['name'];
        $row['contact_reference'] = $row['contact']['reference'];
        $row['contact_type'] = $row['contact']['type'];
        $row['tax_number'] = $row['contact']['tax_number'] ?? $row['contact_tax_number'];
        $row['archive'] = strtolower($row['archive'] ?? 'não') === 'sim' ? 1 : 0;
        $row['metadata'] = $row;
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
