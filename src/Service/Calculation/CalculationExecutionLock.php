<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

use RuntimeException;
use Symfony\Component\HttpKernel\KernelInterface;

final class CalculationExecutionLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private KernelInterface $kernel)
    {
    }

    public function acquire(): void
    {
        if ($this->handle !== null) {
            throw new RuntimeException('Já existe um cálculo em execução nesta instância.');
        }

        $directory = $this->kernel->getProjectDir() . '/var/locks';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de travamento do cálculo.');
        }

        $handle = @fopen($directory . '/producao.lock', 'c+');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o bloqueio de execução de produção.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Já existe um cálculo em execução. Acompanhe a execução anterior antes de repetir.');
        }
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        $handle = $this->handle;
        $this->handle = null;
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function __destruct()
    {
        $this->release();
    }
}
