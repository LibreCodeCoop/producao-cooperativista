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

namespace Tests\Php\Fixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Persistence\ObjectManager;

class CsvLoader implements FixtureInterface
{
    private array $csvFiles = [];
    public function load(ObjectManager $manager): void
    {
        foreach ($this->csvFiles as $filename) {
            $handle = fopen($filename, 'r');
            $entityName = pathinfo($filename, PATHINFO_FILENAME);
            $header = fgetcsv($handle, null, ',', '"', '\\');
            while (($values = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $values = array_map(fn ($v) => $v === '' ? null : $v, $values);
                $data = array_combine($header, $values);
                $className = 'App\Entity\Producao\\' . ucfirst($entityName);
                $entity = new $className();
                $entity->fromArray($data);
                $manager->persist($entity);
                $manager->flush();
            }
            fclose($handle);
        }
    }

    public function loadDataset(string $dataset): void
    {
        $files = glob(__DIR__ . '/dataset/' . $dataset . '/*.csv');
        $this->csvFiles = array_merge($this->csvFiles, $files);
    }
}
