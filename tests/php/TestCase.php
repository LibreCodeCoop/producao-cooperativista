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

namespace Tests\Php;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use PHPUnit\Framework\TestCase as FrameworkTestCase;
use ProducaoCooperativista\Core\App;
use ProducaoCooperativista\DB\Database;
use Tests\Php\Fixtures\CsvLoader;

class TestCase extends FrameworkTestCase
{
    public function loadDataset(string $dataset): void
    {
        $csvLoader = new CsvLoader();
        $csvLoader->loadDataset($dataset);
        $loader = new Loader();
        $loader->addFixture($csvLoader);

        /** @var Database */
        $db = App::get(Database::class);
        $entityManager = $db->getEntityManager();
        $executor = new ORMExecutor($entityManager, new ORMPurger());
        $executor->execute($loader->getFixtures());
    }
}
