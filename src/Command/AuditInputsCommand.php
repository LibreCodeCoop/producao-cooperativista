<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Command;

use App\Domain\Competencia;
use App\Service\Cooperative\ProfileRegistry;
use App\Service\Import\SourcePipeline;
use App\Service\Audit\InputManifestBuilder;
use App\Service\Audit\StagingInputReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** @psalm-suppress UnusedClass Loaded by the Symfony AsCommand registry */
#[AsCommand(name: 'audit:inputs', description: 'Audita, sem recalcular nem gravar no Akaunting, os dados de uma competência')]
final class AuditInputsCommand extends Command
{
    private const array RULE_ENV_KEYS = [
        'PERCENTUAL_MAXIMO',
        'DIA_UTIL_PAGAMENTO',
        'CNPJ_CLIENTES_INTERNOS',
        'AKAUNTING_PARENT_ENTRADAS_CLIENTES_CATEGORY_ID',
        'AKAUNTING_PARENT_DISPENDIOS_CLIENTE_CATEGORY_ID',
        'AKAUNTING_PARENT_DISPENDIOS_INTERNOS_CATEGORY_ID',
        'AKAUNTING_CAPITAL_SOCIAL_CATEGORY_ID',
        'AKAUNTING_CAPITAL_SOCIAL_ITEM_ID',
        'AKAUNTING_FRRA_MES_PADRAO',
        'AKAUNTING_DISTRIBUICAO_SOBRAS_CATEGORY_ID',
        'HOLYDAYS_LIST',
        'LOCALE',
    ];

    public function __construct(
        private readonly StagingInputReader $reader,
        private readonly InputManifestBuilder $builder,
        private readonly ProfileRegistry $profiles,
        private readonly SourcePipeline $pipeline,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('ano-mes', null, InputOption::VALUE_REQUIRED, 'Competência YYYY-MM')
            ->addOption('revision', null, InputOption::VALUE_REQUIRED, 'Revisão do código executado')
            ->addOption('save-bundle', null, InputOption::VALUE_NONE, 'Salva entradas integrais, confidenciais, em var/audit com permissões privadas');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $competence = Competencia::fromString((string) $input->getOption('ano-mes'));
            $revision = (string) ($input->getOption('revision') ?: (getenv('APP_REVISION') ?: 'unknown'));
            $rules = [];
            foreach (self::RULE_ENV_KEYS as $key) {
                $value = getenv($key);
                $rules[$key] = $value === false ? null : $value;
            }
            // A matching Git revision is insufficient if dependency versions,
            // PHP runtime or timezone differ between calculations.
            $lockfile = dirname(__DIR__, 2) . '/composer.lock';
            $rules['__composer_lock_sha256'] = is_file($lockfile) ? hash_file('sha256', $lockfile) : null;
            $rules['__php_runtime'] = PHP_VERSION;
            $rules['__timezone'] = date_default_timezone_get();
            $profile = $this->profiles->active();
            $rules['__profile_id'] = $profile->id();
            $rules['__allocation_policy_id'] = $profile->allocationPolicyId();
            $rules['__selected_sources'] = implode(',', $this->pipeline->selectedStageNames());
            $sources = $this->reader->read($competence);
            $manifest = $this->builder->build($competence, $sources, $rules, $revision);
            $output->writeln(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($input->getOption('save-bundle')) {
                $relativePath = $this->saveBundle($competence, $manifest, $sources, $rules, $revision);
                $output->writeln('Bundle confidencial armazenado em ' . $relativePath);
            }
            if ($revision === 'unknown') {
                $output->writeln('<comment>Informe --revision para identificar o código usado nesta execução.</comment>');
            }
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>Falha na auditoria dos dados de entrada: ' .
                \Symfony\Component\Console\Formatter\OutputFormatter::escape($error->getMessage()) . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $sources
     * @param array<string, mixed> $rules
     */
    private function saveBundle(
        Competencia $competence,
        array $manifest,
        array $sources,
        array $rules,
        string $revision,
    ): string {
        $directory = dirname(__DIR__, 2) . '/var/audit/' . (string) $competence;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível criar o diretório privado de auditoria.');
        }
        $path = $directory . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.json';
        $originalMask = umask(0077);
        try {
            $stream = fopen($path, 'x');
        } finally {
            umask($originalMask);
        }
        if ($stream === false) {
            throw new \RuntimeException('Não foi possível criar o bundle de auditoria.');
        }

        $payload = json_encode([
            'manifest' => $manifest,
            'rules' => $rules,
            'revision' => $revision,
            'sources' => $sources,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        try {
            if (fwrite($stream, $payload) !== strlen($payload)) {
                throw new \RuntimeException('O bundle de auditoria não foi gravado integralmente.');
            }
        } catch (\Throwable $error) {
            fclose($stream);
            unlink($path);
            throw $error;
        }
        fclose($stream);
        chmod($path, 0600);

        return 'var/audit/' . (string) $competence . '/' . basename($path);
    }
}
