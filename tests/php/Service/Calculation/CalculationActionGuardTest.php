<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\CalculationActionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelInterface;

final class CalculationActionGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PRODUCAO_ACTION_TOKEN');
    }

    public function testMissingCsrfFailsEvenForPreview(): void
    {
        [$guard, $session] = $this->guardAndSession('prod');
        $request = Request::create('/acoes/do-make-producao', 'POST');
        $request->setSession($session);
        $this->expectException(AccessDeniedHttpException::class);
        $guard->assertAuthorized($request, false);
    }

    public function testDryRunDoesNotRequireAdministrativeSecret(): void
    {
        [$guard, $session] = $this->guardAndSession('prod');
        $request = Request::create('/acoes/do-make-producao', 'POST', ['_token' => $this->token($guard, $session)]);
        $request->setSession($session);
        $guard->assertAuthorized($request, false);
        $this->addToAssertionCount(1);
    }

    public function testImportOrPublishingRequiresServerConfiguredSecret(): void
    {
        [$guard, $session] = $this->guardAndSession('prod');
        $request = Request::create('/acoes/do-make-producao', 'POST', ['_token' => $this->token($guard, $session)]);
        $request->setSession($session);
        $this->expectException(AccessDeniedHttpException::class);
        $guard->assertAuthorized($request, true);
    }

    public function testCorrectConfiguredSecretAllowsWriting(): void
    {
        putenv('PRODUCAO_ACTION_TOKEN=protected-by-server');
        [$guard, $session] = $this->guardAndSession('prod');
        $request = Request::create('/acoes/do-make-producao', 'POST', [
            '_token' => $this->token($guard, $session),
            'action_token' => 'protected-by-server',
        ]);
        $request->setSession($session);
        $guard->assertAuthorized($request, true);
        $this->addToAssertionCount(1);
    }

    private function guardAndSession(string $environment): array
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn($environment);
        return [new CalculationActionGuard($kernel), new Session(new MockArraySessionStorage())];
    }

    private function token(CalculationActionGuard $guard, Session $session): string
    {
        $get = Request::create('/acoes/make-producao');
        $get->setSession($session);
        return $guard->token($get);
    }
}
