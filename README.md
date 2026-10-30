# Cálculo de produção cooperativista

ETL de produção cooperativista com conectores de dados, apuração econômica e publicação contábil separados por contratos de integração. O perfil padrão preserva o modelo operacional da LibreCode com Kimai, Akaunting e documentos de NFS-e municipais. Perfis adicionais podem definir outras métricas e políticas sem reutilizar implicitamente as regras financeiras da LibreCode.

## Requisitos do perfil LibreCode

| Sistema                            | Descrição                                                                                                 |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------- |
| [Kimai](https://www.kimai.org)     | Registro de horas trabalhadas por projeto, emissão de relatório de horas trabalhadas para clientes        |
| [Akaunting](https://akaunting.com) | Gestão financeira                                                                                         |
| [Nextcloud + Profile Fields](https://github.com/LibreCodeCoop/profile_fields/) | Diretório interno dos cooperados com os campos customizados `weight`, `tax_number` e `dependents` usados na produção |
| Site da prefeitura                 | Emissão de NFSe. Hoje o sistema dá suporte oficial apenas as prefeituras dos municípios do Rio e Niterói. |

## Ambiente de desenvolvimento e homologação

Execute a apuração em uma instalação e bancos **isolados** dos produtivos. Os dados importados podem conter informações pessoais e financeiras: mantenha dumps, `.env`, CSVs e logs fora do Git, sob permissões privadas e uma política de retenção.

1. Copie `.env.example` para `.env` e configure **somente credenciais de desenvolvimento**; não use tokens de escrita, senhas nem chaves de produção para homologar.
2. Verifique que `DATABASE_URL` aponta para um banco intermediário **próprio da homologação**, `DATABASE_AKAUNTING_URL` para a cópia local do banco do Akaunting e `AKAUNTING_API_BASE_URL` para uma API **local**, nunca para o serviço de produção.
   O bootstrap de testes recusa bancos remotos e URLs de API não locais. Chamadas HTTP reais sob APP_ENV=test também são bloqueadas fora de serviços locais; clientes HTTP simulados não acessam a rede.
3. Defina `APP_ENV=dev` (ou `test` para PHPUnit). Selecione `COOPERATIVE_PROFILE=librecode` somente para a política vigente; outros perfis exigem instância/banco próprios.
4. Evite publicar portas de banco no host e não conecte o ETL a redes externas quando estiver testando snapshots. Configure a infraestrutura de homologação existente em vez de reutilizar bancos de desenvolvimento compartilhados.

O projeto usa duas bases distintas: a do **ETL** (transformações, importações e auditoria) e a do **Akaunting** (documentos contábeis). Restaurar apenas uma delas não reproduz a execução histórica. O Kimai e as demais integrações exigem fontes autorizadas, ou um snapshot previamente importado.

## Ações para que tudo funcione
* Emissão de notas fiscais
  * Definir a descrição da nota fiscal com campos separados por `:` (dois pontos)
  * Quando for NFSe para mais de um setor de um mesmo CNPJ, acrescentar o campo `setor: <setor>`
* Nextcloud + Profile Fields
  * Criar no app `profile_fields` os campos `weight`, `tax_number` e `dependents`.
  * Preencher estes campos para cada cooperado; eles passam a ser a fonte oficial do peso, do CPF/CNPJ e da quantidade de dependentes usados no cálculo.
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
    * Todo custo que deve descontar do líquido da fatura do cooperado precisa estar abaixo da categoria pai `Cooperado > Produção > Desconto líquido`
      * Exemplo: `Cooperado > Produção > Desconto líquido > Plano de saúde`
      * Exemplo: `Cooperado > Produção > Desconto líquido > Telefonia`
    * Configure o id da categoria pai em `AKAUNTING_PARENT_DESCONTO_LIQUIDO_CATEGORY_ID`
    * Categorias fora desta hierarquia são ignoradas.
    * No campo "nota" (descrição) de cada desconto líquido deve conter a divisão por cooperado.
      Exemplo:
      ```csv
      Cooperado: Hedy Lamarr; CPF: 00000000000; Valor: 781,95
      Cooperado: Grace Hopper; CPF: 11111111111; Valor: R$ 439,55
      Cooperado: Ada Lovelace, CPF: 22222222222, Valor: 1.592,65
      Cooperado: Radia Perlman, CPF: 33333333333, Valor: R$12.345,67
      ```
      > **Validação monetária:** use vírgula como separador decimal e ponto
      > apenas entre grupos de três dígitos para milhares. São aceitos
      > `781,95`, `R$ 781,95`, `1.592,65` e `R$12.345,67`.
      > Um valor como `1592.65`, com ponto decimal, **interrompe o cálculo**
      > com uma mensagem de correção; não será interpretado como `159265`.
      > O formato é obrigatório: confira as notas antes de apurar a produção.
    * Customização da fatura (compra ou venda) ou transação deve ser inserida na descrição. Valores possíveis:
      | Nome                     | Descrição                                                                                                                                                                         |
      | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
      | NFSe                     | Número da NFSe, necessário para fazer ligação com a Nota Fiscal coletada da Prefeitura. Não estamos utilizando este recurso.                                                      |
      | Transação do mês         | Mês onde esta transação será contabilizada. Formato: `2023-09`                                                                                                                    |
      | CNPJ cliente             | CNPJ do cliente de quem será cobrado o valor                                                                                                                                      |
      | Setor                    | Setor do cliente, quando é um CNPJ com mais de um contrato                                                                                                                        |
      | Arquivar                 | `sim` = Arquivar transação e não utilizá-la.                                                                                                                                      |
      | Percentual desconto fixo | Ignora a taxa administrativa (somatório de todas as despesas do mês incluindo valor de NFSe e custos da coop do mês) e aplica apenas este perentual de desconto no valor da nota. |

      Valores customizados precisam ter o nome da propriedade separado do valor com dois pointos, exemplo:
      ```
      NFSe: 123456789
      Transação do mês: 2023-05
      ```

## Checklist de configuração da LibreCode

- Cada contato de cooperado no Akaunting deve ter identificação fiscal válida e verificável.
- Confira os IDs de categorias, itens e tributos no Akaunting e as variáveis correspondentes do `.env`, sem copiar credenciais de produção.
- Antes de uma publicação, valide pesos, dependentes e identificação no Profile Fields, apontamentos do Kimai, recebimentos efetivos, categorias e despesas no Akaunting e eventuais campos manuais de competência.

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
php ./bin/console doctrine:migrations:migrate -n
```

## Comandos
### Prévia e publicação

O comando `make:producao` calcula sem publicar por padrão. Para conferir valores, use `--csv`: a prévia inclui agora o número **esperado** do documento `PDC_...-AAAA-MM`, permitindo conciliação por identificador, e não por posição da linha.

```bash
php bin/console make:producao --ano-mes=2026-08 --baixar-dados=0 --as-of=2026-10-07 --csv
```

- `--ano-mes=AAAA-MM` identifica o **mês trabalhado**; 2026-08 corresponde à competência de documentos 2026-09, com pagamento previsto no mês seguinte.
- `--baixar-dados=1` (padrão) importa novamente as fontes externas e **altera o banco intermediário**. Para importar separadamente, use `etl:sync --ano-mes=AAAA-MM`.
- `--baixar-dados=0` apenas reutiliza dados já importados: **não** garante, sozinho, uma fotografia histórica, nem corrige importação incompleta. Aceita exclusivamente `0` ou `1`.
- `--as-of=AAAA-MM-DD` fixa a data de referência em uma prévia e não pode ser combinado com publicação.
- `--dias-uteis`, `--dia-util-pagamento` e `--percentual-maximo` afetam o cálculo; use somente parâmetros aprovados.
- `--atualiza-producao` **publica** documentos e tributos no Akaunting configurado; use apenas após conferência e autorização, nunca conectado à produção durante homologação.
- `--database` não grava dados nem publica por conta própria e agora é recusado explicitamente. `--ods` foi removido: não havia exportação ODS implementada.

Uma prévia recalculada pode divergir de uma fatura paga por mudanças posteriores nas fontes, descontos, adiantamentos ou critérios. **Não** altere o valor histórico automaticamente para fazer os relatórios coincidirem.

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

## Rotina de conferência mensal

1. **Conferir as origens**: fechamento das horas no Kimai, campos cadastrais no Profile Fields, identificação dos cooperados, pagamentos de clientes, notas, custos e categorias corretas no Akaunting. Campos livres como `Transação do mês`, `Arquivar: sim` e descontos fixos exigem conferência humana. Não use essas marcações para contornar uma diferença não investigada.
   Receitas e despesas vinculadas a clientes devem ter identificador explícito; referências ausentes não serão mais presumidas como o CNPJ da própria cooperativa.
2. **Importar e preservar o corte**: execute `etl:sync --ano-mes=AAAA-MM` com acesso autorizado e gere `audit:inputs --ano-mes=AAAA-MM --revision="$(git rev-parse HEAD)"`. Preserve snapshot intermediário e manifesto em área confidencial. Importar outra competência pode mudar as bases da anterior.
3. **Calcular sem publicar**: `make:producao --ano-mes=AAAA-MM --baixar-dados=0 --csv`; use `--as-of` em auditoria histórica. Confira todos os números `PDC_`, bases, retenções, adiantamentos, descontos, FRRA, totais e datas. Guarde o CSV com acesso restrito.
4. **Conciliar em leitura com o Akaunting**: compare `document_number`, situação (`draft`, `paid` ou `cancelled`), competência, itens e notas. Não sobrescreva faturas finalizadas. Toda divergência exige explicação sobre dados de origem, data de corte, política e eventuais ajustes posteriores.
5. **Aprovar ou bloquear**: obtenha a revisão financeira/contábil aplicável antes de usar `--atualiza-producao` no ambiente aprovado. Ausência de horas, custos sem receita, capital elegível insuficiente ou inconsistência cadastral bloqueiam a publicação. Após uma falha de publicação, confira os registros existentes antes de repetir a operação.

## Logs e sigilo

O Monolog registra a execução em `var/log/<APP_ENV>.log` (por exemplo, `var/log/dev.log`) e os erros em `var/log/calculation-errors.log`, ambos com rotação. Acompanhe localmente com:

```bash
tail -f var/log/dev.log var/log/calculation-errors.log
```

Logs, relatórios CSV e snapshots podem conter dados pessoais ou financeiros. Nunca os anexe a um PR ou issue públicos.

### Requisitos e qualidade

- **PHP:** imagem de execução declarada uma única vez em `ARG PHP_IMAGE` de `.docker/php/Dockerfile`; os workflows leem esse valor automaticamente. O `composer.json` mantém apenas a versão mínima compatível.
- **Node.js:** versão de execução de testes declarada em `.nvmrc`. O frontend usa JavaScript nativo, sem dependências npm instaladas.
- **Dependências:** `composer update -W` no backend, com ferramentas de desenvolvimento isoladas em `vendor-bin`. O Dependabot acompanha Composer, GitHub Actions e Docker Compose; o Renovate acompanha o Dockerfile, `.nvmrc` e a imagem MySQL usada pelo CI. As atualizações exigem revisão e CI aprovada.
- **Modernização de PHP:** `composer run rector:check` verifica as refatorações, e `composer run rector:fix` aplica as alterações.
- **Verificação:** `composer run test:unit`, `composer run cs:check` e `composer run psalm`. Os testes de JavaScript usam `node --test tests/js/*.test.cjs`. A CI executa esses testes em um workflow separado, com limites mínimos de cobertura (90% de linhas, 80% de ramos e 75% de funções).

### Arquitetura do ETL e apuração por competência

O pipeline mantém quatro limites: conectores de origem (Kimai, NFS-e e Akaunting), banco de transformação, apuração econômica e publicação opcional no Akaunting. As etapas de origem implementam `SourceStep` e são orquestradas por `SourcePipeline`, preservando a ordem das dependências. `ProductionDistributionPlanner` calcula a distribuição sem acessar APIs ou saldo bancário. O capital social é consultado separadamente e não integra receitas de clientes.

A competência mensal é validada como `YYYY-MM`. A importação completa do Kimai reconcilia alterações e exclusões. Em casos não cobertos pela reconciliação de lançamentos financeiros, utilize a reconstrução do banco intermediário e importe novamente. A nova classificação contraditória de percentuais fixos e móveis do mesmo cliente interrompe o cálculo para análise, em vez de permitir uma distribuição incompleta.

Audite os dados intermediários sem qualquer escrita financeira:

```bash
php bin/console audit:inputs --ano-mes=2025-04 --revision="$(git rev-parse HEAD)"
```

O relatório compacto mostra contagens e fingerprints SHA-256 de entradas, pesos e configurações (incluindo dependências e runtime), sem expor valores financeiros ou dados pessoais. Para preservar as entradas integrais de uma execução, adicione `--save-bundle`. O arquivo ficará em `var/audit/` com permissão privada. Verifique posteriormente com `php bin/console audit:verify --file=var/audit/AAAA-MM/NOME.json`; a verificação detecta alterações no conteúdo em relação ao manifesto, mas não substitui armazenamento imutável externo nem uma assinatura digital independente. **Contém dados pessoais e financeiros confidenciais:** proteja o acesso, os backups e a retenção conforme a LGPD; não publique em Git ou PRs.

Simule o resultado de uma competência antiga com uma data de referência operacional definida:

```bash
php bin/console make:producao --ano-mes=2025-04 --baixar-dados=0 --as-of=2025-05-10
```

`--as-of` é exclusivo de prévias e não pode ser combinado com `--atualiza-producao`.

Os relatórios e arquivos de auditoria permitem conferir os dados **efetivamente disponíveis na execução indicada**, seus parâmetros e a versão do código. A análise de qualquer competência deve considerar o conjunto de comprovantes, políticas aprovadas e dados de origem aplicáveis ao período; a geração de um relatório não equivale, por si só, à certificação contábil da competência.

### Arquitetura por perfis de cooperativa

- **Ingestão:** cada integração implementa `SourceStep`. `SourcePipeline` seleciona somente as etapas declaradas pelo perfil, sem alterar os conectores existentes.
- **Perfil:** `CooperativeProfile` declara as origens e a política de distribuição. `ProfileRegistry` seleciona o perfil **por instalação** usando `COOPERATIVE_PROFILE` (padrão: `librecode`). Não existe isolamento de múltiplas cooperativas no mesmo banco; cada cooperativa precisa de uma instalação/banco independente.
- **Métricas:** `MetricStrategy` permite novas unidades de produção. As implementações de exemplo são `elapsed-hours` (horas registradas) e `work-entries` (quantidade de apontamentos faturáveis). Os dados importados do Kimai são normalizados por `WorkMetricsReader`; o perfil de exemplo usa `BasicUsers`, sem exigir CPF, Profile Fields ou vínculo prévio com o Akaunting.
- **Política de rateio:** `AllocationPolicy` recebe valores elegíveis aprovados e medições normalizadas, sem depender do Akaunting. `HoursProportionalPolicy` é **apenas um exemplo técnico** de cálculo proporcional por cliente, não uma regra atribuída à Inovar ou a qualquer outra cooperativa.
- **Publicação:** `ProductionPublisher` e `PublicationRouter` isolam a gravação. O único publicador homologado por padrão é o da LibreCode (produção, FRRA e tributos). Para outro perfil, a publicação fica bloqueada até que exista implementação e aprovação contábil específicas.

Configure `COOPERATIVE_PROFILE=hours` em uma **instância de homologação própria**, depois importe as origens desse perfil:

```bash
php bin/console etl:sync --ano-mes=2026-07
```

Forneça um arquivo JSON de **bases elegíveis aprovadas**. O programa não presume que saldo bancário, faturamento bruto ou valor de nota seja distribuível:

```json
[
  {"customer_reference": "CLIENTE-EXEMPLO", "eligible_amount": 1200.00, "currency": "BRL"}
]
```

Execute a prévia com uma métrica configurada:

```bash
php bin/console allocation:preview --ano-mes=2026-07 --receipts-file=/caminho/restrito/bases.json --metric=elapsed-hours
```

A prévia inclui valores por cliente/cooperado, total elegível, total distribuído e fingerprint do arquivo de bases, sem emissão de documentos. `work-entries` demonstra uma métrica alternativa. Para usar outra política econômica, implemente a interface de rateio, o perfil e, quando aprovado, um publicador financeiro próprio; não altere diretamente os conectores Kimai ou as regras da LibreCode.

O comando `make:producao` e a interface de produção contábil existente permanecem exclusivos do perfil `librecode`. O Akaunting armazena documentos; a efetiva emissão fiscal municipal/validação de NFS-e, quando aplicável, é responsabilidade da integração específica e precisa ser testada separadamente.

## Implantação em produção

A instalação produtiva usa o [Compose versionado e o procedimento operacional](docs/production-deployment.md). As configurações da aplicação, Nginx e PHP ficam no Git; credenciais, bancos de dados e hashes do HTTP Basic Auth ficam exclusivamente no servidor.
