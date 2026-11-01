<?php

declare(strict_types=1);

namespace App\Service\Config;

/** Display-only cooperative identity. Every installation can override it. */
final class BrandSettings
{
    public const string DEFAULT_NAME = 'LibreCode';
    public const string DEFAULT_LOGO_URL = 'https://librecode.coop/assets/images/logo/librecode.png';
    public const string DEFAULT_DISPLAY_MODE = 'logo_and_text';
    public const array DISPLAY_MODES = ['logo_and_text', 'logo_only', 'text_only'];

    /** @psalm-suppress PossiblyUnusedMethod Accessed as a Twig global. */
    public function getName(): string
    {
        $name = trim((string) getenv('APP_BRAND_NAME'));

        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** @psalm-suppress PossiblyUnusedMethod Accessed as a Twig global. */
    public function getLogoUrl(): string
    {
        $url = trim((string) getenv('APP_BRAND_LOGO_URL'));

        return self::isValidLogoUrl($url) ? $url : self::DEFAULT_LOGO_URL;
    }

    /** @psalm-suppress PossiblyUnusedMethod Accessed as a Twig global. */
    public function getDisplayMode(): string
    {
        $mode = trim((string) getenv('APP_BRAND_DISPLAY_MODE'));

        return in_array($mode, self::DISPLAY_MODES, true) ? $mode : self::DEFAULT_DISPLAY_MODE;
    }

    public static function isValidLogoUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
