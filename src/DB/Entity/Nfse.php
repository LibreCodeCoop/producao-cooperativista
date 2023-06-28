<?php

namespace ProducaoCooperativista\DB\Entity;

class Nfse
{
    private int $id;
    private ?int $numero;
    private ?int $numeroSubstituta;
    private ?string $cnpj;
    private ?string $razaoSocial;
    private ?\DateTime $dataEmissao;
    private ?float $valorServico;
    private ?float $valorCofins;
    private ?float $valorIr;
    private ?float $valorPis;
    private ?float $valorIss;
    private ?string $discriminacaoNormalizada;
    private ?string $setor;
    private ?string $codigoCliente;
    private ?array $metadata;
}
