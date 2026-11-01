<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use Symfony\Component\Console\Command\Command;

final readonly class ExecutionOutcome
{
    public function __construct(
        public int $exitCode,
        public string $executionId,
        public string $details = '',
    ) {
    }

    public function event(): string
    {
        return $this->exitCode === Command::SUCCESS ? 'done' : 'failed';
    }

    public function message(): string
    {
        if ($this->exitCode === Command::SUCCESS) {
            return sprintf('Cálculo concluído. Execução %s.', $this->executionId);
        }

        $guide = FailureGuide::diagnose($this->details);
        $stage = '';
        foreach (['importação', 'configuração', 'cálculo', 'gravação', 'exportação'] as $phase) {
            if (str_contains(mb_strtolower($this->details), $phase)) {
                $stage = ' Etapa: ' . $phase . '.';
                break;
            }
        }
        $userId = '';
        if (in_array($guide->code, ['PROFILE_DEPENDENTS', 'PROFILE_SOURCE'], true)
            && preg_match('/usuário Kimai #([1-9][0-9]*)/u', $this->details, $matches)) {
            $userId = sprintf(' Cadastro Kimai #%s.', $matches[1]);
        }
        return sprintf(
            'Execução %s: erro %s.%s %s%s Como resolver: %s Consulte os detalhes privados no log do servidor.',
            $this->executionId,
            $guide->code,
            $stage,
            $guide->summary,
            $userId,
            $guide->instructions
        );
    }
}
