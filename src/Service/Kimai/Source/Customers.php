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

namespace App\Service\Kimai\Source;

use App\Entity\Producao\Customer;
use App\Provider\Kimai;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use App\Service\Import\KimaiDetailsLoader;

class Customers
{
    use Kimai;
    private array $customers = [];
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ExistingEntityIndex $entityIndex,
        private BatchPersistence $batchPersistence,
        private KimaiDetailsLoader $detailsLoader,
    ) {
    }

    public function updateDatabase(): self
    {
        $this->logger->info('Baixando dados de customers');
        $this->getFromApi();
        $this->saveList();
        $this->logger->info('Dados de customers salvos com sucesso. Total: {total}', [
            'total' => count($this->customers),
        ]);
        return $this;
    }

    public function getFromApi(): array
    {
        if ($this->customers) {
            return $this->customers;
        }
        $this->customers = $this->detailsLoader->enrich(
            $this->doRequestKimai('/customers'),
            'customers',
            ['timeBudget', 'vatId']
        );
        foreach ($this->customers as &$customer) {
            $customer['time_budget'] = $customer['timeBudget'];
            $customer['vat_id'] = $customer['vatId'];
        }
        unset($customer);
        return $this->customers;
    }

    public function saveList(): self
    {
        $existing = $this->entityIndex->byId(
            $this->entityManager,
            Customer::class,
            array_column($this->customers, 'id')
        );
        $entities = [];
        foreach ($this->customers as $row) {
            $customer = $existing[$row['id']] ?? new Customer();
            if (!isset($existing[$row['id']])) {
                $customer->setId($row['id']);
            }
            $customer
                ->setName($row['name'])
                ->setNumber($row['number'])
                ->setComment($row['comment'])
                ->setVisible($row['visible'])
                ->setBillable($row['billable'])
                ->setCurrency($row['currency'])
                ->setColor($row['color'])
                ->setVatId($row['vat_id'])
                ->setTimeBudget($row['time_budget']);
            $entities[] = $customer;
        }
        $this->batchPersistence->save($this->entityManager, $entities);
        return $this;
    }
}
