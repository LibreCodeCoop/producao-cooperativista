# Publicação financeira: reconciliação e retomada

Este projeto é um ETL: reimportar dados ou recalcular uma competência não
desfaz uma conta, retenção ou pagamento já registrado no Akaunting.

## Controles implementados

- `make:producao` é prévia por padrão. Somente a opção explícita
  `--atualiza-producao` autoriza publicação no Akaunting configurado.
- Requisições financeiras `POST` e `PATCH` **não são repetidas automaticamente**
  após falhas de transporte. A operação pode ter sido gravada mesmo sem resposta.
- Quando a criação retorna conflito de número, a pesquisa precisa identificar
  **um único documento exato**. Antes de atualizar, compara empresa, tipo,
  contato e situação atual. Documentos pagos ou cancelados são bloqueados.
- Para um ID previamente importado, o ETL agora consulta novamente o documento
  remoto antes de efetuar `PATCH`; a identidade e a situação são revalidadas.
- Ao carregar um documento por ID, a identidade e a situação são conferidas
  **antes de incorporar** seus campos ao objeto local.
- Mensagens de erro desses bloqueios evitam expor o número do documento, que
  pode conter CPF ou CNPJ.

## Quando a publicação falhar

1. Não repita a publicação automaticamente. Preserve o ID da execução, a
   competência, o log privado e o manifesto dos dados utilizados.
2. Consulte o Akaunting **em leitura**, na empresa correta. Compare os
   números esperados na prévia CSV com os documentos efetivamente existentes,
   inclusive seus estados, contatos, itens e valores.
3. Diferencie os itens ainda não criados, rascunhos correspondentes, rascunhos
   divergentes e documentos pagos ou cancelados. **Não** substitua documentos
   finalizados; para divergências contábeis, investigue a causa e registre
   a correção por procedimento aprovado.
4. Somente depois da conciliação, reexecute a prévia da competência e compare
   os dados. A retomada da publicação exige autorização operacional explícita.

## Limites conhecidos

A consulta `GET` e o `PATCH` são operações separadas, sem condição de versão
(`If-Match` / ETag) garantida pelo servidor Akaunting. Uma edição concorrente
ainda pode ocorrer entre essas operações. O bloqueio local reduz o risco,
**não garante atualização transacional distribuída**.

A sequência de documentos por cooperado, FRRA e tributos também não é atômica:
uma falha intermediária pode deixar documentos parcialmente publicados. A
retomada com garantias completas ainda requer registro durável individual
das operações, reconciliação de gravações com resposta perdida e suporte
servidor para idempotência/concorrência. Nenhum ledger de publicação
financeira foi implementado nesta etapa.

Testes de escrita devem utilizar exclusivamente o Akaunting **local** ou
clientes HTTP simulados e bancos de teste isolados. Nunca exercite publicação
contra a instância produtiva para validar estes controles.
