# Cálculo de produção cooperativista

Calcular o bruto da produção cooperativista por cooperado com base em dados coletados do Akaunting, Kimai e site da prefeitura.

## Requisitos

| Sistema                            | Descrição                                                                                                  |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| [Kimai](https://www.kimai.org)     | Registro de horas trabalhadas por projeto, emissão de relatório de horas trabalhadas para clientes         |
| [Akaunting](https://akaunting.com) | Gestão financeira                                                                                          |
| Site da prefeitura                 | Emissão de NFSe. Hoje o sistema dá suporte oficial apenas as prefeituras dos municípios do Rio e Niterói.  |

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
  * Divisão de sobras
    * Criar uma fatura de venda com categoria `Cliente > Interno > Cooperado > Produção > Distribuição de sobras`
    * Colocar o cliente como `LibreCode` (é um cliente interno)
    * Inserir um item "Bruto produção" e remover a descrição padrão
    * No valor do item, inserir o valor que será dividido
    * Campo de anotações:
      * Colocar o motivo da divisão de sobras bem detalhado
      * Informar se é transaçâo para algum mês específico
    * Criar a fatura
    * Marcar fatura como enviada
    * Cancelar fatura. Após cancelada, a fatura não será contabilizada no mês mas será considerado o valor dela para distribuição de sobras.
  * Custos
    * Categorizar transação de saída como `Cliente (custo)` quando forem custos de clientes
    * Sempre que for custo reembolsável pelo cliente, adicionar `<cpf/CNPJ>|<setor>` na transação no campo `Referência` para que seja possível identificar qual cliente deverá reembolsar este custo de entrada. Lembrar de acrescentar o setor sempre que necessário.
    * Plano de saúde deve ser categorizado como `Plano de saúde`
    * No campo "nota" (descrição) do plano de saúde deve conter a divisão das sobras do plano de saúde.
      Exemplo:
      ```csv
      Cooperado: Hedy Lamarr; CPF: 00000000000; Valor: 781,95
      Cooperado: Grace Hopper; CPF: 11111111111; Valor: R$ 439,55
      Cooperado: Ada Lovelace, CPF: 11111111111, Valor: R$439,55
      ```
      > **Dica**: Esta descrição é coletada com a seguinte regex:
      > ```
      > /^Cooperado: .*CPF: (?<CPF>\d+)[,;]? Valor: (R\$ ?)?(?<value>.*)$/i
      > ```
    * Customização da fatura (compra ou venda) ou transação deve ser inserida na descrição. Valores possíveis:
      | Nome             | Descrição                                                      |
      | ---------------- | -------------------------------------------------------------- |
      | NFSe             | Número da NFSe                                                 |
      | Transação do mês | Mês onde esta transação será contabilizada. Formato: `2023-09` |
      | CNPJ cliente     | CNPJ do cliente de quem será cobrado o valor                   |
      | Setor            | Setor do cliente, quando é um CNPJ com mais de um contrato     |
      | Arquivar         | `sim` = Arquivar transação e não utilizá-la.                   |

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
      --csv                                    To output as CSV
      --database                               Save to default database
      --ano-mes=ANO-MES                        Ano e mês para gerar a produção cooperativista, formato: YYYY-mm
      --dias-uteis=DIAS-UTEIS                  Total de dias úteis no mês trabalhado. Se não informar, irá calcular com base nos dias úteis de um mês considerando apenas feriados nacionais.
      --dia-util-pagamento=DIA-UTIL-PAGAMENTO  Número ordinal do dia útil quando o pagamento será feito [default: 5]
      --percentual-maximo=PERCENTUAL-MAXIMO    Percentual máximo para pagamento de dispêndios [default: 25]
      --baixar-dados=BAIXAR-DADOS              Acessa todas as bases externas e atualiza o banco de dados local. Valores: 1 = sim, 0 = não. [default: 1]
      --atualiza-producao                      Atualiza a produção cooperativista no Akaunting
      --ods                                    To output as ods
```

`--baixar-dados=0`

Após executar uma vez e constatar que baixou todos os dados corretamente, você pode usar esta opção para não ficar baixando os dados de todas as fontes externas o tempo inteiro pois baixar isto tudo é o que hoje faz este comando demorar um pouco. Esta opção só é útil caso você queira ficar executando o mesmo comando mais de uma vez quando for analizar os dados importados ou os logs do sistema ou debugar a aplicação.

O comando principal é composto pela execução independente de cada um dos comandos abaixo que você provavelmente não precisará usar:

```bash
Available commands:
 get
  get:categories                     Get categories
  get:customers                      Get customers
  get:invoices                       Get invoices
  get:nfse                           Get NFSe
  get:projects                       Get projects
  get:taxes                          Get taxes
  get:timesheets                     Get timesheets
  get:transactions                   Get transactions
  get:users                          Get users
```

## Logs
Tudo (ou quase tudo) o que é feito fica registrado em log e pode ser monitorado em:

```bash
tail -f logs/system.log
```