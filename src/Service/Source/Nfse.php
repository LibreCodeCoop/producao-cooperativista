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

namespace App\Service\Source;

use DateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use App\Entity\Producao\Nfse as EntityNfse;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\HttpClient\HttpClient;

class Nfse
{
    private string $cookiesFile = 'cookies.json';
    private string $login;
    private string $senha;
    private string $prefeitura;
    private array $prefeituras = [
        'rio' => [
            'host' => 'notacarioca.rio.gov.br',
            'columns' => [
                'numero' => 'Nº da Nota Fiscal Eletrônica',
                'cnpj' => 'CPF/CNPJ do Tomador',
                'razao_social' => 'Razão Social do Tomador',
                'data_emissao' => 'Data Hora da Emissão da Nota Fiscal',
                'valor_servico' => 'Valor dos Serviços',
                'valor_cofins' => 'Valor COFINS',
                'valor_ir' => 'Valor IRPJ',
                'valor_pis' => 'Valor PIS/PASEP',
                'valor_iss' => 'Valor do ISS',
                'numero_substituta' => 'Nº Nota Fiscal Substituta',
                'discriminacao' => 'Discriminação dos Serviços'
            ],
        ],
        'niteroi' => [
            'host' => 'nfse.niteroi.rj.gov.br',
            'columns' => [
                'numero' => 'Nº da Nota Fiscal Eletrônica',
                'cnpj' => 'CPF/CNPJ/NIF do Tomador',
                'razao_social' => 'Razão Social do Tomador',
                'data_emissao' => 'Data Hora da Emissão da Nota Fiscal',
                'valor_servico' => 'Valor dos Serviços',
                'valor_cofins' => 'Valor COFINS',
                'valor_ir' => 'Valor IR',
                'valor_pis' => 'Valor PIS/PASEP',
                'valor_iss' => 'Valor do ISS',
                'numero_substituta' => 'Nº Nota Fiscal Substituta',
                'discriminacao' => 'Discriminação dos Serviços',
            ],
        ],
    ];
    private DateTime $inicio;
    private DateTime $fim;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
        $this->cookiesFile = realpath(__DIR__ . '/../../..') . '/' . $this->cookiesFile;
    }

    public function updateDatabase(DateTime $data): void
    {
        if (!getenv('PREFEITURA_LOGIN')) {
            $this->logger->warning('Dados de NFSe não foram baixados da prefeitura, dados de login não informados.');
            return;
        }
        $this->logger->info('Baixando dados de NFSe');
        $list = $this->getFromApi($data);
        $this->saveList($list);
        $this->logger->info('Dados de NFSe salvos com sucesso. Total: {total}', [
            'total' => count($list),
        ]);
    }

    public function getFromApi(
        DateTime $data,
        ?string $login = null,
        ?string $senha = null,
        ?string $prefeitura = null
    ): array {
        $inicio = $data
            ->modify('first day of this month');
        $fim = clone $inicio;
        $fim = $fim->modify('last day of this month');

        $this->inicio = $inicio;
        $this->fim = $fim;

        $this->login = $login ?? getenv('PREFEITURA_LOGIN');
        $this->senha = $senha ?? getenv('PREFEITURA_SENHA');
        $this->prefeitura = $prefeitura ?? getenv('PREFEITURA');

        $list = $this->getData();
        return $list;
    }

    private function getData(): array
    {
        if (!file_exists($this->cookiesFile)) {
            $this->doLogin();
        }
        $list = $this->getNfse();
        return $list;
    }

    public function saveList(array $list): void
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();
        $qb->select('n.numero')
            ->from('nfse', 'n')
            ->where($qb->expr()->in('n.numero', ':numero'))
            ->setParameter(
                'numero',
                array_column(
                    $list,
                    $this->columnInternalToExternal('numero')
                ),
                ArrayParameterType::INTEGER
            );
        $stmt = $qb->executeQuery();
        $exists = [];
        while ($row = $stmt->fetchAssociative()) {
            $exists[] = $row['numero'];
        }
        foreach ($list as $row) {
            if (in_array($row[$this->columnInternalToExternal('numero')], $exists)) {
                $update = $this->entityManager->createQueryBuilder();
                $update->update('nfse')
                    ->set('cnpj', $update->createNamedParameter(
                        $row[$this->columnInternalToExternal('cnpj')]
                    ))
                    ->set('razao_social', $update->createNamedParameter(
                        $row[$this->columnInternalToExternal('razao_social')]
                    ))
                    ->set('data_emissao', $update->createNamedParameter(
                        $this->convertDate($row[$this->columnInternalToExternal('data_emissao')]),
                        Types::DATE_MUTABLE
                    ))
                    ->set('valor_servico', $update->createNamedParameter(
                        $this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_servico')]),
                        Types::FLOAT
                    ))
                    ->set('valor_cofins', $update->createNamedParameter(
                        $this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_cofins')]),
                        Types::FLOAT
                    ))
                    ->set('valor_ir', $update->createNamedParameter(
                        $this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_ir')]),
                        Types::FLOAT
                    ))
                    ->set('valor_pis', $update->createNamedParameter(
                        $this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_pis')]),
                        Types::FLOAT
                    ))
                    ->set('valor_iss', $update->createNamedParameter(
                        $this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_iss')]),
                        Types::FLOAT
                    ))
                    ->set('numero_substituta', $update->createNamedParameter(
                        $this->convertPtBrToFloat($row[$this->columnInternalToExternal('numero_substituta')]),
                        Types::FLOAT
                    ))
                    ->set('discriminacao_normalizada', $update->createNamedParameter(
                        $row['discriminacao_normalizada']
                    ))
                    ->set('codigo_cliente', $update->createNamedParameter(
                        $row['codigo_cliente']
                    ))
                    ->set('metadata', $update->createNamedParameter(
                        json_encode($row)
                    ))
                    ->where($update->expr()->eq(
                        'numero',
                        $update->createNamedParameter(
                            $row[$this->columnInternalToExternal('numero')],
                            ParameterType::INTEGER
                        )
                    ))
                    ->getQuery()
                    ->execute();
                continue;
            }
            $nfse = new EntityNfse();
            $nfse
                ->setNumero($row[$this->columnInternalToExternal('numero')])
                ->setCnpj($row[$this->columnInternalToExternal('cnpj')])
                ->setRazaoSocial($row[$this->columnInternalToExternal('razao_social')])
                ->setDataEmissao($this->convertDate($row[$this->columnInternalToExternal('data_emissao')]))
                ->setValorServico($this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_servico')]))
                ->setValorCofins($this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_cofins')]))
                ->setValorIr($this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_ir')]))
                ->setValorPis($this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_pis')]))
                ->setValorIss($this->convertPtBrToFloat($row[$this->columnInternalToExternal('valor_iss')]))
                ->setNumeroSubstituta($this->convertPtBrToFloat($row[$this->columnInternalToExternal('numero_substituta')]))
                ->setDiscriminacaoNormalizada($row['discriminacao_normalizada'])
                ->setSetor($row['setor'])
                ->setCodigoCliente($row['codigo_cliente'])
                ->setMetadata(json_encode($row));
            $this->entityManager->persist($nfse);
        }
    }

    private function convertPtBrToFloat(?string $number): ?float
    {
        if (!$number) {
            return null;
        }
        $number = str_replace('.', '', $number);
        $number = str_replace(',', '.', $number);
        return (float) $number;
    }

    private function convertDate($date): DateTime
    {
        $date = DateTime::createFromFormat('d/m/Y H:i:s', $date);
        return $date;
    }

    private function columnInternalToExternal(string $internal): string
    {
        return $this->prefeituras[$this->prefeitura]['columns'][$internal];
    }

    private function doLogin(): void
    {
        if (file_exists($this->cookiesFile)) {
            unlink($this->cookiesFile);
        }

        $client = new HttpBrowser(HttpClient::create([
            'headers' => [
                'Host' => $this->prefeituras[$this->prefeitura]['host']
            ]
        ]));
        // Request to get cookie
        $crawler = $client->request('GET', 'https://' . $this->prefeituras[$this->prefeitura]['host'] . '/senhaweb/login.aspx');

        $cookie = new Cookie(
            'ASP.NET_SessionId',
            $client->getCookieJar()->allRawValues('https://' . $this->prefeituras[$this->prefeitura]['host'])['ASP.NET_SessionId'],
            (string) strtotime('+1 day')
        );
        $cookieJar = new CookieJar();
        $cookieJar->set($cookie);

        $client = new HttpBrowser(HttpClient::create([
            'headers' => [
                'Host' => $this->prefeituras[$this->prefeitura]['host']
            ]
        ]), null, $cookieJar);
        $client->request('POST', 'https://' . $this->prefeituras[$this->prefeitura]['host'] . '/senhaweb/login.aspx', [
            '__LASTFOCUS' => '',
            '__EVENTTARGET' => '',
            '__EVENTARGUMENT' => '',
            '__VIEWSTATE' => $crawler->filter('#__VIEWSTATE')->attr('value'),
            '__VIEWSTATEGENERATOR' => $crawler->filter('#__VIEWSTATEGENERATOR')->attr('value'),
            '__EVENTVALIDATION' => $crawler->filter('#__EVENTVALIDATION')->attr('value'),
            'ctl00$CAB$ddlNavegacaoRapida' => 0,
            'ctl00$cphCabMenu$tbCpfCnpj' => $this->login,
            'ctl00$cphCabMenu$tbSenha' => $this->senha,
            // Não é necessário, basta pegar os cookies e usar que já está autenticado
            'ctl00$cphCabMenu$ccCodigo$tbCaptchaControl' => 'fake',
            'ctl00$cphCabMenu$btEntrar' => 'ENTRAR',
        ]);
        file_put_contents($this->cookiesFile, json_encode($client->getCookieJar()->allRawValues('https://' . $this->prefeituras[$this->prefeitura]['host'])));
    }

    private function getNfse(): array
    {
        $cookies = json_decode(file_get_contents($this->cookiesFile), true);
        $cookieJar = new CookieJar();
        $cookieJar->set(new Cookie('ASP.NET_SessionId', $cookies['ASP.NET_SessionId'], (string) strtotime('+1 day')));
        $cookieJar->set(new Cookie('NITEROI_NFE_CPFCNPJ', $cookies['NITEROI_NFE_CPFCNPJ'], (string) strtotime('+1 day')));
        $cookieJar->set(new Cookie('.nfse.niteroi', $cookies['.nfse.niteroi'], (string) strtotime('+1 day')));

        $client = new HttpBrowser(HttpClient::create([
            'headers' => [
                'Host' => $this->prefeituras[$this->prefeitura]['host']
            ]
        ]), null, $cookieJar);

        try {
            $crawler = $client->request('GET', 'https://' . $this->prefeituras[$this->prefeitura]['host'] . '/NFSE/contribuinte/Consulta.aspx');
            $inscricao = $crawler->filter('#ctl00_cphCabMenu_ddlContribuinte option[selected=selected]')->attr('value');
        } catch (\Throwable $th) {
            $this->doLogin();
            $this->getData();
            return [];
        }
        $urlNotasEmitidas = 'https://' . $this->prefeituras[$this->prefeitura]['host'] . '/NFSE/contribuinte/Notasemitidas.aspx?' . http_build_query([
            'inscricao' => $inscricao,
            'regimeperiodo' => 2,
            'inicio' => $this->inicio->format('d/m/Y'),
            'fim' => $this->fim->format('d/m/Y'),
            'cpfcnpj' => '',
            'nome' => '',
            'returnUrl' => 'Consulta.aspx?' .
                http_build_query([
                    'inscricao' => $inscricao,
                    'regimeperiodo' => 2,
                    'inicio' => $this->inicio->format('d/m/Y'),
                    'fim' => $this->fim->format('d/m/Y'),
                    'cpfcnpj' => '',
                    'nome' => '',
                    'modulo' => '0',
                ])
            ]);
        $crawler = $client->request('POST', $urlNotasEmitidas);
        $client->request(
            'POST',
            $urlNotasEmitidas,
            [
                '__EVENTTARGET' => 'ctl00$cphCabMenu$btExportar$btGerar',
                '__EVENTARGUMENT' => '',
                '__LASTFOCUS' => '',
                '__VIEWSTATE' => $crawler->filter('#__VIEWSTATE')->attr('value'),
                '__VIEWSTATEGENERATOR' => $crawler->filter('#__VIEWSTATEGENERATOR')->attr('value'),
                'ctl00$CAB$ddlNavegacaoRapida' => '0',
                'ctl00$cphCabMenu$ctrlInscricoes$ddlInscricoes' => $inscricao,
                'ctl00$cphCabMenu$btExportar$ddlTipoArquivo' => '5',
                'ctl00$cphCabMenu$ctrlFiltros$ddlRegimePeriodo' => '2',
                'ctl00$cphCabMenu$ctrlFiltros$ctrPeriodo$tbInicio' => $this->inicio->format('d/m/Y'),
                'ctl00$cphCabMenu$ctrlFiltros$ctrPeriodo$tbFim' => $this->fim->format('d/m/Y'),
                'ctl00$cphCabMenu$ctrlFiltros$ddlRPS' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ddlStatus' => '1',
                'ctl00$cphCabMenu$ctrlFiltros$ddlRetencao' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ddlSituacaoIss' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ddlRegimeTributacao' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ddlExigibilidade' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ddlRegraEspecial' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ddlAceite' => '0',
                'ctl00$cphCabMenu$ctrlFiltros$ctrlCpfCnpj$tbCpfCnpj' => '',
                'ctl00$cphCabMenu$ctrlFiltros$tbNome' => '',
                'ctl00$cphCabMenu$ctrlFiltros$tbSerieRPS' => '',
                'ctl00$cphCabMenu$ctrlFiltros$tbNumeroRPS' => ''
            ],
        );
        $content = iconv(
            'iso-8859-1',
            'utf-8',
            $client->getResponse()->getContent()
        );

        $list = explode("\n", $content);
        $lastRow = end($list);
        while (!str_starts_with($lastRow, 'Total') && count($list) > 0) {
            $lastRow = array_pop($list);
        }
        $text = implode("\n", $list);

        $fp = fopen('data://text/plain,' . $text, 'r');
        $rows = [];
        while (($data = fgetcsv($fp, 10000, ';')) !== false) {
            $rows[] = $data;
        }
        $header = array_shift($rows);
        $csv = [];
        foreach ($rows as $row) {
            $csv[] = array_combine($header, $row);
        }
        array_walk($csv, function (&$row) {
            $row[$this->columnInternalToExternal('cnpj')] = preg_replace('/[^0-9]/', '', $row[$this->columnInternalToExternal('cnpj')]);
            $discriminacao = explode('|', $row[$this->columnInternalToExternal('discriminacao')]);
            $normalized = [];
            foreach ($discriminacao as $value) {
                $exploded = explode(':', $value);
                if (count($exploded) === 2) {
                    $key = strtolower($exploded[0]);
                    $normalized[$key] = trim($exploded[1]);
                    if (empty($normalized[$key])) {
                        unset($normalized[$key]);
                    }
                } else {
                    $normalized['raw'][] = implode(':', $exploded);
                }
            }
            $row['discriminacao_normalizada'] = json_encode($normalized);
            if (isset($normalized['setor']) && is_string($normalized['setor'])) {
                $row['setor'] = $normalized['setor'];
                $row['codigo_cliente'] = $row[$this->columnInternalToExternal('cnpj')] . '|' . $normalized['setor'];
            } else {
                $row['setor'] = null;
                $row['codigo_cliente'] = $row[$this->columnInternalToExternal('cnpj')];
            }
        });
        return $csv;
    }
}
