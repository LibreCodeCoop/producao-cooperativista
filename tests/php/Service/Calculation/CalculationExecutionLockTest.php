<?php

declare(strict_types=1);

namespace App\Tests\Service\Calculation;

use App\Service\Calculation\CalculationExecutionLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\KernelInterface;

final class CalculationExecutionLockTest extends TestCase
{
    public function testConcurrentExecutionIsRejectedAndLockCanBeReused(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn(dirname(__DIR__, 4));

        $first = new CalculationExecutionLock($kernel);
        $second = new CalculationExecutionLock($kernel);
        $first->acquire();
        try {
            try {
                $second->acquire();
                self::fail('Concurrent calculation was allowed.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('Já existe', $error->getMessage());
            }
        } finally {
            $first->release();
        }

        $second->acquire();
        $second->release();
        $this->addToAssertionCount(1);
    }
}
