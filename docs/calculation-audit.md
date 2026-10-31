# Auditoria técnica e contábil da memória de cálculo

## Escopo e limites (29/10/2026)

Auditoria da rota `GET /calculos`, endpoint `GET /api/v1/calculos`, serviços `Movimentacao`, `ProducaoCooperativista`, `CustomerCostAllocator`, `ProductionDistributionPlanner`, `WeightedAllocator` e camadas de publicação no Akaunting. A interface passou a usar toda a largura disponível e expõe referências e descrição de cada indicador.

**Não se declara conformidade fiscal, contábil ou estatutária integral.** Uma implementação correta de fórmulas não demonstra que a política econômica foi regularmente aprovada em assembleia, que as classificações contábeis estão corretas nem que o lançamento fiscal está correto. Conferir estatuto vigente, atas, plano de contas, demonstrativos, contratos, retenções e parecer de contador habilitado antes de publicar valores reais.

## Linha de origem e transformação

| Etapa | Fonte/serviço | Validações e risco relevante |
| --- | --- | --- |
| Recebimentos de clientes | Akaunting, faturas + transações financeiras relacionadas, importadas para banco `report` | Competência contábil e status pago; recebimento parcial proporcionalizado; faturas não pagas excluídas; revisar retenções, anulações e diferenças entre valor bruto e líquido |
| Classificações | Categorias e hierarquia do Akaunting | Distinção receitas de clientes, custos por cliente e dispêndios internos; reconciliação de categorias obrigatória |
| Trabalho e pesos | Kimai + cadastro de cooperados e pesos | Identificador, cliente, competência, duração e peso; horas ponderadas não substituem conferência de direitos da Lei 12.690/2012 |
| Custos e margens | `Movimentacao` / `CustomerCostAllocator` | Custo por cliente distribuído entre documentos proporcionalmente à receita; receitas insuficientes bloqueadas |
| Taxa gerencial | `Movimentacao::percentualAdministrativo()` | Piso de custos, valor de referência e duas vezes os custos; não converter o percentual de referência em alíquota legal |
| Base e rateio | `ProducaoCooperativista` / `ProductionDistributionPlanner` / `WeightedAllocator` | Base por documento e rateio pelo trabalho ponderado; eventual trabalho abaixo do contratado pode reduzir o valor distribuível |
| Retiradas e tributos | `Values` / documentos / publicador específico LibreCode | FRRA, auxílio, bruto, INSS, IRPF, descontos e adiantamentos; dependem de regras aprovadas e legislação tributária |
| Publicação | `LibreCodeAkauntingPublisher` | Deve permanecer ação separada e expressamente autorizada; nunca disparar a partir da visualização da memória |

### Regras do relatório (perfil LibreCode atual)

- `T` = recebimentos de clientes elegíveis; `F` = recebimentos com percentual fixo; `M = T − F`.
- `C` = custos por cliente; `A = M − C` = base móvel líquida para a taxa administrativa.
- `D` = dispêndios internos; **mínimo** = `D`; **máximo de referência** = `2 × D`.
- `P` = `PERCENTUAL_MAXIMO` configurado (exemplo no `.env.example`: **25%**; valor efetivo deve ser verificado em cada instalação).
- `S = A × P/100` = valor de segurança. **Taxa administrativa = max(D, min(S, 2D))**. Se o piso for superior a `S`, o percentual administrativo efetivo **poderá superar P**. Portanto, `P` não funciona como teto absoluto. A regra dos 25% e o multiplicador 2 são **política operacional**, não imposição legal identificada.
- **Percentual efetivo** = `taxa administrativa / A × 100` quando há base suficiente. Sem base econômica, o processamento falha em vez de gerar produção impossível.
- **Base de produção por documento** = `(recebimento − custo atribuído àquele documento) × (1 − desconto aplicável / 100)`. Para cliente com várias faturas, o custo é rateado entre todas; a fórmula visual agora usa a mesma alocação do motor.
- **Reserva gerencial** = `taxa administrativa − dispêndios internos`. **Não é** o Fundo de Reserva de 10% sobre sobras líquidas do exercício nem o FATES/RATES mínimo de 5%, previstos no art. 28 da Lei 5.764/1971.
- **Saldo gerencial do mês** = `T − C − D − base de produção − reserva gerencial − descontos fixos + ajuste de clientes fixos`. O nome histórico `total_sobras_do_mes` não identifica, por si só, sobras líquidas do exercício à disposição da AGO.
- **Distribuição**: documentos por cooperado dependem de pesos e horas trabalhadas; direitos mínimos, retenções de tributos, FRRA e classificação contábil devem ser validados separadamente.

## Conclusões de auditoria

### Melhorias implementadas sem modificar política econômica
1. Layout de largura total para relatórios, composição em etapas e índice visual com destaque correlacionado por mouse/teclado.
2. Referência explicativa individual e orientações contábeis; não confundir saldo gerencial, reserva administrativa e fundos legais.
3. Fórmula visual de base de produção fiel à alocação por documento; escapamento HTML em nomes e valores de origem e links com `rel=noopener noreferrer`.
4. Validação estrita de competência `YYYY-MM` e sinalização quando parâmetros da apuração são simulados via URL.
5. Testes determinísticos para formatação/escaping, navegação visual, validação de parâmetros, proteção de saída e fórmula.

### Riscos materiais que NÃO podem ser resolvidos com mudança unilateral de percentuais

| Tema | Risco observado | Critério para encerrar |
| --- | --- | --- |
| Aritmética monetária | PHP `float` na soma, divisão e rateio, `JSON_TABLE ... DOUBLE` no SQL. Pode produzir frações de centavo e resultados sensíveis à ordem/etapa de arredondamento. | Política formal de arredondamento por etapa, comparação histórica centavo a centavo, migração consistente a `DECIMAL`/centavos inteiros ou BCMath |
| Alocação de custos e horas | Resíduo da divisão vai para o último participante/documento; na persistência o arredondamento ocorre em outras camadas. | Distribuição determinística de centavos pelo método dos maiores restos, com chave de desempate estável, após aprovação e testes de equivalência |
| Despesas e pagamentos parciais | Competência contábil, bruto, retenção, pagamento recebido e ajustes podem divergir. | Conciliação por documento, parcela, classificação, competência e impostos |
| Percentual de segurança e teto 2× | Origem documental da regra dos 25% e multiplicador 2 não comprovada nas fontes institucionais acessadas. | Estatuto, regimento ou ata que valide a política e sua vigência por competência |
| Fundo de Reserva, FATES e sobras anuais | Campos mensais são gerenciais, não lançamentos estatutários obrigatórios. | Plano de contas e deliberações de AGO, evidenciação ITG 2004 e conciliação anual |
| Tributação/retiradas | Não foram reexecutados cenários reais com notas fiscais, INSS, IRPF e situações particulares dos cooperados. | Parecer fiscal/contábil com evidências dos critérios, vigências e guias/lançamentos |
| Simulações | Parâmetros em query podem alterar o resultado visível. | Indicar simulação e impedir uso desse preview como autorização automática de pagamento |
| Segurança da saída | Fórmulas concatenavam dados de contato a HTML renderizado diretamente. | Sanitização/escaping e testes de regressão; proibir valores de origem arbitrários como marcação HTML |

### Avaliação das alternativas de precisão

- **Inteiros de centavos**: adequados para valores finais BRL, mas frações de centavo em juros, retenções, rateios e índices exigem trabalhar com precisão intermediária e uma política explícita antes da conversão.
- **BCMath** (PHP 8.4+ `bcround` / `BcMath\\Number`) ou **`brick/math` / `brick/money`**: números decimais independentes de representação binária, mas não são troca transparente de tipo em um sistema que já registra valores históricos. Requer versionamento da regra e testes de reconciliação.
- **MySQL `DECIMAL(p,s)` e `JSON_TABLE ... DECIMAL`** em vez de `DOUBLE`: evita arredondamentos binários na consulta de dados financeiros; verificar primeiro o formato da fonte e a política para itens/preços com mais de duas casas.
- **Arredondamento**: comparar `HalfAwayFromZero`, `HalfEven` e regras específicas de tributos; não escolher uma delas globalmente sem decisão técnica-contábil. O PHP `round` sobre `float` não resolve a exatidão do cálculo acumulado.

A migração do motor deve ocorrer em dois estágios: (1) apurador decimal paralelo **somente leitura**, comparação com períodos preservados e relatório de divergências; (2) aprovação e aplicação da política, com testes de somatório por documento, cooperado, competência e razão contábil. O PR presente não altera os montantes publicados.

## Referências verificáveis

- Brasil, [Lei 5.764/1971](https://www2.camara.leg.br/legin/fed/lei/1970-1979/lei-5764-16-dezembro-1971-357788-normaatualizada-pl.html), especialmente arts. 28 e 44.
- Brasil, [Lei 12.690/2012](https://www.planalto.gov.br/ccivil_03/_ato2011-2014/2012/lei/l12690.htm), especialmente arts. 7º, 11, 13 e 14.
- Conselho Federal de Contabilidade, [ITG 2004 — Entidade Cooperativa](https://cfc.org.br/noticias/plenario-do-cfc-aprova-a-norma-itg-2004-entidade-cooperativa/); [comentário técnico OCB](https://somoscooperativismo.coop.br/arquivos/publicacoes/cartilha-20itg-final.pdf).
- Aliança Cooperativa Internacional, [Identidade Cooperativa, terceiro princípio](https://ica.coop/en/cooperatives/cooperative-identity): participação econômica e destinação de excedentes.
- Aliança Cooperativa Internacional (2021), [Examinando a identidade cooperativa](https://coopidentity.ica.coop/assets/resources/congress_discussion_paper_final_en_2021-10-09.pdf), seção sobre remuneração do trabalho e excedentes.
- Morshed (2026), [Workplace Democracy Through Numbers](https://journals.sagepub.com/doi/10.1177/08863687261420502): o resumo acadêmico aborda transparência contábil e credibilidade das regras de participação em resultados; **não constitui validação das alíquotas brasileiras**.
- PHP, [BCMath\Number::round](https://www.php.net/manual/pt_BR/bcmath-number.round.php) e [round](https://www.php.net/manual/en/function.round.php).

### Recuperação automática de consultas à API Akaunting

A integração repete **somente GET/HEAD** após falhas de transporte ou HTTP
429, 502, 503 e 504, por no máximo três tentativas com intervalo limitado.
O cabeçalho numérico `Retry-After` é respeitado até dois segundos; uma
janela maior é devolvida como erro para nova execução posterior. Os logs
registram método/tentativa/status, sem tokens, parâmetros ou corpos HTTP.

**POST, PUT, PATCH e DELETE não recebem retry automático:** uma resposta
perdida não prova que a transação financeira não foi efetuada. Em especial,
uma falha após emitir um documento exige reconciliação no Akaunting antes
de qualquer repetição. As falhas 401/403 (permissões), 404 (endpoint),
422 (validação), configuração inválida, divergência de schema e regras
econômicas também não são corrigidas por nova tentativa automática.
