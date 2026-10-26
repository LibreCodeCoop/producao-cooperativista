# Diagnóstico da produção cooperativista e correções operacionais

**Auditoria local iniciada em 25/10/2026.** Fontes: código do projeto e arquivo local `var/log/prod.log`. Nenhum dado bancário de produção foi modificado. A análise histórica não prova que as falhas estejam ativas hoje.

## Origem dos dados e capital elegível

O cálculo usa movimentos classificados do mês da competência no Akaunting, sem consultar saldo da conta bancária. `Movimentacao` reúne transações avulsas, faturas pagas sem transação vinculada e pagamentos de faturas vinculados, identifica entradas de clientes e dispêndios internos e por cliente e calcula o percentual administrativo variável ou fixo.

**Correção comprovada por teste:** pagamentos vinculados são agora agrupados por documento e competência e contabilizados pelo montante efetivamente associado às transações daquele mês. Duas parcelas no mesmo mês e uma no mês seguinte não repetem o valor integral da fatura. O bruto da fatura também é proporcionalizado para evitar duplicação de base tributária. Faturas avulsas de cliente sem confirmação de pagamento não integram receitas elegíveis; uma advertência indica os IDs e como conferir. Despesas continuam sujeitas ao critério por categorias da aplicação. A apuração continua independente do saldo bancário.

A base de produção por cliente equivale à receita elegível do cliente menos os dispêndios atribuíveis, distribuídos proporcionalmente entre suas faturas; o desconto administrativo é aplicado depois. A regra de percentual mínimo, máximo e de segurança foi preservada. Sobre o resultado, o sistema aplica os cálculos existentes de produção, repasse de sobras e tributação. O relatório deixou de converter sobras negativas em positivas por `abs()`.

Recalcular números históricos para comparar a nova forma de distribuição por fatura antes de publicar documentos definitivos, especialmente clientes que possuam ao mesmo tempo desconto fixo e variável. Os testes garantem a conservação do total de custos, mas não substituem conciliação contábil real.

## Evidências históricas dos logs

Arquivo analisado: `var/log/prod.log`, aproximadamente 163 MiB, com ocorrências de março de 2025 até julho de 2026 e alguns registros de testes locais posteriores. **Não há comprovação de que esse arquivo represente o processo de produção atual ou de que tenha cobertura de outubro de 2026.** Não foi copiado para a documentação nenhum CPF, nome completo, token ou valor de cliente.

| Ocorrências históricas | Quantidade aproximada identificada | Interpretação | Tratamento e orientação |
| --- | ---: | --- | --- |
| NFS-e sem credenciais | 41 avisos | Consulta municipal não executada | Aviso específico para `PREFEITURA`, `PREFEITURA_LOGIN` e `PREFEITURA_SENHA`; conferir fechamento fiscal |
| SSE, cabeçalhos enviados previamente | 20 erros principais mais variantes antigas | Saída HTTP anterior à construção da resposta | `StreamedResponse` passou a definir cabeçalhos; o emissor SSE escreve somente eventos; testes de formato |
| Profile Fields `/users/lookup` HTTP 404 | 78 respostas | Muitas consultas sem resultado, enquanto UID tem respostas 200; não prova indisponibilidade global | Após primeira ausência, priorizar consulta UID e validar CPF retornado, reduzindo requisições redundantes |
| Migrações com tabela `users` existente | Mais de 10 ocorrências antigas | Esquema e histórico de migração divergentes | Reset GET eliminado e bloqueado em produção; reconciliação manual de versões, jamais apagar tabela automaticamente |
| Ausência do campo `data` no Akaunting | 4 erros | Resposta API fora do contrato | Validação estruturada com instruções de autenticação, URL, versão e status HTTP |
| Tipos de contato e IDs não inicializados | Diversas falhas de 2025 | Mapeamento antigo incompatível | Entity `Contacts` atual já usa tipos adequados; nova resolução reutiliza contatos antes de criar |
| Erros de rede/DNS para Akaunting | Alguns erros de 2026 | Serviço/hostname inacessível | Erro `SERVICO_INACESSIVEL`, conferir DNS/contêineres, sem exibir credenciais |
| JSON inválido de itens | 1 erro identificado | Configuração `AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS` | Diagnóstico `CONFIG_JSON`; conferir sintaxe e IDs dos itens |
| Código de cliente inválido | 1 erro identificado no histórico local | CNPJ e setor em referências incompatíveis | Diagnóstico `CLIENTE_SEM_CODIGO` e ID do movimento no log |
| Ausência de movimentação | 2 erros históricos | Dados não baixados ou competência vazia | Mensagem indica competência e checagem de importação, pagamentos e categorias |

Os erros do histórico incluem versões antigas, tentativas de migração e testes; não inferir recorrência atual sem evidência.

## Como diagnosticar uma falha nova

1. **Anote o ID da execução** exibido na interface e a competência selecionada.
2. Confira o código de erro e a instrução exibida, sem repetir a gravação no mesmo momento.
3. No servidor, procure `run_id` nos arquivos rotativos de `var/log`. O stack trace detalhado é restrito ao servidor; o navegador não recebe credenciais, CPF ou resposta integral da API.
4. Se a etapa envolveu gravação, consulte os números `PDC_...`, FRRA e impostos já emitidos no Akaunting antes de decidir reexecutar. O código impede duplicação de contatos e atualizações de documentos finalizados, mas a operação externa **não é uma transação única com rollback**.
5. Para problemas `SCHEMA_DIVERGENTE`, faça backup, inspecione o histórico de migrações e o esquema; não apague dados.

## Segurança e ambiente

A interface de geração usa POST, sessão/CSRF e exige a variável secreta `PRODUCAO_ACTION_TOKEN` **em produção** para importar dados externos ou gravar documentos. Gere um segredo longo no servidor e carregue-o no ambiente do processo PHP. A prévia não cadastra contatos no Akaunting. O reset do banco é bloqueado em ambiente `prod`; em desenvolvimento exige POST, CSRF e digitar `ZERAR`.

Os logs rotacionam diariamente: eventos gerais retidos por até 30 arquivos diários, erros por até 90. Permissões de arquivos configuradas em `0640`. No ambiente local auditado, o diretório `var/log` foi restringido de `777` para `750`, e os arquivos históricos controlados pelo usuário foram restringidos para `640`; o conteúdo não foi apagado. Validar permissões equivalentes nos servidores de produção e garantir backups/logs centralizados.

O servidor web e os endpoints de consulta ainda devem estar protegidos por autenticação de usuário, proxy com ACL/VPN ou controle equivalente. O token administrativo de gravação, sozinho, não protege a leitura dos relatórios sensíveis.

## Validações e limites

Executar `composer run test:unit`, lint do contêiner Symfony, PHP-CS-Fixer e `node --test tests/js/production-progress.test.cjs`. A suíte foi executada apenas em MySQL de testes isolado em rede Docker interna. Nenhum teste integrou serviços financeiros de produção. As irregularidades históricas do Psalm (centenas de alertas, muitos ligados a padrões dinâmicos legados) requerem comparação de baseline separada; não devem ser descartadas automaticamente.

**Aceite para publicar:** conferência contábil de pelo menos uma competência real, com reconciliação entre pagamentos vinculados, notas pagas sem vínculo, despesas de clientes, taxas administrativas, sobras e faturas resultantes. Obter revisão da direção/contabilidade para o eventual rateio entre faturas com regimes administrativos distintos. Não efetuar merge ou geração de documentos reais com base apenas nos testes.

## Complemento da retomada em 25/10/2026

Esta seção atualiza o status técnico sem alterar regras financeiras aprovadas:

- **Competência e período:** `Dates` passou a preservar a data de entrada, limpar valores derivados entre competências e recalcular a previsão de pagamento do FRRA quando muda o dia útil. A consulta de transações deixou de comparar apenas o número do mês (erro de virada de ano).
- **Recibos parciais e retenções:** a apuração de entradas vinculadas já usa o valor das parcelas agrupadas por competência; agora os tributos da fatura são rateados para evitar dedução integral repetida em cada parcela. Se o bruto original estiver ausente, há falha diagnosticável em vez de pagamento calculado com base incompleta.
- **Importação Akaunting e Kimai:** faturas/transações são localizadas em lotes de IDs e os metadados dos documentos associados são carregados em lotes de até 400 IDs únicos. Buscas sem correspondência não disparam consultas repetidas.
- **NFS-e:** entidade passou a possuir os setters utilizados na importação, persistência passou a usar ORM e `BatchPersistence` (com flush) em vez de atualização DQL inválida, e o campo JSON é mantido como array. Números duplicados na mesma importação abortam antes de persistir.
- **Análise estática:** os alertas Psalm eram 285 no início desta retomada e foram reduzidos nos trabalhos locais; permanecem dezenas de problemas associados a propriedades/métodos dinâmicos e itens legados. Não foram mascarados por atualização indiscriminada de baseline.
- **Testes mais recentes:** 128 testes PHP e 313 asserções no principal; 3 testes JavaScript; lint Symfony e PHP-CS-Fixer aprovados. 67 testes e 118 asserções passaram na cópia corrigida da dependência de tributos. Todos foram realizados sem gravação financeira de produção.

A auditoria tributária independente está em `docs/AUDITORIA_IMPOSTOS_2026-10-25.md`, e o PR da dependência está em https://github.com/LibreCodeCoop/impostos/pull/10. O sistema principal **ainda usa a versão antiga dos impostos**, aguardando autorização para release, atualização do `composer.lock` e reconciliação histórica.

**Pontos ainda abertos para liberação financeira:** validação estatutária e contábil de ajuda de custo, FRRA, regras de sobras, deduções e INSS de cooperados; reconciliação real entre Akaunting e Kimai por competência; comprovação da fonte oficial e aprovação dos critérios de capitalização; idempotência transacional completa dos lançamentos externos, que não pode ser garantida apenas por bloqueios de execução e de documentos finalizados. Sem esses pontos não se recomenda executar a geração em produção.

**Conferência estática final:** Psalm passou de 285 para **273 diagnósticos**. Não restaram os erros detectados de referência a classes/métodos inexistentes, incompatibilidade de assinaturas e argumentos nos caminhos alterados. Os 273 restantes são avisos/diagnósticos estruturais, sobretudo de propriedades/métodos declarados mas possivelmente não utilizados, atributos de herança e código legado; não equivalem à homologação completa de qualidade estática. O arquivo de detalhes gerado pela auditoria fica em `var/psalm-end.json` (local e ignorado pelo Git).
