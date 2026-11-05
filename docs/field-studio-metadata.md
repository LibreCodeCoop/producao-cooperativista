# Metadados financeiros normalizados no Field Studio

A Produção Cooperativista consome exclusivamente os campos estruturados
expostos pela API do módulo Field Studio no Akaunting. Observações das faturas
(`notes`) e descrições das transações (`description`) permanecem conteúdo
livre e **não são interpretadas** para calcular a produção.

## Campos por entidade

As entidades `invoice`, `bill` e `transaction` devem ter as mesmas
definições opcionais:

| Chave | Tipo | Significado |
| --- | --- | --- |
| `transaction_of_month` | text | Competência **financeira** `YYYY-MM`, distinta da competência fiscal da NFS-e |
| `discount_percentage` | percent | Desconto fixo, como string decimal de 0 a 100 |
| `customer_cnpj` | text | CNPJ do cliente com 14 dígitos e zeros preservados |
| `sector` | text | Setor, usado junto ao CNPJ para identificar projetos |
| `archive` | boolean | `1` exclui a movimentação da apuração; `0` a mantém |
| `nfse` | text | Referência numérica de NFS-e; não substitui a NFS-e oficial |
| `production_metadata_migrated` | boolean | `1` comprova conferência dos metadados do registro |

A ausência de `transaction_of_month` preserva a regra de negócio de usar
`due_at` para documentos e `paid_at` para transações. Isso **não é**
fallback para leitura de texto: é a regra nativa de competência financeira.

## Importação e fechamento

O cliente recebe o objeto `field_studio` nas respostas nativas de
`GET /api/documents?include_field_studio=1` e
`GET /api/transactions?include_field_studio=1`, sem requisições adicionais,
e exige o campo `production_metadata_migrated=1` explicitamente
gravado em **cada registro**. Um registro ainda não migrado impede o fechamento
em vez de gerar resultados incorretos. Documentos associados a transações
também precisam estar normalizados, inclusive quando pertencem a outro mês.

Antes de processar uma competência, execute o script de migração do Field Studio
para os documentos e transações que serão lidos. A migração é progressiva,
começando pela competência mais recente e avançando retroativamente. A limpeza
dos textos é uma operação separada, somente depois da conferência de valores
pela API e da comparação com o fechamento de referência.

Não mantenha parser de texto, adaptador de compatibilidade nem campos
financeiros duplicados no Akaunting. Não altere fatos fiscais, NFS-e emitidas,
impostos, pagamentos ou faturas durante essa migração.
