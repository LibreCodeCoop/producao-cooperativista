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

namespace ProducaoCooperativista\Service\Akaunting\Source;

use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\DB\Entity\Taxes as EntityTaxes;
use ProducaoCooperativista\Helper\MagicGetterSetterTrait;
use ProducaoCooperativista\Provider\Akaunting\Dataset;
use Psr\Log\LoggerInterface;

/**
 * @method self setCompanyId(int $value)
 * @method int getCompanyId()
 * @method self setName(string $value)
 * @method string getName()
 * @method self setRate(float $value)
 * @method float getRate()
 * @method self setEnabled(int $value)
 * @method int getEnabled()
 * @method self setMetadata(string $value)
 * @method string getMetadata()
 */
class Taxes
{
    use MagicGetterSetterTrait;
    private int $companyId;
    /** @var EntityTaxes[] */
    private array $list = [];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger,
        private Dataset $dataset,
    ) {
        $this->companyId = (int) $_ENV['AKAUNTING_COMPANY_ID'];
    }

    public function getList(): array
    {
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->logger->debug('Baixando dados de impostos');

        $list = $this->dataset->list('/api/taxes', [
            'company_id' => $this->getCompanyId(),
        ]);
        foreach ($list as $row) {
            $invoice = $this->fromArray($row);
            $this->list[] = $invoice;
        }
        return $this->list ?? [];
    }

    public function fromArray(array $array): EntityTaxes
    {
        $entity = $this->db->getEntityManager()->find(EntityTaxes::class, $array['id']);
        $array = $this->convertFields($array);
        if (!$entity instanceof EntityTaxes) {
            $entity = new EntityTaxes();
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

    public function saveRow(EntityTaxes $taxes): self
    {
        $em = $this->db->getEntityManager();
        $em->persist($taxes);
        $em->flush();
        return $this;
    }

    private function convertFields(array $row): array
    {
        $row['metadata'] = $row;
        return $row;
    }
}
