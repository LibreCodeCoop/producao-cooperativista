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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\Service\Source\Provider\Kimai;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Client;

class Users
{
    use Kimai;
    private array $visibilidade = [
        1 => 'visible',
        2 => 'hidden',
        3 => 'all',
    ];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    )
    {
    }

    public function updateDatabase(): void
    {
        $this->logger->debug('Baixando dados de users');
        $list = $this->getFromApi();
        $this->saveToDatabase($list);
    }

    /**
     * Get users from Kimai API
     *
     * @param integer $visible 1=visible, 2=hidden, 3=all
     * @return array
     */
    public function getFromApi(int $visible = 3): array
    {
        $this->logger->debug('Importando usuários com visibilidade = {visibilidade}', [
            'visibilidade' => $this->visibilidade[$visible],
        ]);
        $list = $this->doRequestKimai('/api/users', [
            'visible' => $visible,
        ]);
        $list = $this->updateWithExtraValues($list);
        $this->logger->debug('Dados baixados: {json}', ['json' => json_encode($list)]);
        return $list;
    }

    public function updateWithExtraValues(array $list): array
    {
        $csv = $this->getSpreadsheet();
        foreach ($list as $key => $value) {
            $rowFromCsv = array_filter($csv, fn($i) => $i['Usuário Kimai'] === $value['username']);
            if (!count($rowFromCsv)) {
                $list[$key]['cpf'] = null;
                $list[$key]['dependents'] = null;
                continue;
            }
            $rowFromCsv = current($rowFromCsv);
            $list[$key]['cpf'] = $rowFromCsv['CPF'];
            $list[$key]['dependents'] = $rowFromCsv['Dependentes'];
        }

        return $list;
    }

    private function getSpreadsheet(): array
    {
        $config = [
            'baseUri' => $_ENV['NEXTCLOUD_URL'],
            'userName' => $_ENV['NEXTCLOUD_USERNAME'],
            'password' => $_ENV['NEXTCLOUD_PASSWORD']
        ];
        $prefix = 'remote.php/dav/files/' . $config['userName'] . '/';
        $client = new Client($config);
        $adapter = new WebDAVAdapter($client, $prefix);
        $fileSystem = new Filesystem($adapter);
        $pathOfFile = $_ENV['NEXTCLOUD_PATH_OF_FILE'];
        if (!$fileSystem->has($pathOfFile)) {
            throw new \Exception(
                'Planilha com dados de cooperados não encontrada no Nextcloud. Confira as environments de configuração e se a planilha existe.'
            );
        }
        $fileContent = $fileSystem->read($pathOfFile);
        $fileXlsx = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fileXlsx, $fileContent);
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($fileXlsx);

        $writer = IOFactory::createWriter($spreadsheet, "Csv");
        $writer->setSheetIndex(0); // Select which sheet to export.
        $writer->setDelimiter(',');

        $fileCsv = tempnam(sys_get_temp_dir(), 'csv');
        $writer->save($fileCsv);
        $handle = fopen($fileCsv, 'r');
        $cols = fgetcsv($handle, 10000, ',');
        $cols = array_filter($cols, fn($i) => !empty($i));
        while (($row = fgetcsv($handle, 10000, ',')) !== FALSE) {
            if (empty($row[0])) {
                break;
            }
            $row = array_slice($row, 0, count($cols));
            $row = array_combine($cols, $row);
            $row['CPF'] = (string) preg_replace('/\D/', '', $row['CPF']);
            $row['Dependentes'] = $row['Dependentes'] ? (int) $row['Dependentes'] : null;
            $csv[] = $row;
        }
        fclose($handle);

        return $csv;
    }

    public function saveToDatabase(array $list): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('users')
            ->where($select->expr()->in('id', ':id'))
            ->setParameter('id', array_column($list, 'id'), Connection::PARAM_STR_ARRAY);
        $result = $select->executeQuery();
        $exists = [];
        while ($row = $result->fetchAssociative()) {
            $exists[] = $row['id'];
        }
        $insert = new QueryBuilder($this->db->getConnection());
        foreach ($list as $row) {
            if (in_array($row['id'], $exists)) {
                $update = new QueryBuilder($this->db->getConnection());
                $update->update('users')
                    ->set('alias', $update->createNamedParameter($row['alias']))
                    ->set('title', $update->createNamedParameter($row['title']))
                    ->set('username', $update->createNamedParameter($row['username']))
                    ->set('cpf', $update->createNamedParameter($row['cpf']))
                    ->set('dependents', $update->createNamedParameter($row['dependents']))
                    ->set('enabled', $update->createNamedParameter($row['enabled'], ParameterType::INTEGER))
                    ->set('color', $update->createNamedParameter($row['color']))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])))
                    ->executeStatement();
                continue;
            }
            $insert->insert('users')
                ->values([
                    'id' => $insert->createNamedParameter($row['id']),
                    'alias' => $insert->createNamedParameter($row['alias']),
                    'title' => $insert->createNamedParameter($row['title']),
                    'username' => $insert->createNamedParameter($row['username']),
                    'cpf' => $insert->createNamedParameter($row['cpf']),
                    'dependents' => $insert->createNamedParameter($row['dependents']),
                    'enabled' => $insert->createNamedParameter($row['enabled'], ParameterType::INTEGER),
                    'color' => $insert->createNamedParameter($row['color']),
                ])
                ->executeStatement();
        }
    }
}