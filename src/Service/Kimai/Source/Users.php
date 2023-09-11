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

namespace ProducaoCooperativista\Service\Kimai\Source;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use League\Flysystem\Filesystem;
use League\Flysystem\WebDAV\WebDAVAdapter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use ProducaoCooperativista\DB\Database;
use ProducaoCooperativista\DB\Entity\Users as EntityUsers;
use ProducaoCooperativista\Provider\Kimai;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Client;

class Users
{
    use Kimai;
    private int $visibility = 3;
    private array $visibilityDataset = [
        1 => 'visible',
        2 => 'hidden',
        3 => 'all',
    ];
    /** @var EntityUsers[] */
    private array $list = [];
    private array $spreadsheetData = [];

    public function __construct(
        private Database $db,
        private LoggerInterface $logger
    ) {
    }

    public function setVisibility(int $visibility): self
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function updateDatabase(): void
    {
        $list = $this->getList();
        $this->saveList($list);
    }

    /**
     * Get users from Kimai API
     *
     * @param integer $visible 1=visible, 2=hidden, 3=all
     * @return array
     */
    public function getList(): array
    {
        if (!empty($this->list)) {
            return $this->list;
        }
        $this->logger->debug('Importando usuários com visibilidade = {visibilidade}', [
            'visibilidade' => $this->visibilityDataset[$this->visibility],
        ]);
        $list = $this->doRequestKimai('/api/users', [
            'visible' => $this->visibility,
        ]);
        foreach ($list as $row) {
            try {
                $user = $this->fromArray($row);
                $this->list[] = $user;
            } catch (\Throwable $th) {
                $this->logger->debug('Falha ao salvar dados de usuário', [
                    'message' => $th->getMessage(),
                    'data' => $row,
                ]);
            }
        }
        $this->logger->debug('Dados baixados: {json}', ['json' => json_encode($list)]);
        return $list;
    }

    public function fromArray(array $array): EntityUsers
    {
        $array = $this->updateFromUserPreferences($array);
        $array = $this->updateWithDataFromSpreadsheet($array);
        $array = $this->updateWithAkauntingData($array);
        $array = $this->convertFields($array);
        $entity = $this->db->getEntityManager()->find(EntityUsers::class, $array['id']);
        if (!$entity instanceof EntityUsers) {
            $entity = new EntityUsers();
        }
        $entity->fromArray($array);
        return $entity;
    }

    private function updateFromUserPreferences(array $item): array
    {
        $detailed = $this->doRequestKimai('/api/users/' . $item['id']);
        $preferences = array_column($detailed['preferences'], 'value', 'name');
        $item['kimai_username'] = $item['username'];
        if (!$item['alias']) {
            $item['alias'] = $item['username'];
        }
        $item['email'] = $preferences['email'];
        if ($preferences['tax_number'] !== '') {
            $item['tax_number'] = $preferences['tax_number'];
        }
        if ($preferences['dependents'] !== '') {
            $item['dependents'] = (int) $preferences['dependents'];
        }
        return $item;
    }

    private function updateWithAkauntingData(array $item): array
    {
        if (empty($item['tax_number'])) {
            return $item;
        }
        $select = new QueryBuilder($this->db->getConnection(Database::DB_AKAUNTING));
        $select->select('c.*')
            ->from('contacts', 'c')
            ->where($select->expr()->or(
                $select->expr()->eq('tax_number', $select->createNamedParameter($item['tax_number'])),
                $select->expr()->eq('email', $select->createNamedParameter($item['email'])),
            ))
            ->andWhere('deleted_at IS NULL')
            ->andWhere($select->expr()->in('type', $select->createNamedParameter(['vendor', 'employee'], ArrayParameterType::STRING)))
            ->orderBy('c.type');
        $result = $select->executeQuery();
        $row = $result->fetchAssociative();
        if (!$row) {
            return $item;
        }

        if ($item['email'] === $row['email']
            || ($item['tax_number'] === $row['tax_number'])
            || ($item['kimai_username'] === $row['email'])
        ) {
            $item['akaunting_contact_id'] = $row['id'];
        }
        return $item;
    }

    private function updateWithDataFromSpreadsheet(array $row): array
    {
        if (isset($row['tax_number'], $row['dependents'])) {
            return $row;
        }
        $username = $row['username'];
        unset($row['username']);

        $csv = $this->getSpreadsheet();
        $rowFromCsv = array_filter($csv, fn ($i) => $i['Usuário Kimai'] === $username);
        if (!count($rowFromCsv)) {
            throw new \Exception('Usuário não encontrado na planilha. Informe o username do kimai deste usuário.');
        }
        $rowFromCsv = current($rowFromCsv);

        if (empty($row['tax_number'])) {
            if (empty($rowFromCsv['CPF'])) {
                throw new \Exception('Usuário na planilha não possui CPF');
            }
            $row['tax_number'] = $rowFromCsv['CPF'];
        }
        if (empty($row['dependents'])) {
            if (empty($rowFromCsv['Email corporativo'])) {
                throw new \Exception('Usuário na planilha não possui email corporativo');
            }
            $row['dependents'] = $rowFromCsv['Dependentes'] ?? 0;
        }
        $row['email'] = $rowFromCsv['Email corporativo'] ?? 0;

        return $row;
    }

    private function getSpreadsheet(): array
    {
        if ($this->spreadsheetData) {
            return $this->spreadsheetData;
        }
        $config = [
            'baseUri' => getenv('NEXTCLOUD_URL'),
            'userName' => getenv('NEXTCLOUD_USERNAME'),
            'password' => getenv('NEXTCLOUD_PASSWORD')
        ];
        $prefix = 'remote.php/dav/files/' . $config['userName'] . '/';
        $client = new Client($config);
        $adapter = new WebDAVAdapter($client, $prefix);
        $fileSystem = new Filesystem($adapter);
        $pathOfFile = getenv('NEXTCLOUD_PATH_OF_FILE');
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
        while (($row = fgetcsv($handle, 10000, ',')) !== false) {
            if (empty($row[0])) {
                break;
            }
            $row = array_slice($row, 0, count($cols));
            $row = array_combine($cols, $row);
            $row['CPF'] = (string) preg_replace('/\D/', '', (string) $row['CPF']);
            $row['Dependentes'] = $row['Dependentes'] ? (int) $row['Dependentes'] : null;
            $row['Email corporativo'] = $row['Email corporativo'];
            $this->spreadsheetData[] = $row;
        }
        fclose($handle);

        return $this->spreadsheetData;
    }

    public function saveList(): self
    {
        $this->getList();
        foreach ($this->list as $row) {
            $this->saveRow($row);
        }
        return $this;
    }

    public function saveRow(EntityUsers $user): self
    {
        $em = $this->db->getEntityManager();
        $em->persist($user);
        $em->flush();
        return $this;
    }

    private function convertFields(array $row): array
    {
        $row['akaunting_contact_id'] = $row['akaunting_contact_id'] ?? null;
        $row['metadata'] = $row;
        return $row;
    }
}
