# Cálculo de produção cooperativista

Calcular o bruto da produção cooperativista por cooperado com base em dados coletados do Akaunting, Kimai e site da prefeitura.

## Requisitos

| Sistema                            | Descrição                                                                                                  |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| [Kimai](https://www.kimai.org)     | Registro de horas trabalhadas por projeto, emissão de relatório de horas trabalhadas para clientes         |
| [Akaunting](https://akaunting.com) | Gestão financeira                                                                                          |
| Site da prefeitura                 | Emissão de NFSe. Hoje o sistema dá suporte oficial apenas as prefeituras dos municípios do Rio e Niteróio. |

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
    * Customização da tranzação deve ser inserida na descrição. Valores possíveis:
      | Valor            | Descrição                                                  |
      | ---------------- | ---------------------------------------------------------- |
      | NFSe             | Número da NFSe                                             |
      | Transação do mês | Mês onde esta transação será contabilizada                 |
      | CNPJ cliente     | CNPJ do cliente de quem será cobrado o valor               |
      | Setor            | Setor do cliente, quando é um CNPJ com mais de um contrato |

      Valores customizados precisam ter o nome da propriedade separado do valor com dois pointos, exemplo:
      ```
      NFSe: 123456789
      Transação do mês: 2023-05
      ```

## TO-DO para prod
* Todo contact do tipo vendor referente a um cooperado precisa ter tax_number
* Cadastrar tudo o que está no `.env` no Akaunting e atualizar o `.env` com os id's

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
php ./bin/import migrations:migrate -n
```

## Comandos
### Exemplos

O principal comando é:

```bash
make:producao

Description:
  Produção cooperativista por cooperado

Usage:
  make:producao [options]

Options:
      --csv                                  To output as CSV
      --database                             Save to default database
      --previsao                             Previsão de cálculo e não o valor real com base nas NFSe.
      --ano-mes=ANO-MES                      Ano e mês para gerar a produção cooperativista, formato: YYYY-mm
      --dias-uteis=DIAS-UTEIS                Total de dias úteis no mês trabalhado [default: 22]
      --percentual-maximo=PERCENTUAL-MAXIMO  Percentual máximo para pagamento de dispêndios [default: 25]
      --baixar-dados=BAIXAR-DADOS            Acessa todas as bases externas e atualiza o banco de dados local. Valores: 1 = sim, 0 = não. [default: 1]
      --cadastrar-producao                   Cadastra a produção cooperativista
      --ods                                  To output as ods
```
> OBS: Este comando não salva o cálculo em lugar algum pois estes dados devem ser inseridos no sistema utilizado para gerar a produção de cada mês.

`--baixar-dados=0`

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