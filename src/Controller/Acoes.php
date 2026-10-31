<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Controller;

use App\Helper\ArrayValue;
use App\Domain\Competencia;
use App\Helper\SseLogHandler;
use App\Kernel;
use App\Service\Calculation\CalculationActionGuard;
use App\Service\Calculation\CalculationExecutionLock;
use App\Service\Import\StagingDatabaseResetter;
use App\Service\Calculation\ExecutionOutcome;
use App\Service\Calculation\CalculationCommandOptions;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use RuntimeException;

class Acoes extends AbstractController
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'monolog.logger')]
        private Logger $logger,
        private SseLogHandler $sseLogHandler,
        private Kernel $kernel,
        private CalculationActionGuard $guard,
        private StagingDatabaseResetter $stagingDatabaseResetter,
        private CalculationExecutionLock $executionLock,
    ) {
    }

    #[Route('/acoes/zerar-banco-local', methods: ['GET', 'POST'])]
    public function zerarBancoLocal(Request $request): Response
    {
        $message = '';
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            // Basic authentication is enforced at Nginx; keep CSRF and a
            // deliberate confirmation for this destructive staging action.
            $this->guard->assertAuthorized($request);
            if ($request->request->getString('confirmacao') !== 'ZERAR') {
                return new Response('Confirmação inválida: escreva ZERAR.', Response::HTTP_BAD_REQUEST);
            }
            try {
                $this->executionLock->acquire();
                $deleted = $this->stagingDatabaseResetter->reset();
                $this->logger->warning('Banco intermediário do ETL reconstruído a partir de dados vazios.', [
                    'deleted_count' => array_sum($deleted),
                    'staging_tables' => array_keys($deleted),
                ]);
                $message = sprintf(
                    'Dados importados removidos (%d registros). Estrutura, migrações e logs preservados. Execute a extração novamente antes dos relatórios.',
                    array_sum($deleted)
                );
            } catch (RuntimeException $error) {
                $this->logger->error('Não foi possível limpar o banco de transformação.', ['exception' => $error]);
                $status = str_contains($error->getMessage(), 'Já existe um cálculo') ?
                    Response::HTTP_CONFLICT : Response::HTTP_INTERNAL_SERVER_ERROR;
                $message = $error->getMessage();
            } finally {
                $this->executionLock->release();
            }
        }

        return $this->render('acoes/zerar_banco_local.html.twig', [
            'response' => $message,
            'response_is_error' => $status >= Response::HTTP_BAD_REQUEST,
            'staging_configured' => trim((string) getenv('PRODUCAO_STAGING_DATABASE')) !== '',
            'token' => $this->guard->token($request),
            'url' => $this->urlGenerator->generate('app_acoes_zerarbancolocal'),
            'retorno' => $request->query->getString('retorno', new \DateTimeImmutable('first day of -2 months')->format('Y-m')),
        ], new Response('', $status));
    }

    #[Route('/acoes/make-producao', methods: ['GET'])]
    public function makeProducao(Request $request): Response
    {
        $requested = $request->query->getString('ano-mes');
        try {
            $inicio = $requested === ''
                ? new \DateTimeImmutable('first day of -2 months')
                : Competencia::fromString($requested)->start();
        } catch (\InvalidArgumentException $error) {
            return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        return $this->render('acoes/make_producao.html.twig', [
            'inicio_ano' => $inicio->format('Y'),
            'inicio_mes' => $inicio->format('m'),
            'url' => $this->urlGenerator->generate('app_acoes_domakeproducao'),
            'baixar_dados' => $request->query->getBoolean('baixar_dados') ? 1 : 0,
            'atualiza_producao' => $request->query->getBoolean('atualiza_producao')
                && !$request->query->getBoolean('baixar_dados') ? 1 : 0,
            'token' => $this->guard->token($request),
        ]);
    }

    #[Route('/acoes/do-make-producao', methods: ['POST'])]
    public function doMakeProducao(Request $request): Response
    {
        $year = $request->request->getString('year');
        $month = $request->request->getString('month');
        $competencia = $year . '-' . $month;
        try {
            Competencia::fromString($competencia);
        } catch (\InvalidArgumentException $error) {
            return new Response($error->getMessage(), Response::HTTP_BAD_REQUEST);
        }

        $download = $request->request->getString('baixar_dados', '0');
        $publish = $request->request->getString('atualiza_producao', '0');
        if (!in_array($download, ['0', '1'], true) || !in_array($publish, ['0', '1'], true)) {
            return new Response('Opção de execução inválida.', Response::HTTP_BAD_REQUEST);
        }

        $this->guard->assertAuthorized($request);
        $operation = $request->request->getString('operation', 'preview');
        if (!in_array($operation, ['import', 'preview', 'publish'], true)) {
            return new Response('Etapa de processamento inválida.', Response::HTTP_BAD_REQUEST);
        }
        if ($operation === 'import' && ($download !== '1' || $publish !== '0')) {
            return new Response('Importar não pode publicar documentos.', Response::HTTP_BAD_REQUEST);
        }
        if ($operation === 'preview' && $publish !== '0') {
            return new Response('A prévia não pode publicar documentos.', Response::HTTP_BAD_REQUEST);
        }
        if ($operation === 'publish' && ($download !== '0' || $publish !== '1')) {
            return new Response('Publicar não pode reimportar dados.', Response::HTTP_BAD_REQUEST);
        }
        $revision = $request->request->getString('expected_revision');
        $excluded = $request->request->getString('omit_user_ids');
        if ($operation === 'publish' && preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            return new Response('Confira a prévia antes de publicar. Revisão ausente ou inválida.', Response::HTTP_BAD_REQUEST);
        }
        if ($operation !== 'publish' && ($revision !== '' || $excluded !== '')) {
            return new Response('Seleção de publicação enviada na etapa incorreta.', Response::HTTP_BAD_REQUEST);
        }
        if ($excluded !== '' && (strlen($excluded) > 2048
            || preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $excluded) !== 1)) {
            return new Response('Seleção de cooperados inválida.', Response::HTTP_BAD_REQUEST);
        }

        // Importation and publication must be separate operations so that
        // the financial manager can review the reports between them.
        if ($download === '1' && $publish === '1') {
            return new Response(
                'Importe e confira a prévia antes de gravar no Akaunting. '
                . 'Desmarque a importação para publicar.',
                Response::HTTP_BAD_REQUEST
            );
        }
        $runId = bin2hex(random_bytes(8));

        return new StreamedResponse(
            function () use ($competencia, $download, $publish, $operation, $revision, $excluded, $runId): void {
                $this->logger->pushHandler($this->sseLogHandler);
                try {
                    $this->logger->info('Iniciada execução {run_id} da competência {competencia}', [
                        'run_id' => $runId,
                        'competencia' => $competencia,
                    ]);
                    $this->logger->info(new ArrayValue([
                        'event' => 'info',
                        'data' => sprintf('Execução %s iniciada para a competência %s.', $runId, $competencia),
                    ]));

                    $app = new Application($this->kernel);
                    $app->setAutoExit(false);
                    $output = new BufferedOutput();
                    $exitCode = $app->run(new ArrayInput([
                        'make:producao',
                        ...CalculationCommandOptions::forWeb(
                            $competencia,
                            $download,
                            $publish === '1',
                            $operation === 'import',
                            $revision,
                            $excluded,
                        ),
                    ]), $output);
                    $details = $output->fetch();
                    $outcome = new ExecutionOutcome($exitCode, $runId, $details);
                    if ($exitCode !== 0) {
                        $this->logger->error('Falha no cálculo da competência {competencia}: execução {run_id}', [
                            'competencia' => $competencia,
                            'run_id' => $runId,
                            'exit_code' => $exitCode,
                            'details' => $details,
                        ]);
                    }
                    $this->logger->info(new ArrayValue([
                        'event' => $outcome->event(),
                        'data' => $outcome->message(),
                    ]));
                } catch (\Throwable $error) {
                    $this->logger->error('Exceção na execução {run_id}: {message}', [
                        'run_id' => $runId,
                        'message' => $error->getMessage(),
                        'exception' => $error,
                    ]);
                    $outcome = new ExecutionOutcome(1, $runId, $error->getMessage());
                    $this->logger->info(new ArrayValue([
                        'event' => 'failed',
                        'data' => $outcome->message(),
                    ]));
                } finally {
                    $this->logger->popHandler();
                }
            },
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/event-stream; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache',
                'X-Accel-Buffering' => 'no',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
