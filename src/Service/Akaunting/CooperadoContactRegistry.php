<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Akaunting;

use App\Entity\Akaunting\Contacts;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Resolve existing vendors before creating new ones, so a resumed monthly
 * calculation does not create duplicate contacts after a partial failure.
 */
final class CooperadoContactRegistry
{
    public function __construct(private EntityManagerInterface $akauntingManager)
    {
    }

    public function getOrCreate(string $name, string $taxNumber, int $companyId, DateTimeInterface $date): int
    {
        if (trim($taxNumber) === '' || $companyId <= 0) {
            throw new \InvalidArgumentException('Cooperado sem identificação fiscal ou empresa válida.');
        }

        $existing = $this->akauntingManager->getConnection()->fetchOne(
            "SELECT id FROM contacts WHERE company_id = :company
                AND tax_number = :tax_number AND type IN ('vendor', 'employee')
                AND deleted_at IS NULL ORDER BY type LIMIT 1",
            ['company' => $companyId, 'tax_number' => $taxNumber]
        );
        if ($existing !== false && (int) $existing > 0) {
            return (int) $existing;
        }

        $contact = new Contacts();
        $contact
            ->setCompanyId($companyId)
            ->setType('vendor')
            ->setName($name)
            ->setTaxNumber($taxNumber)
            ->setCountry('BR')
            ->setCurrencyCode('BRL')
            ->setEnabled(1)
            ->setCreatedAt(\DateTime::createFromInterface($date))
            ->setUpdatedAt(\DateTime::createFromInterface($date));

        $this->akauntingManager->persist($contact);
        $this->akauntingManager->flush();
        $id = $contact->getId();
        if ($id <= 0) {
            throw new RuntimeException('O contato foi criado, mas o Akaunting não retornou seu identificador.');
        }
        return $id;
    }
}
