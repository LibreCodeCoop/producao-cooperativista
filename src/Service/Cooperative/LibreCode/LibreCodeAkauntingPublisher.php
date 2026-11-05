<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Service\Cooperative\LibreCode;

use App\Helper\Dates;
use App\Provider\Akaunting\Request;
use App\Service\Akaunting\CooperadoContactRegistry;
use App\Service\Akaunting\DocumentConfiguration;
use App\Service\Akaunting\Document\Taxes\Cofins;
use App\Service\Akaunting\Document\Taxes\IrpfRetidoNaNota;
use App\Service\Akaunting\Document\Taxes\Iss;
use App\Service\Akaunting\Document\Taxes\Pis;
use App\Service\Akaunting\Source\Documents;
use App\Service\Cooperative\ProfileRegistry;
use App\Service\Cooperative\ProductionPublisher;
use App\Service\Movimentacao;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * The entire LibreCode-specific document, FRRA and tax publishing policy.
 * Kept intact during architectural migration; do not reuse for a different
 * cooperative without explicit accounting and governance approval.
 */
/** @psalm-suppress UnusedClass Registered through Symfony attributes and dependency injection. */
final readonly class LibreCodeAkauntingPublisher implements ProductionPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Dates $dates,
        private Movimentacao $movimentacao,
        private Documents $documents,
        private Request $request,
        private DocumentConfiguration $documentConfiguration,
        private CooperadoContactRegistry $contactRegistry,
        private ProfileRegistry $profiles,
        private TaxYearPublicationGuard $taxYearGuard,
    ) {
    }

    #[\Override]
    public function profileId(): string
    {
        return 'librecode';
    }

    #[\Override]
    public function publish(array $members, array $work): void
    {
        // Defense in depth: even a direct invocation of this service must not
        // make LibreCode-specific FRRA, benefits or tax writes for other profiles.
        if ($this->profiles->active()->id() !== $this->profileId()) {
            throw new LogicException(
                'Publicação contábil bloqueada: o publicador da LibreCode não corresponde ao perfil ativo.'
            );
        }
        // IRRF reference still comes from the work month; do not publish an
        // individual payout in another calendar year without fiscal review.
        foreach ($members as $member) {
            if (strlen($member->getTaxNumber()) === 11) {
                $this->taxYearGuard->assertSafeForIndividual(
                    $this->dates->getInicio(),
                    $this->dates->getDataPagamento(),
                );
                break;
            }
        }

        // Contacts are financial writes: never run while preparing previews.
        $registered = [];
        foreach ($work as $row) {
            $memberId = (string) ($row['tax_number'] ?? '');
            if (empty($row['akaunting_contact_id']) && !isset($registered[$memberId])) {
                if (!isset($members[$memberId])) {
                    throw new LogicException('Apontamento sem cooperado calculado: não é seguro publicar a competência.');
                }
                $contactId = $this->contactRegistry->getOrCreate(
                    (string) $row['alias'],
                    $memberId,
                    (int) getenv('AKAUNTING_COMPANY_ID'),
                    $this->dates->getDataProcessamento()
                );
                $members[$memberId]->setAkauntingContactId($contactId);
                $registered[$memberId] = true;
            }
        }

        foreach ($members as $member) {
            $production = $member->getProducaoCooperativista();
            $production->save();
            $this->logger->info('Produção do cooperado {nome} criada. Número: {numero}. Líquido: {liquido}', [
                'nome' => $member->getName(),
                'numero' => $production->getDocumentNumber(),
                'liquido' => $production->getValues()->getLiquido(),
            ]);

            if (strlen($member->getTaxNumber()) > 11) {
                continue;
            }
            if ($this->dates->getDataPagamento()->format('m') === '12') {
                continue;
            }
            $frra = $member->getFrra();
            $frra->getValues()->setFrra($production->getValues()->getFrra())->setUpdated();
            $frra->save();
            $this->logger->info('FRRA do cooperado {nome} criada. Número: {numero}. Líquido: {liquido}', [
                'nome' => $member->getName(),
                'numero' => $frra->getDocumentNumber(),
                'liquido' => $frra->getValues()->getLiquido(),
            ]);
        }

        $inssIrpf = new IrpfRetidoNaNota(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
        );
        $inssIrpf->saveMonthTaxes();

        $cofins = new Cofins(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
        );
        $cofins->setTotalBrutoNotasClientes($this->movimentacao->getTotalBrutoNotasClientes());
        $cofins->saveMonthTaxes();

        $pis = new Pis(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
        );
        $pis->setTotalBrutoNotasClientes($this->movimentacao->getTotalBrutoNotasClientes());
        $pis->saveMonthTaxes();

        $iss = new Iss(
            entityManager: $this->entityManager,
            dates: $this->dates,
            documents: $this->documents,
            request: $this->request,
            documentConfiguration: $this->documentConfiguration,
        );
        $iss->setTotalBrutoNotasClientes($this->movimentacao->getTotalBrutoNotasClientes());
        $iss->saveMonthTaxes();
    }
}
