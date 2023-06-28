<?php

namespace ProducaoCooperativista\DB\Entity;

class Projects
{
    private int $id;
    private ?string $parentTitle;
    private ?int $customerId;
    private ?string $name;
    private ?\DateTime $start;
    private ?\DateTime $end;
    private ?string $comment;
    private ?int $visible;
    private ?int $billable;
    private ?string $color;
    private ?int $globalActivities;
}
