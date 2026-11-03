<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Import;

use App\Helper\Dates;
use App\Helper\ArrayValue;
use App\Service\Cooperative\ProfileRegistry;
use DateTime;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use RuntimeException;
use Throwable;

/**
 * Runs the connector contract in explicit dependency order. This stage never
 * calculates cooperative production or writes to Akaunting's financial API.
 */
final class SourcePipeline
{
    /** @var list<SourceStep> */
    private array $steps;

    /** @param iterable<SourceStep> $steps */
    /** @psalm-suppress PossiblyUnusedMethod Instantiated through Symfony DI */
    public function __construct(
        iterable $steps,
        private LoggerInterface $logger,
        private Dates $dates,
        private ?ProfileRegistry $profiles = null,
    ) {
        $names = [];
        $this->steps = [];
        foreach ($steps as $step) {
            if (!$step instanceof SourceStep || isset($names[$step->name()]) || $step->name() === '') {
                throw new InvalidArgumentException('Etapa de importação duplicada ou inválida.');
            }
            $names[$step->name()] = true;
            $this->steps[] = $step;
        }
        if ($this->steps === []) {
            throw new InvalidArgumentException('Não há conectores configurados para importação.');
        }
    }

    /** @return list<string> */
    /** @psalm-suppress PossiblyUnusedMethod Inspected by integration checks */
    public function stageNames(): array
    {
        return array_map(static fn (SourceStep $step): string => $step->name(), $this->steps);
    }

    /** @return list<string> */
    public function selectedStageNames(): array
    {
        if ($this->profiles === null) {
            return $this->stageNames();
        }

        $selected = $this->profiles->active()->sources();
        $available = $this->stageNames();
        $unavailable = array_diff($selected, $available);
        if ($unavailable !== []) {
            throw new InvalidArgumentException(sprintf(
                'Conector requerido pelo perfil cooperativo indisponível: %s.',
                implode(', ', $unavailable)
            ));
        }

        // Preserve the dependency order defined in the existing Symfony pipeline.
        return array_values(array_filter($available, static fn (string $id): bool => in_array($id, $selected, true)));
    }

    public function run(DateTime $competence): void
    {
        $this->dates->setInicio($competence);
        $normalized = $this->dates->getInicio();
        $enabled = array_fill_keys($this->selectedStageNames(), true);
        $total = count($enabled);
        $position = 0;
        foreach ($this->steps as $step) {
            if (!isset($enabled[$step->name()])) {
                continue;
            }
            ++$position;
            $label = match ($step->name()) {
                'kimai.customers' => 'Clientes do Kimai',
                'kimai.projects' => 'Projetos do Kimai',
                'kimai.users', 'kimai.users.basic' => 'Cooperados do Kimai e perfis Nextcloud',
                'kimai.timesheets' => 'Horas trabalhadas do Kimai',
                'akaunting.categories' => 'Categorias do Akaunting',
                'akaunting.taxes' => 'Tributos do Akaunting',
                'akaunting.documents' => 'Documentos financeiros do Akaunting',
                'akaunting.transactions' => 'Transações financeiras do Akaunting',
                default => 'Fonte ' . $step->name(),
            };
            $this->logger->info(new ArrayValue([
                'event' => 'info',
                'data' => sprintf('Etapa %d de %d: %s.', $position, $total, $label),
            ]));
            $this->logger->info('Importando dados da etapa {step} para a competência {month}', [
                'step' => $step->name(),
                'month' => $normalized->format('Y-m'),
            ]);
            try {
                $step->synchronize(clone $normalized);
            } catch (Throwable $error) {
                // Only a whitelisted error code and safe member identity can
                // leave the ETL process. The exception and private details
                // remain available in server logs, not in the SSE stream.
                $profileHint = '';
                if ($error instanceof \DomainException && preg_match(
                    '/^(PROFILE_(?:SOURCE|DEPENDENTS|KIMAI_LINK|ACCOUNTING_LINK|ACCOUNTING_INVALID|TAX_MISMATCH|TAX_MISSING)):/D',
                    $error->getMessage(),
                    $codeMatch,
                ) === 1) {
                    $profileHint = ' ' . $codeMatch[1] . ':';
                    if (preg_match(
                        "/usuário Kimai #([1-9][0-9]*)(?: \(([\p{L}\p{M} .'-]{1,60})\))?/u",
                        $error->getMessage(),
                        $memberMatch,
                    ) === 1) {
                        $profileHint .= ' usuário Kimai #' . $memberMatch[1];
                        if (isset($memberMatch[2]) && trim($memberMatch[2]) !== '') {
                            $profileHint .= ' (' . trim($memberMatch[2]) . ')';
                        }
                        $profileHint .= '.';
                    }
                }
                throw new RuntimeException(sprintf(
                    'Falha na importação de %s, etapa %s.%s Verifique a origem e os logs antes de reexecutar.',
                    $normalized->format('Y-m'),
                    $step->name(),
                    $profileHint !== '' ? $profileHint
                        : ($error instanceof TransportExceptionInterface
                            ? ' SOURCE_CONNECTION_FAILED: serviço de origem não respondeu.'
                            : ''),
                ), 0, $error);
            }
        }
    }
}
