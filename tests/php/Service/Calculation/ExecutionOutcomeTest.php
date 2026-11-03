<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\ExecutionOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class ExecutionOutcomeTest extends TestCase
{
    public function testSuccessfulExecutionEndsWithDone(): void
    {
        $outcome = new ExecutionOutcome(Command::SUCCESS, 'run-ok');

        $this->assertSame('done', $outcome->event());
        $this->assertStringContainsString('run-ok', $outcome->message());
    }

    public function testFailedExecutionNeverSendsDoneAndShowsError(): void
    {
        $outcome = new ExecutionOutcome(Command::FAILURE, 'run-001', 'Falha na etapa "importação": serviço indisponível');

        $this->assertSame('failed', $outcome->event());
        $this->assertStringContainsString('importação', $outcome->message());
        $this->assertStringContainsString('run-001', $outcome->message());
    }

    public function testDependentFailureShowsOnlySafeKimaiIdAndHowToRepair(): void
    {
        $outcome = new ExecutionOutcome(
            Command::FAILURE,
            'run-001',
            'Falha na etapa importação: PROFILE_DEPENDENTS: usuário Kimai #27 com CPF [PRIVATE].',
        );
        self::assertSame('failed', $outcome->event());
        self::assertStringContainsString('PROFILE_DEPENDENTS', $outcome->message());
        self::assertStringContainsString('Kimai #27', $outcome->message());
        self::assertStringContainsString('Cooperados e vínculos', $outcome->message());
        self::assertStringNotContainsString('[PRIVATE]', $outcome->message());
    }

    public function testProfileFailureNamesSafeSourceAliasAndStageWithoutSensitiveDetails(): void
    {
        $outcome = new ExecutionOutcome(
            Command::FAILURE,
            'run-404',
            'Falha na importação de 2026-10, etapa kimai.users. PROFILE_KIMAI_LINK: ' .
            'usuário Kimai #40 (Luis Souza). CPF 99999999999 token private-test-token'
        );
        $text = $outcome->message();
        self::assertStringContainsString('erro PROFILE_KIMAI_LINK', $text);
        self::assertStringContainsString('Cooperado: Luis Souza (Kimai #40)', $text);
        self::assertStringContainsString('perfis Nextcloud', $text);
        self::assertStringContainsString('vínculo explícito', $text);
        self::assertStringNotContainsString('99999999999', $text);
        self::assertStringNotContainsString('private-test-token', $text);
    }

    public function testBadSourceLabelCannotExposeEmailOrNumericIdentifier(): void
    {
        $outcome = new ExecutionOutcome(
            Command::FAILURE,
            'run-001',
            'PROFILE_TAX_MISSING: usuário Kimai #47 (person@example.test) CPF 99999999999'
        );
        self::assertStringContainsString('Kimai #47 (nome não disponível)', $outcome->message());
        self::assertStringNotContainsString('person@example.test', $outcome->message());
        self::assertStringNotContainsString('99999999999', $outcome->message());
    }

    public function testMissingCustomerCodeNamesSafeFinancialRecordWithoutLeakingMetadata(): void
    {
        $details = 'Falha na etapa "cálculo da produção": Código de cliente inválido na movimentação do Akaunting.'
            . "\nDados:\n[ {\n  \"origem\": \"transaction\",\n  \"id\": 3290,\n"
            . '  "categoria": "Cliente > Externo > Recorrência", "tax_number": "12345678900",'
            . ' "authorization": "private-test-token", "codigo_cliente": "" } ]';
        $outcome = new ExecutionOutcome(Command::FAILURE, 'run-april', $details);
        $message = $outcome->message();
        self::assertSame('failed', $outcome->event());
        self::assertStringContainsString('erro CLIENTE_SEM_CODIGO', $message);
        self::assertStringContainsString('transação #3290 no Akaunting', $message);
        self::assertStringContainsString('patrocínio', $message);
        self::assertStringNotContainsString('12345678900', $message);
        self::assertStringNotContainsString('private-test-token', $message);
        self::assertStringNotContainsString('categoria', $message);
    }

    public function testUntrustedIdCannotBeSentAsFinancialRecord(): void
    {
        $outcome = new ExecutionOutcome(
            Command::FAILURE,
            'run-invalid',
            'Código de cliente inválido na movimentação do Akaunting. '
            . '{"origem":"transaction","id":"<script>alert(1)</script>"}',
        );
        self::assertStringNotContainsString('<script>', $outcome->message());
        self::assertStringNotContainsString('Registro financeiro:', $outcome->message());
    }

    public function testLongTraceDoesNotFloodBrowserMessage(): void
    {
        $outcome = new ExecutionOutcome(Command::FAILURE, 'run-001', str_repeat('X', 2000));

        $this->assertLessThan(850, mb_strlen($outcome->message()));
    }
}
