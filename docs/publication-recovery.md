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

## Registro durável de tentativas

A migração `Version20261031020000` cria `publication_write_attempts`
**exclusivamente no banco intermediário do ETL**. Ela precisa ser aplicada
antes de publicar com esta versão, por procedimento autorizado.
Cada operação de `POST /api/documents` ou `PATCH /api/documents/{id}`
reserva uma tentativa no MySQL **antes** de transmitir a requisição.

- `pending`: requisição reservada, ainda sem resultado persistido. Após
  interrupção do processo, requer conferência.
- `confirmed`: resposta HTTP 200/201 contém ID, número, tipo e contato
  compatíveis com o documento solicitado.
- `rejected`: a API recusou com erro HTTP 4xx identificável (exceto 429);
  uma nova tentativa pode ser realizada após correção.
- `uncertain`: resposta perdida, 429, 5xx, corpo inesperado ou identidade
  divergente. **Proíbe novos envios automáticos da mesma operação**.

O histórico é preservado por tentativa, incluindo hash da identidade e do
corpo, método, recurso, empresa, resultado e horário. Não armazena o corpo
da requisição, nome, CPF/CNPJ ou número do documento em claro. Os hashes
são dados pseudonimizados: proteja o banco, pois hash não significa
anonimização. A tabela não integra a lista de limpeza do staging, nem deve
ser descartada ao reimportar uma competência. A migration não possui
rollback destrutivo.

Consulta operacional, somente leitura:

```bash
php bin/console publication:pending --limit=100
```

O comando imprime identificadores opacos das tentativas, empresa, verbo,
recurso e situação, sem conteúdo do documento. Combine com a prévia
autorizada e os documentos disponíveis no Akaunting para conciliar cada
caso antes de qualquer reexecução.

**Limite do mecanismo:** a proteção acompanha apenas as gravações de
documentos efetuadas pela instância da aplicação que utiliza o serviço
`Request` configurado por injeção de dependência. Não cobre operações
de contatos, chamadas avulsas sem registro, outras instalações sem o
mesmo banco, nem garante transações distribuídas ou atualização
condicional no Akaunting. Sem conciliação, tentativas incertas permanecem
bloqueadas; não existe liberação automática de evidência incerta.

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
retomada com garantias completas ainda requer reconciliação confiável de
gravações com resposta perdida, cobertura de todas as operações financeiras
e suporte servidor para idempotência/concorrência. O registro local de
tentativas implementado acima não fornece atomicidade distribuída.

Testes de escrita devem utilizar exclusivamente o Akaunting **local** ou
clientes HTTP simulados e bancos de teste isolados. Nunca exercite publicação
contra a instância produtiva para validar estes controles.
