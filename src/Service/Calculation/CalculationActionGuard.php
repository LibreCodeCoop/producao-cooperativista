<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CalculationActionGuard
{
    private const string CSRF_SESSION_KEY = 'production_calculation_csrf';

    public function __construct(private KernelInterface $kernel)
    {
    }

    public function token(Request $request): string
    {
        $session = $request->getSession();
        $token = $session->get(self::CSRF_SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $session->set(self::CSRF_SESSION_KEY, $token);
        }
        return $token;
    }

    /**
     * @throws AccessDeniedHttpException
     */
    public function assertAuthorized(Request $request, bool $modifiesFinancialData): void
    {
        $received = $request->request->getString('_token');
        if ($received === '' || !hash_equals($this->token($request), $received)) {
            throw new AccessDeniedHttpException('Formulário expirado ou token CSRF inválido. Atualize a página e tente novamente.');
        }

        if (!$modifiesFinancialData || $this->kernel->getEnvironment() !== 'prod') {
            return;
        }

        // The application does not include an authentication system. Require an
        // independent, secret admin credential before importing or writing.
        $expected = trim((string) getenv('PRODUCAO_ACTION_TOKEN'));
        $provided = $request->request->getString('action_token');
        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            throw new AccessDeniedHttpException(
                'Operação financeira bloqueada. Configure PRODUCAO_ACTION_TOKEN e informe a senha de operação.'
            );
        }
    }
}
