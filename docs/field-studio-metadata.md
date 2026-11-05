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
| `customer_cnpj` | text | Cliente atribuído ao custo ou à receita (CNPJ com 14 dígitos), distinto do fornecedor em contas a pagar |
| `sector` | text | Setor, usado junto ao CNPJ para identificar projetos |
| `archive` | boolean | Ignorar na produção cooperativista: `1` exclui do rateio sem alterar contabilidade; `0` inclui |
| `nfse` | text | Referência numérica de NFS-e; não substitui a NFS-e oficial |

A ausência de `transaction_of_month` preserva a regra de negócio de usar
`due_at` para documentos e `paid_at` para transações. Isso **não é**
fallback para leitura de texto: é a regra nativa de competência financeira.

## Importação e fechamento

O cliente recebe o objeto `field_studio` nas respostas nativas de
`GET /api/documents?include_field_studio=1` e
`GET /api/transactions?include_field_studio=1`, sem requisições adicionais,
sem depender de marcador histórico de migração. A ausência de campos
opcionais é válida; os valores informados são validados por tipo.
O objeto `field_studio` e as definições contratadas continuam obrigatórios
na API. Documentos associados a transações devem ser normalizados, inclusive
quando pertencem a outro mês.

A migração histórica é uma operação pontual, não uma pré-condição das
importações novas. A limpeza dos textos requer conferência independente.

Não mantenha parser de texto, adaptador de compatibilidade nem campos
financeiros duplicados no Akaunting. Não altere fatos fiscais, NFS-e emitidas,
impostos, pagamentos ou faturas durante essa migração.
