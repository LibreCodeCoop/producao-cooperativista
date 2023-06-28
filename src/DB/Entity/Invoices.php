<?php

namespace ProducaoCooperativista\DB\Entity;

class Invoices
{
    private int $id;
    private ?string $type;
    private ?\DateTime $issuedAt;
    private ?\DateTime $dueAt;
    private ?string $transactionOfMonth;
    private ?float $amount;
    private ?string $currencyCode;
    private ?string $documentNumber;
    private ?string $nfse;
    private ?string $taxNumber;
    private ?string $customerReference;
    private ?int $contactId;
    private ?string $contactReference;
    private ?string $contactName;
    private ?string $contactType;
    private ?int $categoryId;
    private ?string $categoryName;
    private ?string $categoryType;
    private ?array $metadata;
}
