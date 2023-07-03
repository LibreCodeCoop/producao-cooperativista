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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
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
    ) {
    }

    public function updateDatabase(): void
    {
        $this->logger->debug('Baixando dados de users');
        $list = $this->getFromApi();
        $this->saveList($list);
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
        $list = $this->updateWithDataFromSpreadsheet($list);
        $list = $this->updateWithAkauntingData($list);
        $this->logger->debug('Dados baixados: {json}', ['json' => json_encode($list)]);
        return $list;
    }

    private function updateWithAkauntingData(array $list): array
    {
        $taxNumber = array_column($list, 'tax_number');
        $email = array_column($list, 'corporate_mail');

        $select = new QueryBuilder($this->db->getConnection(Database::DB_AKAUNTING));
        $select->select('c.*')
            ->from('contacts', 'c')
            ->where($select->expr()->or(
                $select->expr()->in('tax_number', $select->createNamedParameter($taxNumber, ArrayParameterType::STRING)),
                $select->expr()->in('email', $select->createNamedParameter($email, ArrayParameterType::STRING)),
            ))
            ->andWhere('deleted_at IS NULL')
            ->andWhere($select->expr()->in('type', $select->createNamedParameter(['vendor', 'employee'], ArrayParameterType::STRING)))
            ->orderBy('c.type');
        $result = $select->executeQuery();

        while ($row = $result->fetchAssociative()) {
            foreach ($list as $key => $value) {
                if ($value['corporate_mail'] === $row['email']
                    || ($value['tax_number'] === $row['tax_number'])
                    || ($value['kimai_username'] === $row['email'])
                ) {
                    $list[$key]['akaunting_contact_id'] = $row['id'];
                    break;
                }
            }
        }
        return $list;
    }

    private function updateWithDataFromSpreadsheet(array $list): array
    {
        $csv = $this->getSpreadsheet();
        foreach ($list as $key => $value) {
            $username = $value['username'];
            $rowFromCsv = array_filter($csv, fn ($i) => $i['Usuário Kimai'] === $username);
            if (!count($rowFromCsv)) {
                unset($list[$key]);
                continue;
            }
            $rowFromCsv = current($rowFromCsv);
            $list[$key]['kimai_username'] = $username;
            unset($list[$key]['username']);
            $list[$key]['tax_number'] = $rowFromCsv['CPF'];
            if (empty($list[$key]['tax_number'])) {
                unset($list[$key]);
                continue;
            }
            $list[$key]['dependents'] = $rowFromCsv['Dependentes'] ?? 0;
            $list[$key]['health_insurance'] = $rowFromCsv['Plano de saúde'] ?? 0;
            $list[$key]['corporate_mail'] = $rowFromCsv['Email corporativo'] ?? 0;
        }
        $list = array_filter($list, fn ($r) => !empty($r['tax_number']));

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

        /** @var Csv */
        $writer = IOFactory::createWriter($spreadsheet, "Csv");
        $writer->setSheetIndex(0); // Select which sheet to export.
        $writer->setDelimiter(',');

        $fileCsv = tempnam(sys_get_temp_dir(), 'csv');
        $writer->save($fileCsv);
        $handle = fopen($fileCsv, 'r');
        $cols = fgetcsv($handle, 10000, ',');
        $cols = array_filter($cols, fn ($i) => !empty($i));
        $csv = [];
        while (($row = fgetcsv($handle, 10000, ',')) !== false) {
            if (empty($row[0])) {
                break;
            }
            $row = array_slice($row, 0, count($cols));
            $row = array_combine($cols, $row);
            $row['CPF'] = (string) preg_replace('/\D/', '', (string) $row['CPF']);
            $row['Dependentes'] = $row['Dependentes'] ? (int) $row['Dependentes'] : null;
            $row['Plano de saúde'] = $row['Plano de saúde'] ? (float) $row['Plano de saúde'] : null;
            $row['Email corporativo'] = $row['Email corporativo'];
            $csv[] = $row;
        }
        fclose($handle);

        return $csv;
    }

    public function saveList(array $list): void
    {
        $select = new QueryBuilder($this->db->getConnection());
        $select->select('id')
            ->from('users')
            ->where($select->expr()->in('id', ':id'))
            ->setParameter('id', array_column($list, 'id'), ArrayParameterType::STRING);
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
                    ->set('kimai_username', $update->createNamedParameter($row['kimai_username']))
                    ->set('akaunting_contact_id', $update->createNamedParameter($row['akaunting_contact_id'] ?? null))
                    ->set('tax_number', $update->createNamedParameter($row['tax_number']))
                    ->set('dependents', $update->createNamedParameter($row['dependents']))
                    ->set('health_insurance', $update->createNamedParameter($row['health_insurance']))
                    ->set('enabled', $update->createNamedParameter($row['enabled'], ParameterType::INTEGER))
                    ->set('metadata', $update->createNamedParameter(json_encode($row)))
                    ->where($update->expr()->eq('id', $update->createNamedParameter($row['id'])))
                    ->executeStatement();
                continue;
            }
            $insert->insert('users')
                ->values([
                    'id' => $insert->createNamedParameter($row['id']),
                    'alias' => $insert->createNamedParameter($row['alias']),
                    'kimai_username' => $insert->createNamedParameter($row['kimai_username']),
                    'akaunting_contact_id' => $insert->createNamedParameter($row['akaunting_contact_id'] ?? null),
                    'tax_number' => $insert->createNamedParameter($row['tax_number']),
                    'dependents' => $insert->createNamedParameter($row['dependents']),
                    'health_insurance' => $insert->createNamedParameter($row['health_insurance']),
                    'enabled' => $insert->createNamedParameter($row['enabled'], ParameterType::INTEGER),
                    'metadata' => $insert->createNamedParameter(json_encode($row)),
                ])
                ->executeStatement();
        }
    }
}
