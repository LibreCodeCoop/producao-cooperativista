<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\CalculationActionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CalculationActionGuardTest extends TestCase
{
    public function testMissingCsrfFailsEvenForPreview(): void
    {
        [$guard, $session] = $this->guardAndSession();
        $request = Request::create('/acoes/do-make-producao', 'POST');
        $request->setSession($session);
        $this->expectException(AccessDeniedHttpException::class);
        $guard->assertAuthorized($request);
    }

    public function testInvalidCsrfBlocksFinancialPublication(): void
    {
        [$guard, $session] = $this->guardAndSession();
        $request = Request::create('/acoes/do-make-producao', 'POST', ['_token' => 'forged']);
        $request->setSession($session);
        $this->token($guard, $session);
        $this->expectException(AccessDeniedHttpException::class);
        $guard->assertAuthorized($request);
    }

    public function testPreviewRequiresOnlyCsrf(): void
    {
        [$guard, $session] = $this->guardAndSession();
        $request = Request::create('/acoes/do-make-producao', 'POST', [
            '_token' => $this->token($guard, $session),
        ]);
        $request->setSession($session);
        $guard->assertAuthorized($request);
        $this->addToAssertionCount(1);
    }

    public function testFinancialPublicationRequiresNoSecondPassword(): void
    {
        [$guard, $session] = $this->guardAndSession();
        $request = Request::create('/acoes/do-make-producao', 'POST', [
            '_token' => $this->token($guard, $session),
            'atualiza_producao' => '1',
        ]);
        $request->setSession($session);
        $guard->assertAuthorized($request);
        $this->addToAssertionCount(1);
    }

    public function testSettingsSaveUsesTheSameCsrfProtection(): void
    {
        [$guard, $session] = $this->guardAndSession();
        $request = Request::create('/configuracao/cooperado/12/kimai', 'POST', [
            '_token' => $this->token($guard, $session),
            'field' => 'tax_number',
            'value' => '52998224725',
        ]);
        $request->setSession($session);
        $guard->assertAuthorized($request);
        $this->addToAssertionCount(1);
    }

    private function guardAndSession(): array
    {
        return [new CalculationActionGuard(), new Session(new MockArraySessionStorage())];
    }

    private function token(CalculationActionGuard $guard, Session $session): string
    {
        $get = Request::create('/acoes/make-producao');
        $get->setSession($session);
        return $guard->token($get);
    }
}
