<?php

/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace App\Tests\Service\Cooperative;

use App\Service\Cooperative\HoursProfile;
use App\Service\Cooperative\LibreCodeProfile;
use App\Service\Cooperative\ProfileRegistry;
use App\Service\Cooperative\PublicationRouter;
use App\Service\Cooperative\LibreCode\LibreCodeAkauntingPublisher;
use App\Service\Import\SourcePipeline;
use App\Service\ProducaoCooperativista;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CooperativeProfilesTest extends KernelTestCase
{
    private string|false $original;

    protected function setUp(): void
    {
        $this->original = getenv('COOPERATIVE_PROFILE');
    }

    protected function tearDown(): void
    {
        if ($this->original === false) {
            putenv('COOPERATIVE_PROFILE');
        } else {
            putenv('COOPERATIVE_PROFILE=' . $this->original);
        }
        parent::tearDown();
    }

    public function testLibreCodePreservesEveryOriginalConnectorAndPublisher(): void
    {
        putenv('COOPERATIVE_PROFILE=librecode');
        self::bootKernel();
        $container = static::getContainer();
        $profiles = $container->get(ProfileRegistry::class);
        $sources = $container->get(SourcePipeline::class);

        self::assertSame('librecode', $profiles->active()->id());
        self::assertSame(new LibreCodeProfile()->sources(), $sources->selectedStageNames());
        self::assertContains('kimai.users.basic', $sources->stageNames());
        self::assertContains('librecode', $container->get(PublicationRouter::class)->available());
    }

    public function testOtherCooperativeCanSelectKimaiOnlyAndCannotUseLibreCodeSettlement(): void
    {
        putenv('COOPERATIVE_PROFILE=hours');
        self::bootKernel();
        $container = static::getContainer();
        $profile = $container->get(ProfileRegistry::class)->active();
        self::assertSame('hours-proportional', $profile->allocationPolicyId());
        self::assertSame(
            ['kimai.customers', 'kimai.projects', 'kimai.timesheets', 'kimai.users.basic'],
            $container->get(SourcePipeline::class)->selectedStageNames(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('política de emissão');
        $container->get(ProducaoCooperativista::class)->getProducaoCooperativista();
    }

    public function testDirectLibreCodePublisherCannotIssueDocumentsForAnotherProfile(): void
    {
        putenv('COOPERATIVE_PROFILE=hours');
        self::bootKernel();
        $publisher = static::getContainer()->get(LibreCodeAkauntingPublisher::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('não corresponde ao perfil ativo');
        $publisher->publish([], []);
    }

    public function testUnregisteredProfileIsRejectedInsteadOfApplyingLibreCodeDefaults(): void
    {
        $registry = new ProfileRegistry([new LibreCodeProfile(), new HoursProfile()], 'unreviewed');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('não registrado');
        $registry->active();
    }

    public function testDuplicatedProfileKeysAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProfileRegistry([new LibreCodeProfile(), new LibreCodeProfile()]);
    }
}
