## Pendências
* Akaunting
  * Dados de clientes
    * [ ] Definir número de identificação fiscal (cpf/cnpj) para todo fornecedor/cliente. Quando houver nota emitida para mais de um setor de um mesmo CNPJ e figurarem diferentes contas na LibreCode (conta = contrato), concatenar da seguinte forma: `<cpf/CNPJ>|<setor>`
  * Receitas
    * [ ] Toda transação com nota fiscal emitida pela LibreCode deve conter o número da nota fiscal no campo `referência`.
    * [ ] Sempre que for receita vind de cliente deve possuir a categoria `Recorrência` ou `Serviço`
  * Custos
    * [ ] Categorizar transação de saída como `Custos clientes` quando forem custos de clientes
    * [ ] Sempre que for custo reembolsável pelo cliente, adicionar CPF/CNPJ na transação no campo `Referência` para que seja possível identificar qual cliente deverá reembolsar este custo de entrada. Lembrar de acrescentar o setor sempre que necessário.
    * [ ] Importar custos de clientes

## Setup
Primeiro clone o repositório e entre nele.

Copie o arquivo `.env.example` para `.env`

Edite o arquivo `.env` preenchendo os dados necessários.

Em um terminal:
```
docker compose up
```
Em outro terminal:
```
docker compose exec php bash
composer install
vendor/bin/phinx migrate
```

## Comandos
### Exemplos

```bash
./bin/import get:customers --database
./bin/import get:nfse --database --ano-mes=2022-09
./bin/import get:projects --database
./bin/import get:timesheet --database --year-month=2022-09
./bin/import get:transactions --database --year-month=2022-09
./bin/import get:users --database
./bin/import report:bruto --ano-mes=2022-12 --csv
```

## Logs
Tudo (ou quase tudo) o que é feito fica registrado em log e pode ser monitorado em:

```
tail -f logs/system.log
```