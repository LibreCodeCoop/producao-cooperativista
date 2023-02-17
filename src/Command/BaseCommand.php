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

namespace KimaiClient\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

class BaseCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'csv',
                null,
                InputOption::VALUE_NONE,
                'To output as CSV'
            )
            ->addOption(
                'database',
                null,
                InputOption::VALUE_NONE,
                'Save to default database'
            );
    }

    protected function toCsv(array $data, array $removeColumns = []): string
    {
        $csv = fopen('php://temp/maxmemory:'. (5*1024*1024), 'r+');

        $header = array_keys(current($data));
        $header = array_filter($header, function($value) use ($removeColumns) {
            return !in_array($value, $removeColumns);
        });
        fputcsv($csv, $header);
        foreach ($data as $row) {
            foreach ($removeColumns as $toRemove) {
                unset($row[$toRemove]);
            }
            unset($row['metaFields'], $row['teams']);
            fputcsv($csv, $row);
        }
        rewind($csv);
        return stream_get_contents($csv);
    }
}
