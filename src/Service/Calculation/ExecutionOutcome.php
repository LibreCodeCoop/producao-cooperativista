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
        $lower = mb_strtolower($this->details);
        $stage = '';
        // Stage identifiers come from our source adapters, not from external
        // service responses. Convert them into names useful to operators.
        foreach ([
            'kimai.users' => 'Cooperados do Kimai e perfis Nextcloud',
            'kimai.customers' => 'Clientes do Kimai',
            'kimai.projects' => 'Projetos do Kimai',
            'akaunting.documents' => 'Documentos financeiros do Akaunting',
            'kimai.timesheets' => 'Apontamentos do Kimai',
            'akaunting.transactions' => 'Transações financeiras do Akaunting',
            'akaunting.categories' => 'Categorias do Akaunting',
            'akaunting.taxes' => 'Tributos do Akaunting',
        ] as $id => $description) {
            if (str_contains($lower, 'etapa ' . $id)) {
                $stage = ' Etapa: ' . $description . '.';
                break;
            }
        }
        if ($stage === '') {
            foreach (['importação', 'configuração', 'cálculo', 'gravação', 'exportação'] as $phase) {
                if (str_contains($lower, $phase)) {
                    $stage = ' Etapa: ' . $phase . '.';
                    break;
                }
            }
        }


        $member = '';
        if (str_starts_with($guide->code, 'PROFILE_')
            && preg_match(
                '/usuário Kimai #([1-9][0-9]*)(?: \(([\p{L}\p{M} .\'-]{1,60})\))?/u',
                $this->details,
                $matches
            ) === 1) {
            $label = trim((string) ($matches[2] ?? ''));
            $member = $label !== ''
                ? sprintf(' Cooperado: %s (Kimai #%s).', $label, $matches[1])
                : sprintf(' Cooperado: Kimai #%s (nome não disponível).', $matches[1]);
        }

        return sprintf(
            'Execução %s: erro %s.%s %s%s Como resolver: %s Consulte os detalhes privados no log do servidor.',
            $this->executionId,
            $guide->code,
            $stage,
            $guide->summary,
            $member,
            $guide->instructions,
        );
    }
}
