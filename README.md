# Cálculo de produção cooperativista

Calcular o bruto da produção cooperativista por cooperado com base em dados coletados do Akaunting, Kimai e site da prefeitura.

## Ações para que tudo funcione
* Emissão de notas fiscais
  * Definir a descrição da nota fiscal com campos separados por `:` (dois pontos)
  * Quando for NFSe para mais de um setor de um mesmo CNPJ, acrescentar o campo `setor: <setor>`
* Akaunting
  * Dados de clientes
    * Definir número de identificação fiscal (cpf/cnpj) para todo fornecedor/cliente. Quando houver nota emitida para mais de um setor de um mesmo CNPJ e figurarem diferentes contas na LibreCode (conta = contrato), concatenar da seguinte forma: `<cpf/CNPJ>|<setor>`
  * Receitas
    * Toda transação com nota fiscal emitida pela LibreCode deve conter o número da nota fiscal no campo `referência`.
    * Sempre que for receita vinda de cliente deve possuir a categoria `Recorrência` ou `Serviço`
  * Custos
    * Categorizar transação de saída como `Cliente (custo)` quando forem custos de clientes
    * Sempre que for custo reembolsável pelo cliente, adicionar `<cpf/CNPJ>|<setor>` na transação no campo `Referência` para que seja possível identificar qual cliente deverá reembolsar este custo de entrada. Lembrar de acrescentar o setor sempre que necessário.
    * Plano de saúde deve ser categorizado como `Plano de saúde`

## Setup
Primeiro clone o repositório e entre nele.

Copie o arquivo `.env.example` para `.env`

Edite o arquivo `.env` preenchendo os dados necessários.

Em um terminal:
```bash
docker compose up
```
Em outro terminal:
```bash
docker compose exec php bash
composer install
vendor/bin/phinx migrate
```

## Comandos
### Exemplos

O principal comando é:

```bash
./bin/import report:bruto --ano-mes=2022-12 --csv
```
> OBS: Este comando não salva o cálculo em lugar algum pois estes dados devem ser inseridos no sistema utilizado para gerar a produção de cada mês.

`--csv` Esta opção é importante para ver a saída de dados senão o comando vai executar sem exibir nada.

`--atualizar-dados=0`

Após executar uma vez e constatar que baixou todos os dados corretamente, você pode usar esta opção para não ficar baixando os dados de todas as fontes externas o tempo inteiro pois baixar isto tudo é o que hoje faz este comando demorar um pouco. Esta opção só é útil caso você queira ficar executando o mesmo comando mais de uma vez quando for analizar os dados importados ou os logs do sistema ou debugar a aplicação.

O comando principal é composto pela execução independente de cada um dos comandos abaixo que você provavelmente não precisará usar:

```bash
./bin/import get:customers --database
./bin/import get:nfse --database --ano-mes=2022-09
./bin/import get:projects --database
./bin/import get:timesheet --database --year-month=2022-09
./bin/import get:transactions --database --year-month=2022-09
./bin/import get:users --database
```

## Logs
Tudo (ou quase tudo) o que é feito fica registrado em log e pode ser monitorado em:

```bash
tail -f logs/system.log
```