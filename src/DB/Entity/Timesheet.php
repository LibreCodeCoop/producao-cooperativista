<?php

namespace ProducaoCooperativista\DB\Entity;

class Timesheet
{
    private int $id;
    private ?int $activityId;
    private ?int $projectId;
    private ?int $userId;
    private ?\DateTime $begin;
    private ?\DateTime $end;
    private ?int $duration;
    private ?string $description;
    private ?float $rate;
    private ?float $internalrate;
    private ?int $exported;
    private ?int $billable;
}
