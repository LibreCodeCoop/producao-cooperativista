<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Service\Calculation\CalculationActionGuard;
use App\Service\Integration\SettingsAccessGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelInterface;

final class SettingsAccessGuardTest extends TestCase
{
    private function guard(): SettingsAccessGuard
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getEnvironment')->willReturn('prod');
        return new SettingsAccessGuard(new CalculationActionGuard($kernel));
    }

    protected function setUp(): void
    {
        putenv('PRODUCAO_ACTION_TOKEN=' . str_repeat('b', 64));
    }

    protected function tearDown(): void
    {
        putenv('PRODUCAO_ACTION_TOKEN');
    }

    public function testUnlockRequiresCsrfAndIndependentOperationsPassword(): void
    {
        $guard = $this->guard();
        $request = Request::create('/configuracao/desbloquear', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $csrf = $guard->csrf($request);
        $request->request->set('_token', $csrf);
        $request->request->set('action_token', 'incorrect');

        $this->expectException(AccessDeniedHttpException::class);
        $guard->unlock($request);
    }

    public function testShortAdministrativeSessionAndServerSignedExpectedValues(): void
    {
        $guard = $this->guard();
        $request = Request::create('/configuracao/desbloquear', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::assertFalse($guard->isUnlocked($request));
        $request->request->set('_token', $guard->csrf($request));
        $request->request->set('action_token', str_repeat('b', 64));
        $guard->unlock($request);
        self::assertTrue($guard->isUnlocked($request));
        self::assertNotSame(
            $guard->fingerprint(4, 'nextcloud', 'weight', '1'),
            $guard->fingerprint(4, 'nextcloud', 'weight', '2')
        );
        $request->request->set('_token', $guard->csrf($request));
        $guard->assertCanWrite($request);
        $guard->lock($request);
        self::assertFalse($guard->isUnlocked($request));
    }
}
