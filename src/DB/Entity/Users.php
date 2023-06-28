<?php

namespace ProducaoCooperativista\DB\Entity;

class Users
{
    private int $id;
    private ?string $alias;
    private ?string $kimaiUsername;
    private ?int $akauntingContactId;
    private ?string $taxNumber;
    private ?int $dependents;
    private ?float $healthInsurance;
    private ?int $enabled;
    private ?array $metadata;
}
