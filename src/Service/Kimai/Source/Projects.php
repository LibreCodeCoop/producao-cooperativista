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

use App\Entity\Producao\Project;
use DateTime;
use App\Provider\Kimai;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Service\Import\BatchPersistence;
use App\Service\Import\ExistingEntityIndex;
use App\Service\Import\KimaiDetailsLoader;

class Projects
{
    use Kimai;
    private array $list = [];
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private ExistingEntityIndex $entityIndex,
        private BatchPersistence $batchPersistence,
        private KimaiDetailsLoader $detailsLoader,
    ) {
    }

    public function updateDatabase(): void
    {
        $this->logger->info('Baixando dados de projects');
        $this->getFromApi();
        $this->saveList($this->list);
        $this->logger->info('Dados de projetos salvos com sucesso. Total: {total}', [
            'total' => count($this->list),
        ]);
    }

    public function getFromApi(): array
    {
        $this->list = $this->detailsLoader->enrich(
            $this->doRequestKimai('/projects'),
            'projects',
            ['timeBudget']
        );
        foreach ($this->list as &$project) {
            $project['time_budget'] = $project['timeBudget'];
        }
        unset($project);
        return $this->list;
    }

    public function saveList(array $list): void
    {
        $existing = $this->entityIndex->byId(
            $this->entityManager,
            Project::class,
            array_column($list, 'id')
        );
        $entities = [];
        foreach ($list as $row) {
            $project = $existing[$row['id']] ?? new Project();
            if (!isset($existing[$row['id']])) {
                $project->setId($row['id']);
            }
            $project
                ->setParentTitle($row['parentTitle'])
                ->setCustomerId($row['customer'])
                ->setName($row['name'])
                ->setStart($this->convertDate($row['start']))
                ->setEnd($this->convertDate($row['end']))
                ->setComment($row['comment'])
                ->setVisible($row['visible'])
                ->setBillable($row['billable'])
                ->setColor($row['color'])
                ->setGlobalActivities($row['globalActivities'])
                ->setTimeBudget($row['time_budget']);
            $entities[] = $project;
        }
        $this->batchPersistence->save($this->entityManager, $entities);
    }

    private function convertDate($date): ?DateTime
    {
        if (!$date) {
            return null;
        }
        $date = preg_replace('/-\d{4}$/', '', $date);
        $date = str_replace('T', ' ', $date);
        $date = DateTime::createFromFormat('Y-m-d', $date);
        return $date;
    }

}
