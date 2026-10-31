<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Config\RuntimeConfig;
use App\Service\Calculation\CalculationActionGuard;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class SystemConfiguration extends AbstractController
{
    public function __construct(
        private RuntimeConfig $config,
        private CalculationActionGuard $guard,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/configuracao/integracoes', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->assertAdmin($request);
        $groups = $this->groups();
        foreach ($groups as &$group) {
            $group['fields'] = [];
            $group['configuredCount'] = 0;
            foreach ($group['keys'] as $key) {
                $value = (string) getenv($key);
                $secret = self::isSecret($key);
                if ($value !== '') {
                    $group['configuredCount']++;
                }
                $group['fields'][] = [
                    'key' => $key,
                    'value' => $secret ? '' : $value,
                    'secret' => $secret,
                    'present' => $value !== '',
                    'json' => self::isJson($key),
                    'critical' => self::isCritical($key),
                ];
            }
        }
        unset($group);
        return $this->noCache($this->render('configuracao.integracoes.html.twig', [
            'requirements' => $groups,
            'token' => $this->guard->token($request),
            'version' => $this->config->snapshot()['version'],
        ]));
    }

    /** @return list<array{title:string,purpose:string,keys:list<string>}> */
    private function groups(): array
    {
        return [
            [
                'title' => 'Nextcloud · Profile Fields',
                'purpose' => 'Cadastro de referência: CPF/CNPJ, peso e dependentes.',
                'keys' => ['PROFILE_FIELDS_API_BASE_URL', 'PROFILE_FIELDS_AUTH_USER', 'PROFILE_FIELDS_AUTH_TOKEN',
                    'PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY', 'PROFILE_FIELDS_WEIGHT_FIELD_KEY',
                    'PROFILE_FIELDS_DEPENDENTS_FIELD_KEY'],
            ],
            [
                'title' => 'Kimai',
                'purpose' => 'Contas de trabalho e apontamentos de horas.',
                'keys' => ['KIMAI_API_BASE_URL', 'KIMAI_API_TOKEN'],
            ],
            [
                'title' => 'Akaunting',
                'purpose' => 'Contatos, lançamentos e documentos financeiros.',
                'keys' => [
                    'AKAUNTING_API_BASE_URL', 'AKAUNTING_AUTH_USER',
                    'AKAUNTING_AUTH_TOKEN', 'AKAUNTING_COMPANY_ID', 'DATABASE_AKAUNTING_URL',
                ],
            ],
            [
                'title' => 'Apuração cooperativista',
                'purpose' => 'Banco de importação, perfil, categorias, itens e parâmetros financeiros.',
                'keys' => [
                    'DATABASE_URL', 'PRODUCAO_STAGING_DATABASE', 'COOPERATIVE_PROFILE', 'CNPJ_COMPANY',
                    'CNPJ_CLIENTES_INTERNOS', 'DIA_UTIL_PAGAMENTO', 'PERCENTUAL_MAXIMO', 'HOLYDAYS_LIST', 'LOCALE',
                ],
            ],
            [
                'title' => 'Categorias e retenções financeiras',
                'purpose' => 'IDs do plano de contas e mapeamentos fiscais utilizados nos documentos.',
                'keys' => [
                    'AKAUNTING_PRODUCAO_COOPERATIVISTA_CATEGORY_ID',
                    'AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS',
                    'AKAUNTING_FRRA_CATEGORY_ID', 'AKAUNTING_ADIANTAMENTO_CATEGORY_ID',
                    'AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID',
                    'AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID',
                    'AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID',
                    'AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID',
                    'AKAUNTING_IMPOSTOS_ITEM_ID',
                    'AKAUNTING_IMPOSTOS_COFINS', 'AKAUNTING_IMPOSTOS_INSS_IRRF',
                    'AKAUNTING_IMPOSTOS_IRPF_RETIDO', 'AKAUNTING_IMPOSTOS_ISS',
                    'AKAUNTING_IMPOSTOS_PIS', 'AKAUNTING_PARENT_DESCONTO_LIQUIDO_CATEGORY_ID',
                    'AKAUNTING_FRRA_MES_PADRAO', 'AKAUNTING_RESGATE_SALDO_IRPF_MES_PADRAO',
                    'AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID', 'AKAUNTING_CAPITAL_SOCIAL_ITEM_ID',
                ],
            ],
        ];
    }

    #[Route('/configuracao/integracoes/revelar', methods: ['POST'])]
    public function reveal(Request $request): Response
    {
        $actor = $this->assertAdmin($request);
        $this->guard->assertAuthorized($request);
        $key = $request->request->getString('key');
        if (!in_array($key, $this->allowedKeys(), true) || !self::isSecret($key)) {
            return $this->noCache(new JsonResponse(['error' => 'Parâmetro não autorizado.'], 400));
        }
        $this->logger->notice('Segredo de integração consultado.', ['key' => $key, 'actor' => $actor]);
        return $this->noCache(new JsonResponse(['value' => (string) getenv($key)]));
    }

    #[Route('/configuracao/integracoes/salvar', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $actor = $this->assertAdmin($request);
        $this->guard->assertAuthorized($request);
        try {
            $groupId = $request->request->getString('group');
            $group = ctype_digit($groupId) ? ($this->groups()[(int) $groupId] ?? null) : null;
            $raw = $request->request->getString('changes');
            $version = $request->request->getString('version');
            if ($group === null || strlen($raw) > 20000 || !ctype_digit($version)) {
                throw new \InvalidArgumentException('Formulário inválido.');
            }
            $changes = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($changes) || $changes === []) {
                throw new \InvalidArgumentException('Nenhum campo alterado.');
            }
            $valid = [];
            foreach ($changes as $key => $value) {
                if (!is_string($key) || !in_array($key, $group['keys'], true) || !is_string($value)) {
                    throw new \InvalidArgumentException('Parâmetro não autorizado.');
                }
                $valid[$key] = self::validateValue($key, $value);
            }
            $nextVersion = $this->config->save($valid, (int) $version);
            $this->logger->notice('Configuração operacional alterada.', [
                'actor' => $actor, 'keys' => array_keys($valid),
            ]);
            return $this->noCache(new JsonResponse([
                'version' => $nextVersion,
                'changedKeys' => array_keys($valid),
                'message' => 'Configuração salva. Novas requisições usarão os valores atualizados.',
            ]));
        } catch (\DomainException $error) {
            return $this->noCache(new JsonResponse(['error' => $error->getMessage()], 409));
        } catch (\RuntimeException $error) {
            $this->logger->error('Falha ao gravar configuração privada.', ['class' => $error::class]);
            return $this->noCache(new JsonResponse(['error' => 'Não foi possível gravar a configuração privada.'], 503));
        } catch (\Throwable) {
            return $this->noCache(new JsonResponse(['error' => 'Parâmetros inválidos. Confira os valores e tente novamente.'], 400));
        }
    }

    private static function validateValue(string $key, string $value): string
    {
        if (strlen($value) > 4096 || preg_match('/[\\x00-\\x1F]/', $value)) {
            throw new \InvalidArgumentException('Parâmetro inválido.');
        }
        if ($value === '') {
            return '';
        }
        if (self::isJson($key)) {
            $parsed = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($parsed) || $parsed === []) {
                throw new \InvalidArgumentException('Mapeamento JSON inválido.');
            }
        }
        if ($key === 'COOPERATIVE_PROFILE' && !in_array($value, ['librecode', 'hours'], true)) {
            throw new \InvalidArgumentException('Perfil não permitido.');
        }
        if (str_ends_with($key, '_ID') || $key === 'AKAUNTING_COMPANY_ID') {
            if (!ctype_digit($value)) {
                throw new \InvalidArgumentException('Identificador inválido.');
            }
        }
        if (str_ends_with($key, '_MES_PADRAO') && (!ctype_digit($value) || (int)$value < 1 || (int)$value > 12)) {
            throw new \InvalidArgumentException('Mês inválido.');
        }
        if ($key === 'DIA_UTIL_PAGAMENTO' && (!ctype_digit($value) || (int)$value < 1 || (int)$value > 31)) {
            throw new \InvalidArgumentException('Dia de pagamento inválido.');
        }
        if ($key === 'PERCENTUAL_MAXIMO' && (!is_numeric($value) || (float)$value < 0 || (float)$value > 100)) {
            throw new \InvalidArgumentException('Percentual inválido.');
        }
        if (str_ends_with($key, '_BASE_URL') && !preg_match('~^https?://[^\\s]+$~D', $value)) {
            throw new \InvalidArgumentException('URL inválida.');
        }
        if (str_starts_with($key, 'DATABASE_') && str_ends_with($key, '_URL') && !str_contains($value, '://')) {
            throw new \InvalidArgumentException('DSN inválida.');
        }
        return $value;
    }

    private function assertAdmin(Request $request): string
    {
        $user = $request->server->getString('REMOTE_USER');
        $allowed = array_filter(array_map(trim(...), explode(',', (string) getenv('CONFIG_ADMIN_USERS'))));
        if ($user === '' || !in_array($user, $allowed, true)) {
            throw new AccessDeniedHttpException('Administração de integrações não autorizada.');
        }
        return $user;
    }

    /** @return list<string> */
    private function allowedKeys(): array
    {
        $keys = [];
        foreach ($this->groups() as $group) {
            array_push($keys, ...$group['keys']);
        }
        return $keys;
    }

    private static function isSecret(string $key): bool
    {
        return in_array($key, [
            'DATABASE_URL', 'DATABASE_AKAUNTING_URL', 'PROFILE_FIELDS_AUTH_TOKEN',
            'KIMAI_API_TOKEN', 'AKAUNTING_AUTH_TOKEN',
        ], true);
    }

    private static function isJson(string $key): bool
    {
        return $key === 'AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS'
            || (str_starts_with($key, 'AKAUNTING_IMPOSTOS_') && $key !== 'AKAUNTING_IMPOSTOS_ITEM_ID');
    }

    private static function isCritical(string $key): bool
    {
        return str_starts_with($key, 'DATABASE_') || str_starts_with($key, 'AKAUNTING_IMPOSTOS_')
            || in_array($key, ['COOPERATIVE_PROFILE', 'PRODUCAO_STAGING_DATABASE', 'CNPJ_COMPANY'], true);
    }

    private function noCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');
        return $response;
    }
}
