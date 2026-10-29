<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Integration\CooperativeSources;
use App\Service\Integration\SettingsAccessGuard;
use App\Service\Integration\MemberParameterValidator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** @psalm-suppress UnusedClass Routed by Symfony attributes. */
final class SourceConfiguration extends AbstractController
{
    public function __construct(
        private CooperativeSources $sources,
        private SettingsAccessGuard $access,
        private MemberParameterValidator $validator,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/configuracao', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $month = $this->month($request->query->getString('competencia'));
        $data = [
            'unlocked' => $this->access->isUnlocked($request),
            'token' => $this->access->csrf($request),
            'month' => $month,
            'users' => [],
            'member' => null,
            'services' => [],
            'error' => '',
            'notifications' => $request->getSession()->getFlashBag()->all(),
        ];
        if (!$data['unlocked']) {
            return $this->privatePage($data);
        }
        try {
            $users = $this->sources->users();
            $hours = $this->sources->monthlyHours($month);
            foreach ($users as &$row) {
                $row['hours'] = round(($hours[$row['id']] ?? 0) / 3600, 2);
                $row['participates'] = ($hours[$row['id']] ?? 0) > 0;
            }
            unset($row);
            $data['users'] = $users;

            $selected = $request->query->getInt('cooperado', 0);
            if ($selected > 0) {
                $listed = array_filter($users, static fn (array $row): bool => $row['id'] === $selected && !$row['system']);
                if ($listed === []) {
                    throw new \DomainException('Selecione um cooperado válido na lista do Kimai.');
                }
                $member = $this->sources->user($selected);
                $data['member'] = $member;
                $data['services'] = $this->serviceFields($member);
            }
        } catch (\Throwable $error) {
            $this->logger->error('Painel de configuração: falha de consulta.', ['error_class' => $error::class]);
            $data['error'] = 'Não foi possível consultar todas as fontes. Confira conexão, permissões e logs do servidor.';
        }

        return $this->privatePage($data);
    }

    #[Route('/configuracao/desbloquear', methods: ['POST'])]
    public function unlock(Request $request): Response
    {
        $this->access->unlock($request);
        $request->getSession()->getFlashBag()->add('success', 'Edição habilitada nesta sessão por 15 minutos.');
        return $this->redirectToRoute('app_sourceconfiguration_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/configuracao/bloquear', methods: ['POST'])]
    public function lock(Request $request): Response
    {
        $this->access->lock($request);
        return $this->redirectToRoute('app_sourceconfiguration_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/configuracao/cooperado/{id}/{source}', requirements: ['id' => '\\d+', 'source' => 'nextcloud|kimai|akaunting'], methods: ['POST'])]
    public function save(Request $request, int $id, string $source): Response
    {
        $this->access->assertCanWrite($request);
        $field = $request->request->getString('field');
        $allowed = [
            'nextcloud' => ['tax_number', 'weight', 'dependents'],
            'kimai' => ['tax_number', 'dependents'],
            'akaunting' => ['tax_number'],
        ];
        if (!in_array($field, $allowed[$source] ?? [], true)) {
            return new Response('Campo não autorizado.', Response::HTTP_BAD_REQUEST);
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
            if ($expected === '' || !hash_equals($this->access->fingerprint($id, $source, $field, $old), $expected)) {
                return new Response('Dados alterados na origem desde a abertura do formulário. Atualize a página antes de salvar.', Response::HTTP_CONFLICT);
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
                $request->getSession()->getFlashBag()->add('success', 'Alteração confirmada na API do ' . ucfirst($source) . '. Reimporte a competência antes de recalcular.');
            } else {
                $request->getSession()->getFlashBag()->add('info', 'O valor informado já está salvo na origem.');
            }
        } catch (\InvalidArgumentException | \DomainException $error) {
            return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $error) {
            $this->logger->error('Falha ao editar fonte por API.', [
                'source' => $source, 'field' => $field, 'member_id' => $id,
                'error_class' => $error::class,
            ]);
            return new Response('A fonte recusou a alteração ou não confirmou a gravação. Confira permissões e o log operacional; nenhum valor foi gravado no ETL.', Response::HTTP_BAD_GATEWAY);
        }
        return $this->redirectToRoute('app_sourceconfiguration_index', [
            'cooperado' => $id, 'competencia' => $month,
        ], Response::HTTP_SEE_OTHER);
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
        } catch (\Throwable $error) {
            $services['nextcloud']['error'] = 'Consulta Nextcloud indisponível ou sem permissão de administração.';
        }
        foreach (['tax_number', 'dependents'] as $field) {
            $services['kimai']['fields'][] = $this->field($id, 'kimai', $field, (string) ($member['preferences'][$field] ?? ''));
        }

        $contactId = (int) ($snapshot['akaunting_contact_id'] ?? 0);
        if ($contactId <= 0) {
            $services['akaunting']['error'] = 'Contato Akaunting ainda não vinculado na importação. Confira o CPF e reimporte os cooperados.';
        } else {
            try {
                $contact = $this->sources->akauntingContact($contactId);
                $services['akaunting']['fields'][] = $this->field($id, 'akaunting', 'tax_number', (string) $contact['tax_number']);
                $services['akaunting']['configured'] = true;
            } catch (\Throwable $error) {
                $services['akaunting']['error'] = 'API de contatos indisponível ou com acesso negado. Confira permissões de leitura/edição da conta de integração no Akaunting (HTTP 403 observado na implantação).';
            }
        }

        $services['nextcloud']['description'] = 'CPF, peso e dependentes são mantidos no Nextcloud. Alterações são globais ao perfil, não retroativas por competência.';
        $services['kimai']['description'] = 'Preferências do usuário no Kimai. A importação pode ser sobrescrita pelos campos do Nextcloud; revise divergências.';
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
            'expected' => $this->access->fingerprint($id, $source, $field, $value),
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

    private function month(string $input): string
    {
        if ($input === '') {
            return (new \DateTimeImmutable('first day of -2 months'))->format('Y-m');
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
