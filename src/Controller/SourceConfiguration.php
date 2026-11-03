<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Integration\CooperativeSources;
use App\Service\Config\CompetenceDefaults;
use App\Service\Calculation\CalculationActionGuard;
use App\Service\Integration\MemberParameterValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class SourceConfiguration extends AbstractController
{
    public function __construct(
        private CooperativeSources $sources,
        private CompetenceDefaults $competences,
        private CalculationActionGuard $guard,
        private MemberParameterValidator $validator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/configuracao', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->redirectToRoute('app_sourceconfiguration_members', $request->query->all());
    }

    #[Route('/configuracao/cooperados', methods: ['GET'])]
    public function members(Request $request): Response
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
                    ?: (float) ($b['imported_weight'] ?? -1) <=> (float) ($a['imported_weight'] ?? -1)
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
            $member = $this->sources->user($id);
            if ($member['system']) {
                return new Response('Cooperado não encontrado ou não editável.', Response::HTTP_NOT_FOUND);
            }
            $editor = $this->serviceFields($member);
            $canManage = $this->isAdmin($request);
            $services = $editor['services'];
            if (!$canManage) {
                // Do not pass fiscal or technical identifiers to the template,
                // even if its administrator-only sections change later.
                $services['nextcloud']['fields'] = array_values(array_filter(
                    $services['nextcloud']['fields'],
                    static fn (array $field): bool => $field['field'] !== 'tax_number',
                ));
                unset($services['kimai'], $services['akaunting']);
            }
            $data = [
                'token' => $canManage ? $this->guard->token($request) : '',
                'month' => $month,
                'member' => $canManage ? $member : ['alias' => $member['alias']],
                'services' => $services,
                'links' => $canManage ? $editor['links'] : [
                    'linked' => $editor['links']['linked'],
                    'kimai_linked' => $editor['links']['kimai_linked'],
                ],
                'can_manage' => $canManage,
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

    #[Route('/configuracao/vinculos/opcoes', methods: ['GET'])]
    public function linkOptions(Request $request): Response
    {
        $this->assertAdmin($request);
        $source = $request->query->getString('source');
        $query = trim($request->query->getString('q'));
        if (!in_array($source, ['nextcloud', 'akaunting'], true) || mb_strlen($query) > 60) {
            return $this->linkResponse(['error' => 'Busca inválida.'], 400);
        }
        if (mb_strlen($query) < 2) {
            return $this->linkResponse(['items' => []]);
        }
        try {
            $items = $source === 'nextcloud'
                ? array_map(static fn (string $uid): array => ['id' => $uid, 'label' => $uid], $this->sources->searchNextcloudUsers($query))
                : $this->sources->searchAkauntingContacts($query);
            return $this->linkResponse(['items' => $items]);
        } catch (\Throwable $error) {
            $this->logger->error('Pesquisa de vínculos indisponível.', ['source' => $source, 'class' => $error::class]);
            return $this->linkResponse(['error' => 'Não foi possível consultar a origem.'], 502);
        }
    }

    #[Route('/configuracao/vinculos/preparar', methods: ['POST'])]
    public function prepareLinks(Request $request): Response
    {
        $this->assertAdmin($request);
        $this->guard->assertAuthorized($request);
        try {
            $this->sources->prepareLinkFields();
            return $this->linkResponse(['message' => 'Campos de vínculo disponíveis no Nextcloud.']);
        } catch (\Throwable $error) {
            $this->logger->error('Falha ao preparar campos de vínculo.', ['class' => $error::class]);
            return $this->linkResponse(['error' => 'Não foi possível configurar os campos no Profile Fields.'], 502);
        }
    }

    #[Route('/configuracao/cooperado/{id}/contato/proposta', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function contactProposal(Request $request, int $id): Response
    {
        $this->assertAdmin($request);
        try {
            return $this->linkResponse($this->sources->contactProvisioning($id));
        } catch (\DomainException | \InvalidArgumentException $error) {
            return $this->linkResponse(['error' => $error->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable $error) {
            $this->logger->error('Falha na consulta de disponibilidade de contato financeiro.', [
                'member_id' => $id, 'error_class' => $error::class,
            ]);
            return $this->linkResponse([
                'error' => 'Não foi possível consultar os contatos existentes. Nenhum contato foi criado.',
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/configuracao/cooperado/{id}/contato/criar', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createContact(Request $request, int $id): Response
    {
        $this->assertAdmin($request);
        $this->guard->assertAuthorized($request);
        if ($id <= 0 || $request->request->getString('identity_confirmed') !== '1') {
            return $this->linkResponse(
                ['error' => 'Confirme a identidade do cooperado antes de criar.'],
                Response::HTTP_BAD_REQUEST
            );
        }
        $uid = trim($request->request->getString('nextcloud_uid'));
        $name = trim($request->request->getString('contact_name'));
        if ($uid === '' || mb_strlen($uid) > 128 || mb_strlen($name) > 120) {
            return $this->linkResponse(
                ['error' => 'Confira a conta Nextcloud e o nome do contato.'],
                Response::HTTP_BAD_REQUEST
            );
        }
        try {
            $created = $this->sources->createContactForMember($id, $uid, $name);
            $this->logger->notice('Contato financeiro criado por ação administrativa explícita.', [
                'member_id' => $id, 'contact_id' => $created['id'],
            ]);
            return $this->linkResponse([
                'id' => $created['id'],
                'message' => 'Contato criado no Akaunting. Confira o ID e clique em Salvar vínculos para associá-lo.',
            ], Response::HTTP_CREATED);
        } catch (\DomainException | \InvalidArgumentException $error) {
            return $this->linkResponse(['error' => $error->getMessage()], Response::HTTP_CONFLICT);
        } catch (\Throwable $error) {
            $this->logger->error('Criação de contato financeiro não confirmada.', [
                'member_id' => $id, 'error_class' => $error::class,
                'exception' => $error,
            ]);
            return $this->linkResponse(
                ['error' => 'Resultado da criação não confirmado. Consulte os contatos no Akaunting antes de repetir; não crie outro contato às cegas.'],
                Response::HTTP_BAD_GATEWAY
            );
        }
    }

    #[Route('/configuracao/cooperado/{id}/vincular', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function saveLinks(Request $request, int $id): Response
    {
        $this->assertAdmin($request);
        $this->guard->assertAuthorized($request);
        $uid = trim($request->request->getString('nextcloud_uid'));
        $contact = trim($request->request->getString('akaunting_contact_id'));
        $kimai = trim($request->request->getString('kimai_user_id'));
        if ($id <= 0 || $uid === '' || mb_strlen($uid) > 128
            || !ctype_digit($contact) || (int) $contact <= 0
            || !preg_match('/^[1-9][0-9]*$/D', $kimai) || filter_var($kimai, FILTER_VALIDATE_INT) === false) {
            return $this->linkResponse(['error' => 'Informe um usuário Kimai válido, uma conta Nextcloud e um contato financeiro.'], 400);
        }
        try {
            $this->sources->linkMember($id, $uid, (int) $contact, (int) $kimai);
            $this->logger->notice('Vínculo entre contas confirmado.', ['member_id' => $id]);
            return $this->linkResponse(['message' => 'Vínculos salvos no Nextcloud. Reimporte antes do próximo fechamento.']);
        } catch (\DomainException | \InvalidArgumentException $error) {
            return $this->linkResponse(['error' => $error->getMessage()], 409);
        } catch (\Throwable $error) {
            $this->logger->error('Falha na atualização de vínculo entre contas.', ['member_id' => $id, 'class' => $error::class]);
            return $this->linkResponse(['error' => 'Não foi possível confirmar os vínculos nas fontes. Reabra o cadastro para verificar o estado.'], 502);
        }
    }

    /** @param array<string,mixed> $result */
    private function linkResponse(array $result, int $status = 200): Response
    {
        $response = new JsonResponse($result, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    private function isAdmin(Request $request): bool
    {
        $user = $request->server->getString('REMOTE_USER');
        $allowed = array_filter(array_map(trim(...), explode(',', (string) getenv('CONFIG_ADMIN_USERS'))));

        return $user !== '' && in_array($user, $allowed, true);
    }

    private function assertAdmin(Request $request): void
    {
        if (!$this->isAdmin($request)) {
            throw new AccessDeniedHttpException('Administração de cadastro e vínculos não autorizada.');
        }
    }

    #[Route('/configuracao/cooperado/{id}/{source}', requirements: ['id' => '\\d+', 'source' => 'nextcloud|kimai|akaunting'], methods: ['POST'])]
    public function save(Request $request, int $id, string $source): Response
    {
        // Service credentials can edit administrator-only Nextcloud fields.
        // Only configured administrators may invoke those writes.
        $this->assertAdmin($request);
        $this->guard->assertAuthorized($request);
        $field = $request->request->getString('field');
        $allowed = [
            'nextcloud' => ['tax_number', 'weight', 'dependents'],
        ];
        if (!in_array($field, $allowed[$source] ?? [], true)) {
            return $this->saveError('Campo não autorizado.', Response::HTTP_BAD_REQUEST);
        }

        $month = $this->month($request->request->getString('competencia'));
        try {
            $member = $this->sources->user($id);
            if ($member['system']) {
                throw new \DomainException('Cooperado não localizado ou conta de serviço não editável.');
            }
            $old = $this->nextcloudValue($member, $field);
            $expected = $request->request->getString('expected');
            if ($expected === '' || !hash_equals(self::revision($id, $source, $field, $old), $expected)) {
                return $this->saveError('Dados alterados na origem desde a abertura do formulário. Reabra a edição antes de salvar.', Response::HTTP_CONFLICT);
            }

            $value = $this->validator->validate($field, $request->request->getString('value'));
            $changed = $field === 'weight'
                ? (float) $value !== (float) $old
                : (string) $value !== $old;
            if ($changed) {
                $this->sources->updateNextcloud(
                    $this->resolvedNextcloudUid($member),
                    self::profileKey($field),
                    $value,
                );
                // Never log PII or the previous/new field values.
                $this->logger->notice('Parâmetro de cooperado alterado na origem.', [
                    'source' => $source, 'field' => $field, 'member_id' => $id,
                ]);
                if (!$request->isXmlHttpRequest()) {
                    $this->addNotice($request, 'success', 'Alteração confirmada na API do ' . ucfirst($source) . '. Reimporte a competência antes de recalcular.');
                }
            } else {
                if (!$request->isXmlHttpRequest()) {
                    $this->addNotice($request, 'info', 'O valor informado já está salvo na origem.');
                }
            }

        } catch (\InvalidArgumentException | \DomainException $error) {
            return $this->saveError($error->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $error) {
            $this->logger->error('Falha ao editar fonte por API.', [
                'source' => $source, 'field' => $field, 'member_id' => $id,
                'error_class' => $error::class,
            ]);
            $message = 'O Profile Fields não confirmou a gravação. Confira o cadastro e tente novamente.';
            return $this->saveError($message, Response::HTTP_BAD_GATEWAY);
        }
        if ($request->isXmlHttpRequest()) {
            $response = new JsonResponse([
                'changed' => $changed,
                'value' => $changed ? (string) $value : $old,
                'expected' => self::revision($id, $source, $field, $changed ? (string) $value : $old),
                'message' => $changed
                    ? 'Alteração confirmada na origem. Reimporte a competência antes de publicar.'
                    : 'O valor já estava salvo na origem.',
            ]);
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

            return $response;
        }
        return $this->redirectToRoute('app_sourceconfiguration_members', [
            'competencia' => $month,
        ], Response::HTTP_SEE_OTHER);
    }

    private function saveError(string $message, int $status): Response
    {
        return new Response($message, $status);
    }

    /**
     * @param array<string, mixed> $member
     * @return array{services: array<string, array<string, mixed>>, links: array<string, mixed>}
     */
    private function serviceFields(array $member): array
    {
        $id = (int) $member['id'];
        $services = [
            'nextcloud' => ['title' => 'Nextcloud', 'fields' => [], 'error' => '', 'configured' => false],
        ];
        $links = [
            'uid' => '',
            'contact_id' => '',
            'kimai_id' => (string) $id,
            'linked' => false,
            'kimai_linked' => false,
        ];
        try {
            $profile = $this->sources->nextcloudForMember($member);
            if ($profile['exists']) {
                $links['uid'] = $profile['uid'];
                $values = $profile['values'];
                $links['contact_id'] = trim((string) ($values['akaunting_contact_id'] ?? ''));
                $links['kimai_id'] = trim((string) ($values['kimai_user_id'] ?? '')) ?: (string) $id;
                $links['kimai_linked'] = trim((string) ($values['kimai_user_id'] ?? '')) === (string) $id;
                $links['linked'] = $links['kimai_linked'] && $links['contact_id'] !== '';
                if ($links['kimai_linked']) {
                    foreach (['weight', 'dependents', 'tax_number'] as $field) {
                        $value = (string) ($values[self::profileKey($field)] ?? '');
                        $services['nextcloud']['fields'][] = $this->field($id, 'nextcloud', $field, $value);
                    }
                    $services['nextcloud']['configured'] = true;
                } else {
                    $services['nextcloud']['error'] = 'Confirme o vínculo com o Kimai antes de alterar o cadastro.';
                }
            } else {
                $services['nextcloud']['error'] = 'Escolha a conta Nextcloud para continuar.';
            }
        } catch (\Throwable $error) {
            $this->logger->warning('Falha ao consultar perfil para edição.', ['member_id' => $id, 'class' => $error::class]);
            $services['nextcloud']['error'] = 'Conta Nextcloud indisponível. Confira o vínculo e as permissões.';
        }
        return ['services' => $services, 'links' => $links];
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
        $profile = $this->confirmedProfile($member);
        return (string) ($profile['values'][self::profileKey($field)] ?? '');
    }

    /** @param array<string, mixed> $member */
    private function resolvedNextcloudUid(array $member): string
    {
        return $this->confirmedProfile($member)['uid'];
    }

    /**
     * @param array<string, mixed> $member
     * @return array{exists: bool, uid: string, values: array<string, mixed>}
     */
    private function confirmedProfile(array $member): array
    {
        $profile = $this->sources->nextcloudForMember($member);
        if (!$profile['exists'] || (string) ($profile['values']['kimai_user_id'] ?? '') !== (string) $member['id']) {
            throw new \DomainException('Confirme o vínculo Nextcloud–Kimai antes de editar o perfil.');
        }
        return $profile;
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
            return $this->competences->reportMonth();
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
