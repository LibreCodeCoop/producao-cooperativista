<?php

declare(strict_types=1);

namespace App\Tests\Service\Akaunting;

use App\Service\Akaunting\CapitalSocialSettings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CapitalSocialSettingsTest extends TestCase
{
    private string|false $originalCategory;
    private string|false $originalItem;

    protected function setUp(): void
    {
        $this->originalCategory = getenv('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID');
        $this->originalItem = getenv('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID');
    }

    protected function tearDown(): void
    {
        $this->restore('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID', $this->originalCategory);
        $this->restore('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID', $this->originalItem);
    }

    public function testUsesExplicitPerCompanyMappings(): void
    {
        putenv('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID=25');
        putenv('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID=11');
        $config = CapitalSocialSettings::fromEnvironment();
        self::assertSame(25, $config->categoryId);
        self::assertSame(11, $config->itemId);
    }

    public function testDoesNotAssumeLibreCodeIdsWhenUnconfigured(): void
    {
        putenv('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID');
        putenv('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID');
        CapitalSocialSettings::fromEnvironment();
    }

    public function testRejectsInvalidItemIdRatherThanInventingMapping(): void
    {
        putenv('AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID=25');
        putenv('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID=0');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AKAUNTING_CAPITAL_SOCIAL_ITEM_ID');
        CapitalSocialSettings::fromEnvironment();
    }

    private function restore(string $key, string|false $value): void
    {
        putenv($value === false ? $key : $key . '=' . $value);
    }
}
