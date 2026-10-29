<?php

declare(strict_types=1);

namespace App\Service\Integration;

use App\Service\Calculation\CalculationActionGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * One operations-password challenge per short administrative session.
 * The outer site still requires HTTP Basic; credentials are never kept here.
 */
final readonly class SettingsAccessGuard
{
    private const string SESSION_KEY = 'production_settings_unlocked_until';

    public function __construct(private CalculationActionGuard $operations)
    {
    }

    public function csrf(Request $request): string
    {
        return $this->operations->token($request);
    }

    public function isUnlocked(Request $request): bool
    {
        return (int) $request->getSession()->get(self::SESSION_KEY, 0) > time();
    }

    public function unlock(Request $request): void
    {
        // Production: verify both CSRF and independent operations password.
        $this->operations->assertAuthorized($request, true);
        $request->getSession()->migrate(true);
        $request->getSession()->set(self::SESSION_KEY, time() + 900);
    }

    public function lock(Request $request): void
    {
        $this->operations->assertAuthorized($request, false);
        $request->getSession()->remove(self::SESSION_KEY);
    }

    public function assertCanWrite(Request $request): void
    {
        $this->operations->assertAuthorized($request, false);
        if (!$this->isUnlocked($request)) {
            throw new AccessDeniedHttpException('Sessão administrativa expirada. Desbloqueie o painel novamente.');
        }
    }

    public function fingerprint(int $id, string $source, string $field, mixed $value): string
    {
        // Use the already configured, server-side operations secret. Do not
        // introduce a second password or persist a secret in the session.
        $key = trim((string) getenv('PRODUCAO_ACTION_TOKEN'));
        if (strlen($key) < 32) {
            throw new \LogicException('Credencial de operações indisponível para a integridade do formulário.');
        }
        return hash_hmac('sha256', json_encode([$id, $source, $field, (string) ($value ?? '')], JSON_THROW_ON_ERROR), $key);
    }
}
