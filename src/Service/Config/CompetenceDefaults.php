<?php

declare(strict_types=1);

namespace App\Service\Config;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Operational month is derived from the closing lag; a report without an
 * explicit month shows the last imported work month, not an empty next month.
 */
final class CompetenceDefaults
{
    /** @psalm-suppress PossiblyUnusedMethod Constructed by Symfony DI. */
    public function __construct(
        #[Autowire(service: 'doctrine.dbal.main_connection')]
        private Connection $main,
    ) {
    }

    public static function workMonth(?DateTimeImmutable $today = null, ?int $lag = null): string
    {
        $today ??= new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
        $lag ??= self::closingLag();

        return $today->modify('first day of this month')
            ->modify(sprintf('-%d months', $lag))->format('Y-m');
    }

    public static function closingLag(): int
    {
        $value = getenv('PRODUCAO_FECHAMENTO_DEFASAGEM_MESES');
        if ($value === false || $value === '') {
            return 2;
        }
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 12) {
            throw new \DomainException('A defasagem do fechamento deve ser de 1 a 12 meses.');
        }
        return (int) $value;
    }

    public function importedMonth(): ?string
    {
        try {
            $latest = $this->main->fetchOne('SELECT MAX(begin) FROM timesheet');
        } catch (\Doctrine\DBAL\Exception) {
            // Query-independent pages must remain accessible when the ETL
            // database is not initialized; report calculations still fail.
            return null;
        }

        if (!is_string($latest) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])-/', $latest)) {
            return null;
        }

        return substr($latest, 0, 7);
    }

    public function reportMonth(): string
    {
        return $this->importedMonth() ?? self::workMonth();
    }
}
