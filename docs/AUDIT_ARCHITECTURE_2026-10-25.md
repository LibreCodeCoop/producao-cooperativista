# Auditoria local da arquitetura da produção cooperativista — 25/10/2026

**Escopo:** mudanças locais, sem commit, PR nem alterações nas fontes financeiras de produção.

## Limite econômico e governança

A base de produção é derivada de lançamentos da competência, classificados por cliente e natureza econômica. **Saldo bancário não é base de distribuição**. No estado auditado, o método `Movimentacao::getMovimentacaoFinanceira()` consolida transações, documentos vinculados a transações e documentos sem transação vinculada; estes últimos **não são necessariamente recebimentos disponíveis**. A autorização para reconhecer documentos sem recebimento deve ser encontrada em ato formal. Esta auditoria **não alterou unilateralmente o critério de elegibilidade**. A necessidade de identificar pagamentos parciais e recebimentos efetivos segue aberta.

Também **não foram alterados** os percentuais administrativos aprovados, a ajuda de custo, o FRRA, os modelos de INSS/IRPF, a destinação de sobras ou mecanismos de capitalização. Todas essas matérias exigem reconstituição da legislação vigente, estatuto, RFCs, atas e conciliação contábil. Não confundir remuneração pela produção, sobras do exercício, integralização de capital social ou reservas estatutárias.

## Ajustes técnicos realizados

1. **Alocação pura e verificável:** `WeightedAllocator` soma as horas ponderadas por cooperado, valida os pesos antes do cálculo e verifica a conservação do montante distribuído. Evita contar pesos acumulados repetidamente, inclusive em múltiplos apontamentos do mesmo cliente. Sem dependências de banco ou APIs.
2. **Distribuição e estado:** `ProducaoCooperativista` reutiliza a alocação, indexa apontamentos por cliente no desconto fixo, evita pagar sobras múltiplas vezes ao mesmo cooperado e só considera a distribuição completa quando concluída.
3. **Custos por cliente:** `Movimentacao` carrega a lista uma única vez e aplica corretamente o filtro de contratos com desconto fixo, mantendo as deduções agregadas historicamente praticadas no cálculo global.
4. **Movimentos com IDs homônimos:** faturas e transações independentes deixam de se sobrescrever por terem o mesmo número de ID. Duplicações da mesma origem geram falha explícita; não se perde movimentação silenciosamente.
5. **Categorias:** `CategoryHierarchy` cria índice em memória com proteção contra ciclos, evitando buscas recursivas repetidas. O repositório de categorias recebe o resultado pelo cálculo de descendentes.
6. **Consultas repetidas:** descontos líquidos são consultados uma vez por competência; adiantamentos de todos os cooperados são buscados em lote. O resultado é atribuído individualmente, sem execução de uma consulta por cooperado.
7. **Persistência:** `BatchPersistence` agrupa operações Doctrine em até 250 registros por flush para categorias, faturas, transações, tributos e usuários, mantendo `saveRow` disponível para alterações individuais.
8. **Prévia sem gravação externa:** cadastro de contatos faltantes no Akaunting foi transferido da consulta de cálculo para o método explícito de geração de documentos.
9. **Falhas e logs:** importação, cálculo, gravação e exportação informam a fase; o comando preserva stack trace no servidor e retorna código de falha; SSE envia evento `failed`, nunca `done` para um comando malsucedido. A tela não apaga tentativas anteriores e apresenta código da execução. Logs ganham rotação diária; os logs anteriores não foram apagados.

## Validação

- Testes originais antes da intervenção: **37 testes, 66 asserções**, com banco isolado.
- As novas provas cobrem pesos/valores negativos, denominação conservada, custos mistos, IDs repetidos entre tabelas, fluxo de erro de importação/gravação, lote de documentos, hierarquia de categorias e persistência em blocos.
- PHPUnit, validação de sintaxe e PHP-CS-Fixer executados em contêiner PHP 8.4 com testes conectados **somente** a um MySQL temporário numa rede Docker interna.
- Psalm identificou 279 apontamentos no conjunto atual, majoritariamente ligados a padrões legados de propriedades/métodos dinâmicos, contratos de ORM e regras de estilo não necessariamente cobertos pelo baseline; sua aprovação global permanece pendente. Novas classes de `Calculation` e `Import` não apresentaram erros na execução observada.

## Questões não resolvidas automaticamente

| Tema | Risco / ação requerida |
| --- | --- |
| Receita faturada não recebida | Comprovar se pode integrar capital elegível; a consulta atual inclui faturas sem transação. |
| Pagamentos parciais | Verificar como múltiplas transações do mesmo documento são consolidadas; proibir soma fictícia. |
| Custo de cliente com várias faturas | Validar critério de rateio entre contratos ou notas com descontos diferentes. |
| Política administrativa | Taxa pode superar o percentual configurado quando custo mínimo exige; o sistema agora alerta, mas a regra econômica depende da aprovação competente. |
| FRRA, ajuda de custo, tributos e sobras | Conciliar rubricas com contabilidade, legislação e atas antes de mudar percentuais ou bases. |
| Reexecução após gravação parcial | Não há idempotência/transação única garantida para todos os serviços externos. Não repetir uma geração sem conferir documentos já emitidos. |
| Segurança HTTP | Endpoints de ações mutáveis usam GET, inclusive atualização e reset do banco local; exigem revisão de autorização, CSRF e semântica HTTP. |
| N+1 na importação externa | As buscas de detalhes de usuários/projetos e os lookups de campos do Nextcloud ainda requerem perfilamento e possível API de listagem em lote. |
| Reprodutibilidade | Formalizar snapshot, vigência das políticas, aprovador e trilha de auditoria por competência. |

**Próximo aceite:** reconciliar ao menos uma competência completa com os dados de horas do Kimai, a base elegível reconciliada do Akaunting e os documentos oficiais que validam cada regra; executar comparações antes/depois e testes de integração sem acesso a dados de produção.
