<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Integration\CooperativeSources;
use App\Service\Calculation\CalculationActionGuard;
use App\Service\Integration\MemberParameterValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class SourceConfiguration extends AbstractController
{
    public function __construct(
        private CooperativeSources $sources,
        private CalculationActionGuard $guard,
        private MemberParameterValidator $validator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/configuracao', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $month = $this->month($request->query->getString('competencia'));
        $data = [
            'token' => $this->guard->token($request),
            'month' => $month,
            'users' => [],
            'member' => null,
            'services' => [],
            'error' => '',
            'notifications' => $this->popNotice($request),
        ];
        try {
            $users = $this->sources->users();
            $hours = $this->sources->monthlyHours($month);
            $importedWeights = $this->sources->importedWeights();
            foreach ($users as &$row) {
                $row['hours'] = round(($hours[$row['id']] ?? 0) / 3600, 2);
                $row['participates'] = ($hours[$row['id']] ?? 0) > 0;
                $row['imported_weight'] = $importedWeights[$row['id']] ?? null;
            }
            unset($row);
            usort($users, static function (array $a, array $b): int {
                $rank = static fn (array $row): int => $row['system'] ? 2 : ($row['enabled'] ? 0 : 1);
                return $rank($a) <=> $rank($b)
                    ?: $b['hours'] <=> $a['hours']
                    ?: strcasecmp($a['alias'], $b['alias']);
            });
            $data['users'] = $users;
            // The editor is loaded on demand rather than querying three
            // source systems during every roster request.
        } catch (\Throwable $error) {
            $this->logger->error('Painel de configuração: falha de consulta.', ['error_class' => $error::class]);
            $data['error'] = 'Não foi possível consultar todas as fontes. Confira conexão, permissões e logs do servidor.';
        }

        return $this->privatePage($data);
    }

    #[Route('/configuracao/cooperado/{id}/editar', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function edit(Request $request, int $id): Response
    {
        $month = $this->month($request->query->getString('competencia'));
        try {
            $listed = array_filter(
                $this->sources->users(),
                static fn (array $row): bool => $row['id'] === $id && !$row['system'],
            );
            if ($listed === []) {
                return new Response('Cooperado não encontrado ou não editável.', Response::HTTP_NOT_FOUND);
            }
            $member = $this->sources->user($id);
            $data = [
                'token' => $this->guard->token($request),
                'month' => $month,
                'member' => $member,
                'services' => $this->serviceFields($member),
            ];
            $template = $request->isXmlHttpRequest()
                ? 'configuracao/_member_editor.html.twig'
                : 'configuracao.cooperado.html.twig';
            $response = $this->render($template, $data);
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Referrer-Policy', 'no-referrer');

            return $response;
        } catch (\Throwable $error) {
            $this->logger->error('Não foi possível abrir editor de cooperado.', [
                'member_id' => $id, 'error_class' => $error::class,
            ]);
            return new Response(
                'Não foi possível consultar as fontes deste cooperado. Confira as conexões e tente novamente.',
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    /**
     * Read a single field lazily when its table row is visible.
     * The endpoint never returns CPF, Nextcloud credentials or bulk profiles.
     */
    #[Route('/configuracao/cooperado/{id}/peso', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function weight(int $id): JsonResponse
    {
        try {
            $listed = array_filter(
                $this->sources->users(),
                static fn (array $row): bool => $row['id'] === $id && !$row['system'],
            );
            if ($listed === []) {
                return $this->weightResponse(['error' => 'Cooperado não encontrado ou não editável.'], Response::HTTP_NOT_FOUND);
            }

            $member = $this->sources->user($id);
            $profile = $this->sources->nextcloudForMember([
                'username' => $member['username'],
                'preferences' => $member['preferences'],
            ]);
            if (!$profile['exists']) {
                return $this->weightResponse([
                    'error' => 'Conta Nextcloud não vinculada. Confira o perfil completo antes de editar.',
                ], Response::HTTP_NOT_FOUND);
            }

            $current = (string) ($profile['values'][self::profileKey('weight')] ?? '');
            return $this->weightResponse([
                'value' => $current,
                'expected' => self::revision($id, 'nextcloud', 'weight', $current),
            ]);
        } catch (\Throwable $error) {
            $this->logger->error('Não foi possível consultar o peso no Nextcloud.', [
                'member_id' => $id,
                'error_class' => $error::class,
            ]);
            return $this->weightResponse([
                'error' => 'Consulta ao Nextcloud indisponível. Verifique o perfil completo ou tente novamente.',
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /** @param array<string, string> $data */
    private function weightResponse(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    #[Route('/configuracao/cooperado/{id}/{source}', requirements: ['id' => '\\d+', 'source' => 'nextcloud|kimai|akaunting'], methods: ['POST'])]
    public function save(Request $request, int $id, string $source): Response
    {
        // HTTP Basic is enforced by Nginx. Only reject forged cross-site POSTs here.
        $this->guard->assertAuthorized($request);
        $field = $request->request->getString('field');
        $allowed = [
            'nextcloud' => ['tax_number', 'weight', 'dependents'],
            'kimai' => ['tax_number'],
            'akaunting' => ['tax_number'],
        ];
        if (!in_array($field, $allowed[$source] ?? [], true)) {
            return $this->saveError($request, 'Campo não autorizado.', Response::HTTP_BAD_REQUEST);
        }

        $month = $this->month($request->request->getString('competencia'));
        try {
            $listed = array_filter(
                $this->sources->users(),
                static fn (array $row): bool => $row['id'] === $id && !$row['system']
            );
            if ($listed === []) {
                throw new \DomainException('Cooperado não localizado ou conta de serviço não editável.');
            }
            $member = $this->sources->user($id);
            $old = match ($source) {
                'nextcloud' => $this->nextcloudValue($member, $field),
                'kimai' => (string) ($member['preferences'][$field] ?? ''),
                'akaunting' => $this->akauntingValue($id),
            };
            $expected = $request->request->getString('expected');
            if ($expected === '' || !hash_equals(self::revision($id, $source, $field, $old), $expected)) {
                $message = $this->inlineWeightRequest($request, $source, $field)
                    ? 'O peso foi alterado na origem. Reabra a edição para consultar o valor atual.'
                    : 'Dados alterados na origem desde a abertura do formulário. Atualize a página antes de salvar.';

                return $this->saveError($request, $message, Response::HTTP_CONFLICT);
            }

            $value = $this->validator->validate($field, $request->request->getString('value'));
            if ((string) $value !== $old) {
                match ($source) {
                    'nextcloud' => $this->sources->updateNextcloud(
                        $this->resolvedNextcloudUid($member),
                        self::profileKey($field),
                        $value,
                    ),
                    'kimai' => $this->sources->updateKimai($id, $field, $value),
                    'akaunting' => $this->updateAkaunting($id, (string) $value),
                };
                // Never log PII or the previous/new field values.
                $this->logger->notice('Parâmetro de cooperado alterado na origem.', [
                    'source' => $source, 'field' => $field, 'member_id' => $id,
                ]);
                if (!$this->inlineWeightRequest($request, $source, $field)
                    && !$request->isXmlHttpRequest()) {
                    $this->addNotice($request, 'success', 'Alteração confirmada na API do ' . ucfirst($source) . '. Reimporte a competência antes de recalcular.');
                }
            } else {
                if (!$this->inlineWeightRequest($request, $source, $field)
                    && !$request->isXmlHttpRequest()) {
                    $this->addNotice($request, 'info', 'O valor informado já está salvo na origem.');
                }
            }

            if ($this->inlineWeightRequest($request, $source, $field)) {
                // Use the source's confirmed representation, not the submitted string.
                $fresh = $this->nextcloudValue($member, 'weight');

                return $this->weightResponse([
                    'value' => $fresh,
                    'expected' => self::revision($id, 'nextcloud', 'weight', $fresh),
                    'message' => 'Salvo no Nextcloud. Reimporte a competência para atualizar o ETL.',
                ]);
            }
        } catch (\InvalidArgumentException | \DomainException $error) {
            return $this->saveError($request, $error->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $error) {
            $this->logger->error('Falha ao editar fonte por API.', [
                'source' => $source, 'field' => $field, 'member_id' => $id,
                'error_class' => $error::class,
            ]);
            $message = $source === 'akaunting' && str_contains($error->getMessage(), 'HTTP 403')
                ? 'A API de contatos do Akaunting recusou a edição (HTTP 403). Confira a permissão '
                    . 'de atualização de contatos financeiros da conta de integração e a empresa selecionada.'
                : 'A origem não confirmou a gravação. Confira o cadastro e tente novamente.';
            return $this->saveError($request, $message, Response::HTTP_BAD_GATEWAY);
        }
        if ($request->isXmlHttpRequest()) {
            $response = new JsonResponse([
                'changed' => (string) $value !== $old,
                'message' => (string) $value !== $old
                    ? 'Alteração confirmada na origem. Reimporte a competência antes de publicar.'
                    : 'O valor já estava salvo na origem.',
            ]);
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

            return $response;
        }
        return $this->redirectToRoute('app_sourceconfiguration_index', [
            'cooperado' => $id, 'competencia' => $month,
        ], Response::HTTP_SEE_OTHER);
    }

    private function inlineWeightRequest(Request $request, string $source, string $field): bool
    {
        return $source === 'nextcloud'
            && $field === 'weight'
            && !$request->isXmlHttpRequest()
            && str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function saveError(Request $request, string $message, int $status): Response
    {
        if ($this->inlineWeightRequest(
            $request,
            $request->attributes->getString('source'),
            $request->request->getString('field'),
        )) {
            return $this->weightResponse(['error' => $message], $status);
        }

        return new Response($message, $status);
    }

    /** @return array<string, array<string, mixed>> */
    private function serviceFields(array $member): array
    {
        $id = $member['id'];
        $snapshot = $this->sources->importedSnapshot($id);
        $services = [
            'nextcloud' => ['title' => 'Nextcloud · Profile Fields', 'fields' => [], 'error' => '', 'configured' => false],
            'kimai' => ['title' => 'Kimai · Preferências', 'fields' => [], 'error' => '', 'configured' => true],
            'akaunting' => ['title' => 'Akaunting · Contato financeiro', 'fields' => [], 'error' => '', 'configured' => false],
        ];
        try {
            $profile = $this->sources->nextcloudForMember($member);
            if (!$profile['exists']) {
                $services['nextcloud']['error'] = 'Conta não encontrada no Nextcloud. Não é seguro criar valores para um UID inexistente.';
            } else {
                foreach (['tax_number', 'weight', 'dependents'] as $field) {
                    $value = (string) ($profile['values'][self::profileKey($field)] ?? '');
                    $services['nextcloud']['fields'][] = $this->field($id, 'nextcloud', $field, $value);
                }
                $services['nextcloud']['configured'] = true;
            }
        } catch (\Throwable) {
            $services['nextcloud']['error'] = 'Consulta Nextcloud indisponível ou sem permissão de administração.';
        }
        foreach (['tax_number'] as $field) {
            $services['kimai']['fields'][] = $this->field($id, 'kimai', $field, (string) ($member['preferences'][$field] ?? ''));
        }

        $contactId = (int) ($snapshot['akaunting_contact_id'] ?? 0);
        if ($contactId <= 0) {
            $services['akaunting']['error'] = 'Contato Akaunting ainda não vinculado na importação. Confira o CPF e reimporte os cooperados.';
        } else {
            try {
                $contact = $this->sources->akauntingContact($contactId);
                if ($contact['editable']) {
                    $services['akaunting']['fields'][] = $this->field($id, 'akaunting', 'tax_number', (string) $contact['tax_number']);
                    $services['akaunting']['configured'] = true;
                } else {
                    $services['akaunting']['error'] = $contact['reason'];
                }
            } catch (\Throwable) {
                $services['akaunting']['error'] = 'Não foi possível consultar o contato vinculado no Akaunting. Confira identidade, empresa, tipo de contato e logs do ETL.';
            }
        }

        $services['nextcloud']['description'] = 'CPF, peso e dependentes são mantidos no Nextcloud. Alterações são globais ao perfil, não retroativas por competência.';
        $services['kimai']['description'] = 'CPF/CNPJ usado apenas para identificar a pessoa e conciliar com o Nextcloud. Dependentes e peso são mantidos exclusivamente no Profile Fields.';
        $services['akaunting']['description'] = 'CPF/CNPJ do contato financeiro. Apenas o contato já vinculado será atualizado via API, após conferir empresa e identificador.';

        return $services;
    }

    /** @return array{field: string, label: string, value: string, expected: string, kind: string} */
    private function field(int $id, string $source, string $field, string $value): array
    {
        return [
            'field' => $field,
            'label' => match ($field) {
                'tax_number' => 'CPF/CNPJ',
                'weight' => 'Peso de participação',
                default => 'Dependentes',
            },
            'value' => $value,
            'expected' => self::revision($id, $source, $field, $value),
            'kind' => $field === 'dependents' ? 'number' : 'text',
        ];
    }

    /** @param array<string, mixed> $member */
    private function nextcloudValue(array $member, string $field): string
    {
        $profile = $this->sources->nextcloudForMember($member);
        if (!$profile['exists']) {
            throw new \DomainException('Usuário não localizado no Nextcloud.');
        }
        return (string) ($profile['values'][self::profileKey($field)] ?? '');
    }

    /** @param array<string, mixed> $member */
    private function resolvedNextcloudUid(array $member): string
    {
        $result = $this->sources->nextcloudForMember($member);
        if (!$result['exists']) {
            throw new \DomainException('Conta Nextcloud correspondente não localizada.');
        }
        return $result['uid'];
    }

    private function akauntingValue(int $id): string
    {
        $contactId = (int) ($this->sources->importedSnapshot($id)['akaunting_contact_id'] ?? 0);
        return (string) $this->sources->akauntingContact($contactId)['tax_number'];
    }

    private function updateAkaunting(int $id, string $number): void
    {
        $contactId = (int) ($this->sources->importedSnapshot($id)['akaunting_contact_id'] ?? 0);
        $this->sources->updateAkauntingTaxNumber($contactId, $number);
    }

    private static function profileKey(string $field): string
    {
        $keys = [
            'tax_number' => trim((string) getenv('PROFILE_FIELDS_TAX_NUMBER_FIELD_KEY')) ?: 'tax_number',
            'weight' => trim((string) getenv('PROFILE_FIELDS_WEIGHT_FIELD_KEY')) ?: 'weight',
            'dependents' => trim((string) getenv('PROFILE_FIELDS_DEPENDENTS_FIELD_KEY')) ?: 'dependents',
        ];
        return $keys[$field];
    }

    /** @return array<string, list<string>> */
    private function popNotice(Request $request): array
    {
        $session = $request->getSession();
        $notice = $session->get('production_settings_notice', []);
        $session->remove('production_settings_notice');

        return is_array($notice) ? $notice : [];
    }

    private function addNotice(Request $request, string $type, string $message): void
    {
        $request->getSession()->set('production_settings_notice', [$type => [$message]]);
    }

    /**
     * Optimistic concurrency checksum, not an authorization mechanism.
     * No additional password, secret or service login is required.
     */
    private static function revision(int $id, string $source, string $field, mixed $value): string
    {
        return hash('sha256', json_encode([
            $id, $source, $field, (string) ($value ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    private function month(string $input): string
    {
        if ($input === '') {
            return new \DateTimeImmutable('first day of -2 months')->format('Y-m');
        }
        if (!preg_match('/^20[0-9]{2}-(?:0[1-9]|1[0-2])$/D', $input)) {
            throw new \InvalidArgumentException('Competência inválida.');
        }
        return $input;
    }

    /** @param array<string, mixed> $data */
    private function privatePage(array $data): Response
    {
        $response = $this->render('configuracao.index.html.twig', $data);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
