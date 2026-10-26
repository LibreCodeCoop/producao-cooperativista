<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\FailureGuide;
use App\Service\Calculation\ExecutionOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class FailureGuideTest extends TestCase
{
    #[DataProvider('pastProductionErrors')]
    public function testKnownProductionFailureHasSpecificAction(string $input, string $code): void
    {
        $guide = FailureGuide::diagnose($input);
        self::assertSame($code, $guide->code);
        self::assertNotEmpty($guide->instructions);
    }

    public static function pastProductionErrors(): iterable
    {
        yield ['Código de cliente inválido na movimentação do Akaunting', 'CLIENTE_SEM_CODIGO'];
        yield ['Environment variable AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS must contain valid JSON.', 'CONFIG_JSON'];
        yield ['Could not resolve host: akaunting.nginx', 'SERVICO_INACESSIVEL'];
        yield ['Warning: Undefined array key "data"', 'API_RESPOSTA'];
        yield ['Cannot assign string to property App\\Entity\\Akaunting\\Contacts::$taxNumber', 'CONTACT_MAPPING'];
        yield ['The table with name "users" already exists', 'SCHEMA_DIVERGENTE'];
        yield ['Warning: Cannot modify header information - headers already sent', 'SSE_CABECALHOS'];
        yield ['Sem transações', 'SEM_MOVIMENTOS'];
        yield ['Nenhuma hora: não há horas ponderadas', 'PESOS_HORAS'];
    }

    public function testSseNeverLeaksRawDataFromExceptions(): void
    {
        $result = new ExecutionOutcome(
            Command::FAILURE,
            'identificador-01',
            'Código de cliente inválido com CPF 12345678900 e token segredo-api-valor'
        );
        self::assertSame('failed', $result->event());
        self::assertStringContainsString('CLIENTE_SEM_CODIGO', $result->message());
        self::assertStringNotContainsString('12345678900', $result->message());
        self::assertStringNotContainsString('segredo-api-valor', $result->message());
    }
}
