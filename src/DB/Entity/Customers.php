<?php

namespace ProducaoCooperativista\DB\Entity;

class Customers
{
    private int $id;
    private ?string $name;
    private ?string $number;
    private ?string $comment;
    private ?int $visible;
    private ?int $billable;
    private ?string $currency;
    private ?string $color;
    private ?string $vatId;
    private ?int $timeBudget;
}
