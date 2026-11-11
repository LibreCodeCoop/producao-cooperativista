<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class CalculationActionGuard
{
    private const string CSRF_SESSION_KEY = 'production_calculation_csrf';

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
    public function assertAuthorized(Request $request): void
    {
        $received = $request->request->getString('_token');
        if ($received === '' || !hash_equals($this->token($request), $received)) {
            throw new AccessDeniedHttpException('Formulário expirado ou token CSRF inválido. Atualize a página e tente novamente.');
        }

    }
}
