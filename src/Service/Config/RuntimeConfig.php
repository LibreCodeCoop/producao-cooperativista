<?php

declare(strict_types=1);

namespace App\Service\Config;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RuntimeConfig
{
    /** @psalm-suppress PossiblyUnusedMethod Invoked by Symfony's DI container. */
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $root,
    ) {
    }

    /** @return array{version:int, values:array<string,string>} */
    public function snapshot(): array
    {
        return self::read($this->root);
    }

    public static function apply(string $root): void
    {
        foreach (self::read($root)['values'] as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $key)) {
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /** @return array{version:int, values:array<string,string>} */
    private static function read(string $root): array
    {
        $path = $root . '/var/admin-config/settings.json';
        if (!is_file($path)) {
            return ['version' => 0, 'values' => []];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Configuração protegida indisponível.');
        }
        $result = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($result) || !is_int($result['version'] ?? null)
            || !is_array($result['values'] ?? null)) {
            throw new RuntimeException('Formato de configuração inválido.');
        }
        foreach ($result['values'] as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Configuração protegida inválida.');
            }
        }
        return $result;
    }

    /** @param array<string,string> $changes */
    public function save(array $changes, int $expectedVersion): int
    {
        $dir = $this->root . '/var/admin-config';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Diretório privado não pode ser criado.');
        }
        $lock = fopen($dir . '/settings.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Não foi possível bloquear a configuração.');
        }
        chmod($dir . '/settings.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Configuração ocupada.');
            }
            $current = self::read($this->root);
            if ($current['version'] !== $expectedVersion) {
                throw new \DomainException('Configuração alterada por outra sessão. Recarregue a página.');
            }
            $current['values'] = array_replace($current['values'], $changes);
            $current['version']++;
            $tmp = tempnam($dir, 'settings-');
            if ($tmp === false) {
                throw new RuntimeException('Arquivo temporário indisponível.');
            }
            try {
                $json = json_encode($current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if (file_put_contents($tmp, $json, LOCK_EX) === false) {
                    throw new RuntimeException('Falha ao persistir configuração.');
                }
                chmod($tmp, 0600);
                if (!rename($tmp, $dir . '/settings.json')) {
                    throw new RuntimeException('Não foi possível publicar a configuração.');
                }
            } finally {
                if (is_file($tmp)) {
                    unlink($tmp);
                }
            }
            return $current['version'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
