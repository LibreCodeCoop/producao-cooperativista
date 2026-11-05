<?php

declare(strict_types=1);

namespace App\Tests\Service\Config;

use App\Service\Config\BrandSettings;
use PHPUnit\Framework\TestCase;

final class BrandSettingsTest extends TestCase
{
    public function testDefaultsAndCustomIdentity(): void
    {
        $previousName = getenv('APP_BRAND_NAME');
        $previousLogo = getenv('APP_BRAND_LOGO_URL');
        try {
            putenv('APP_BRAND_NAME');
            putenv('APP_BRAND_LOGO_URL');
            $settings = new BrandSettings();
            self::assertSame('LibreCode', $settings->getName());
            self::assertSame('https://librecode.coop/assets/images/logo/librecode.png', $settings->getLogoUrl());
            putenv('APP_BRAND_NAME=Outra cooperativa');
            putenv('APP_BRAND_LOGO_URL=https://exemplo.coop/assets/brand.png');
            self::assertSame('Outra cooperativa', $settings->getName());
            self::assertSame('https://exemplo.coop/assets/brand.png', $settings->getLogoUrl());
        } finally {
            putenv($previousName === false ? 'APP_BRAND_NAME' : 'APP_BRAND_NAME=' . $previousName);
            putenv($previousLogo === false ? 'APP_BRAND_LOGO_URL' : 'APP_BRAND_LOGO_URL=' . $previousLogo);
        }
    }

    public function testDisplayModeDefaultsAndAcceptsOnlyThreeOptions(): void
    {
        $previous = getenv('APP_BRAND_DISPLAY_MODE');
        try {
            putenv('APP_BRAND_DISPLAY_MODE');
            $settings = new BrandSettings();
            self::assertSame('logo_and_text', $settings->getDisplayMode());

            foreach (BrandSettings::DISPLAY_MODES as $mode) {
                putenv('APP_BRAND_DISPLAY_MODE=' . $mode);
                self::assertSame($mode, $settings->getDisplayMode());
            }

            putenv('APP_BRAND_DISPLAY_MODE=invalid');
            self::assertSame('logo_and_text', $settings->getDisplayMode());
        } finally {
            putenv($previous === false ? 'APP_BRAND_DISPLAY_MODE' : 'APP_BRAND_DISPLAY_MODE=' . $previous);
        }
    }

    public function testRejectsUnsafeBrandUrls(): void
    {
        foreach (['javascript:alert(1)', 'http://exemplo.coop/a.png', '//exemplo.coop/a.png',
            'https://name:password@exemplo.coop/logo.png', 'data:image/png;base64,YWJj'] as $url) {
            self::assertFalse(BrandSettings::isValidLogoUrl($url), $url);
        }
    }
}
