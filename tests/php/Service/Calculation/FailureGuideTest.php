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
        yield ['SOURCE_CONNECTION_FAILED: integração temporariamente indisponível', 'SERVICO_INACESSIVEL'];
        yield ['PROFILE_SOURCE: usuário Kimai #28 sem vínculo válido', 'PROFILE_SOURCE'];
        yield ['PROFILE_KIMAI_LINK: usuário Kimai #40', 'PROFILE_KIMAI_LINK'];
        yield ['PROFILE_ACCOUNTING_LINK: usuário Kimai #40', 'PROFILE_ACCOUNTING_LINK'];
        yield ['PROFILE_ACCOUNTING_INVALID: usuário Kimai #40', 'PROFILE_ACCOUNTING_INVALID'];
        yield ['PROFILE_TAX_MISSING: usuário Kimai #40', 'PROFILE_TAX_MISSING'];
        yield ['PROFILE_TAX_MISMATCH: usuário Kimai #40', 'PROFILE_TAX_MISMATCH'];
        yield ['PROFILE_DEPENDENTS: usuário Kimai #15 com campo ausente', 'PROFILE_DEPENDENTS'];
        yield ['Código de cliente inválido na movimentação do Akaunting', 'CLIENTE_SEM_CODIGO'];
        yield ['Environment variable AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS must contain valid JSON.', 'CONFIG_JSON'];
        yield ['Could not resolve host: akaunting.nginx', 'SERVICO_INACESSIVEL'];
        yield ['Warning: Undefined array key "data"', 'API_RESPOSTA'];
        yield ['Cannot assign string to property App\\Entity\\Akaunting\\Contacts::$taxNumber', 'CONTACT_MAPPING'];
        yield ['The table with name "users" already exists', 'SCHEMA_DIVERGENTE'];
        yield ['Warning: Cannot modify header information - headers already sent', 'SSE_CABECALHOS'];
        yield ['Sem transações', 'SEM_MOVIMENTOS'];
        yield ['Nenhuma hora: não há horas ponderadas', 'PESOS_HORAS'];
        yield ['Publicação bloqueada: já existe uma gravação pendente ou de resultado incerto.', 'PUBLICACAO_INCERTA'];
        yield ['Table report_test.publication_write_attempts does not exist', 'REGISTRO_PUBLICACAO_AUSENTE'];
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
