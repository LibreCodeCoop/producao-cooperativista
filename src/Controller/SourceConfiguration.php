<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Operator's read-only source registry. Never stores CPF, weight, secret,
 * or duplicated source settings in the ETL database.
 */
/** @psalm-suppress UnusedClass Used by Symfony attribute routing. */
final class SourceConfiguration extends AbstractController
{
    #[Route('/configuracao', methods: ['GET'])]
    public function index(): Response
    {
        $profileUrl = self::publicSourceUrl((string) getenv('PROFILE_FIELDS_API_BASE_URL'));
        $kimaiUrl = preg_replace('~/api/?$~', '', (string) getenv('KIMAI_API_BASE_URL'));

        return $this->render('configuracao.index.html.twig', [
            'nextcloud_url' => $profileUrl,
            'nextcloud_ready' => $profileUrl !== null
                && (string) getenv('PROFILE_FIELDS_AUTH_USER') !== ''
                && (string) getenv('PROFILE_FIELDS_AUTH_TOKEN') !== '',
            'kimai_url' => self::publicSourceUrl((string) $kimaiUrl),
            'kimai_ready' => (string) getenv('KIMAI_API_TOKEN') !== ''
                && (string) getenv('KIMAI_API_BASE_URL') !== '',
        ]);
    }

    private static function publicSourceUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || !str_contains((string) $parts['host'], '.')
            || filter_var($parts['host'], FILTER_VALIDATE_IP)) {
            return null;
        }
        // Never expose URL userinfo, query strings or credentials in the UI.
        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}
