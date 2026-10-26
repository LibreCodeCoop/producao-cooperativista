<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Tests\Helper;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LogConfigurationTest extends TestCase
{
    public function testLogsRotateAcrossDaysAndErrorsRemainLonger(): void
    {
        $settings = Yaml::parseFile(dirname(__DIR__, 3) . '/config/packages/monolog.yaml');
        $main = $settings['monolog']['handlers']['main'];
        $errors = $settings['monolog']['handlers']['errors'];

        $this->assertSame('rotating_file', $main['type']);
        $this->assertSame('rotating_file', $errors['type']);
        $this->assertGreaterThanOrEqual(30, $main['max_files']);
        $this->assertGreaterThanOrEqual(90, $errors['max_files']);
        $this->assertSame('error', $errors['level']);
    }
}
