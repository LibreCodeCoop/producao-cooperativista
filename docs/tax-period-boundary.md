# Competência da produção e período de incidência do IRRF

## Situação identificada

A apuração da produção na LibreCode recebe ano e mês do trabalho em
ProducaoCooperativista::getCooperado, e Values::calculaImpostos utiliza
esses parâmetros para selecionar a tabela de IRPF. O vencimento do documento
financeiro é calculado separadamente por Dates::getDataPagamento, que
representa uma **previsão**, ajustada à data atual para períodos retroativos.

Essas datas não são intercambiáveis: segundo a IN RFB 1.500/2014, art. 65,
§1º, os rendimentos sujeitos à tabela progressiva mensal do imposto de renda
são considerados no mês em que são efetivamente recebidos. O eSocial também
distingue o período de remuneração da data do pagamento.

Fontes oficiais:
- https://normas.receita.fazenda.gov.br/sijut2consulta/link.action?idAto=57670&visao=anotado
- https://www.gov.br/esocial/pt-br/empresas/manual-web-geral

## Bloqueio provisório da publicação

Antes da primeira operação de escrita do publicador LibreCode, quando há
cooperado pessoa física, a aplicação compara o **ano trabalhado** com
o **ano previsto de pagamento**. Se forem diferentes, a publicação é
interrompida com erro explicativo. A prévia permanece disponível.

O bloqueio não altera nenhuma alíquota nem qualquer fórmula de INSS, IRPF,
FRRA ou distribuição de produção. Não transforma previsão em data efetiva
de pagamento e não reconcilia automaticamente documentos anteriores.

## Limites e aprovação necessária

Mesmo no mesmo ano podem existir tabelas mensais diferentes, pagamentos
parciais ou acumulados, outras fontes pagadoras e situações previdenciárias
específicas. Portanto, passar por esta verificação não equivale a homologação
tributária. A regra atual de cálculo pela competência do trabalho ainda
precisa ser substituída por uma decisão normativamente validada para IRRF,
com período e data de pagamento registrados de modo auditável.

Antes de desbloquear a emissão em uma virada de exercício, validar o
enquadramento e a referência temporal com a contabilidade, conferir os
eventos de pagamento efetivo e os casos de 2025/2026/2027, formalizar a
decisão normativa e adicionar testes determinísticos.
