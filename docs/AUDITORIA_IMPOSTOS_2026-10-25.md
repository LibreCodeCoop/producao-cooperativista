# Auditoria da dependência de impostos — 25/10/2026

**Situação:** proposta corrigida somente na cópia local `.audit-dependencies/impostos`; a aplicação principal ainda utiliza `librecodecoop/impostos v0.2.1` do `composer.lock`. Não houve commit, PR, tag, release nem alteração de dados fiscais de produção.

## Problemas comprovados no pacote instalado

| Componente | Situação encontrada em v0.2.1 | Correção local preparada |
| --- | --- | --- |
| INSS 2023 | Teto-base informado R$ 7.087,22 (valor de 2022) | R$ 7.507,49 |
| INSS 2024 | Teto-base informado R$ 7.087,22 | R$ 7.786,02 |
| IRPF 2025 | Tabela de janeiro aplicada a todo o ano | Segunda faixa temporal desde maio de 2025 (R$ 2.428,80 de isenção mensal na tabela progressiva) |
| IRPF desde maio de 2023 | Escolha entre desconto simplificado e deduções tradicionais não comparava os valores de fato, sobretudo com dependentes | Comparação entre ambas as deduções efetivas, escolhendo o maior abatimento |
| IRPF 2026 | Tabela/redução incluídas na dependência, mas cálculos de dependentes sujeitos à falha acima | Mantidos valores de 2026, corrigida a comparação de deduções |

**Fontes públicas para validação contábil antes de uso real:**
- Receita Federal — tabelas progressivas: https://www.gov.br/receitafederal/pt-br/assuntos/meu-imposto-de-renda/tabelas
- Previdência/INSS — tetos do salário de contribuição de 2023/2024 (consultar portarias anuais e corrigir o teto de acordo com a competência): https://www.gov.br/previdencia/pt-br
- Legislação e regra de desconto simplificado da Receita Federal: validar com o contador a forma de aplicação **para produção cooperativista** e a identificação do fato gerador.

## Testes e arquivos

O checkout isolado foi criado em `.audit-dependencies/impostos` e ignorado no Git principal. O pacote passou em **67 testes e 118 asserções**. A correção cobre anos históricos e a escolha de dedução com dependentes. Não foram alterados arquivos em `vendor/`.

As correções estão em revisão no PR [LibreCodeCoop/impostos#10](https://github.com/LibreCodeCoop/impostos/pull/10), com CI aprovada. A aplicação principal ainda não consome o novo código.

## Etapas obrigatórias antes da ativação

1. Revisar a regra previdenciária de cooperado pessoa física, inclusive a alíquota e seu fato gerador. **A implementação ainda usa alíquota de 20% como padrão**; esta auditoria não homologou que seja correta.
2. Validar com contador os anos de abrangência, aplicação do desconto simplificado mensal, dedução por dependente, retenções, eventuais compensações e efeitos retrospectivos. Não alterar regras aprovadas de FRRA ou ajuda de custo por dedução.
3. Preparar code review, release e atualização explícita da dependência no `composer.lock` **somente após autorização**.
4. Rodar testes de ambos os projetos, calcular diferenças por competência e por cooperado sem emitir ou retificar documentos automaticamente.
5. Deliberar sobre correções fiscais históricas e documentação de aprovação. Não reinterpretar distribuição de sobras como receita, nem usar saldo bancário como capital elegível.

## Governança e incertezas

Localizamos as RFCs históricas de fluxo de pagamento, formação de valor-hora e folha de pagamento no Nextcloud, mas não foi possível recuperar e verificar integralmente os textos nem comprovar sua aprovação vigente. No Akaunting (acesso de leitura), verificamos categorias e documentos de setembro/outubro de 2026; a listagem de transações por período não foi disponibilizada pela API autorizada. **Não houve reconciliação contábil real de competência.**

A regra econômica de base móvel, despesas administrativas, FRRA de 1/12, ajuda de custo de 20%, distribuição de sobras e percentuais de remuneração não foi modificada unilateralmente. Os testes validam comportamento técnico, não substituem aprovação estatutária ou legal.
