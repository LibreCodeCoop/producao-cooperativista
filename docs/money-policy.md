# Política técnica de precisão monetária

A integração usa BRL e separa **cálculo intermediário** de **valor pagável**.

- **BCMath** (extensão PHP, habilitada na imagem versionada) mantém operações em decimal quando os dados já chegam como strings. Como o ETL antigo ainda entrega parte dos dados em `float`, a conversão nos limites de entrada não recupera precisão que foi perdida anteriormente: uma futura refatoração do importador/SQL deverá conservar decimais desde a origem.
- **Centavos finais**: `DecimalAllocation::cents()` aplica `bcround(..., 2, RoundingMode::HalfAwayFromZero)` no limite de geração de uma parcela monetária. O arredondamento é explícito e testado; valores de tributos regidos por regra específica exigem tratamento próprio.
- **Alocação entre cooperados e documentos**: os pesos são duração trabalhada × peso do perfil; o rateio é proporcional aos pesos, com **maiores restos** para os centavos. Empates seguem o identificador em ordem lexical, sem depender da ordenação de resultados SQL. Soma das parcelas em centavos = total que entrou na alocação.
- **Custos por cliente**: ratear o custo entre as faturas do mesmo cliente, nunca transferi-lo silenciosamente para outro cliente. Rejeitar se custos excedem receitas elegíveis.
- **Taxa administrativa**: regra operacional documentada na planilha de 2022 (`Taxa Ideal=25%`): `max(dispêndios, min(base móvel × referência, 2×dispêndios))`. A referência de segurança **não é teto de percentual efetivo**. O valor monetário da taxa é fechado em centavos e o percentual efetivo é apenas um indicador derivado.
- **Retiradas/FRRA/auxílio**: mantém, sem reinterpretar, os percentuais existentes de 1/12 e 20%; valores monetários passam a ser arredondados explicitamente antes da composição do bruto e do líquido. `FRRA` não equivale ao Fundo de Reserva legal anual.
- **Tributos 2026**: `librecodecoop/impostos` inclui tabelas 2026 e redução de IRPF até R$ 7.350. Os testes de referência reproduzem os exemplos oficiais da Receita Federal de R$ 5.000 e R$ 6.000. A exatidão de retenções individuais ainda depende de outros rendimentos, teto previdenciário, documentos e classificação aplicáveis à pessoa.

## Limites de auditoria

1. O plano atual preserva políticas econômicas; nenhuma taxa foi redesenhada e nenhum saldo bancário entra como capital elegível.
2. Um período com distribuição efetiva pode apresentar diferenças de **até centavos** em relação a registros antigos que utilizavam resíduos de floats. Comparar prévia e escrituração antes de autorizar qualquer publicação histórica.
3. O repositório permanece com parcelas do ETL e consultas SQL que usam `float` / `DOUBLE`. As etapas de alocação e retirada agora fecham em centavos, mas não há alegação de **precisão decimal ponta a ponta** enquanto a origem não preservar o valor exato.
4. O portal `/configuracao` identifica as fontes e dá caminhos de correção, **sem escrever em Nextcloud, Kimai ou Akaunting**. Alterações remotas desses sistemas exigem autorização por sistema, verificação dos endpoints de escrita, controle de acesso e rastreabilidade.
5. Antes de emissão/pagamento em produção, validar o resultado por competência com responsável contábil, inclusive IRPF, INSS, FRRA, auxílio e direitos dos cooperados.

### Extração dos itens monetários em JSON

As consultas de preços de itens e tributos em JSON passam a extrair os valores como
DECIMAL(30,12) (em vez de DOUBLE), de modo que o somatório desses itens no MySQL
não acrescente arredondamento binário. Os campos legados de staging ainda usam
DOUBLE e os cálculos PHP ainda contêm float; esta alteração é incremental, não
equivale a precisão decimal ponta a ponta. Não reprocesse documentos pagos para
substituir diferenças históricas sem conciliação.

## Referências

- Planilha LibreCode, `Conselho Administrativo/Financeiro/Contabilidade/Produção/Arquivo/Proposta Fernanda/Produção (Release Candidate).ods`, 17/11/2022, aba `Parametros`, parâmetro `Taxa Ideal=25%`.
- [PHP bcround](https://www.php.net/manual/pt_BR/function.bcround.php).
- [Lei 15.270/2025 — redução mensal de IRPF em 2026](https://legis.senado.gov.br/norma/41758136/publicacao/41762089).
- [Receita Federal: exemplos da Lei 15.270/2025](https://www.gov.br/receitafederal/pt-br/assuntos/meu-imposto-de-renda/tabelas/exemplos-de-aplicacao-da-lei-15-270-2025).
- [INSS: tabela de contribuição 2026](https://www.gov.br/inss/pt-br/direitos-e-deveres/inscricao-e-contribuicao/tabela-de-contribuicao-mensal).
- [Lei 5.764/1971](https://www2.camara.leg.br/legin/fed/lei/1970-1979/lei-5764-16-dezembro-1971-357788-normaatualizada-pl.html).
