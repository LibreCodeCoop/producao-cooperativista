<?php

namespace ProducaoCooperativista\DB\Entity;

class Transactions
{
    private int $id;
    private ?string $type;
    private ?\DateTime $paidAt;
    private ?string $transactionOfMonth;
    private ?float $amount;
    private ?string $currencyCode;
    private ?string $reference;
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
