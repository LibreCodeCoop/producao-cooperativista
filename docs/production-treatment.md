# Tratamento de receitas na produção cooperativista

## Competências distintas

A competência da produção (mês de trabalho no Kimai) utiliza a competência financeira do mês seguinte. O campo **Competência financeira (YYYY-MM)** no Akaunting indica em qual fechamento financeiro a receita ou o dispêndio participa. Não altera a data bancária, a data de emissão da NFS-e, seu período de prestação de serviços nem o vencimento da fatura.

O campo **Tratamento na produção cooperativista** é um seletor único, aplicável às faturas, transações e contas a pagar, com três opções:

- **Automático (quando recebido):** padrão se o campo ficar vazio. Receita de cliente só entra quando existe recebimento; entradas bancárias avulsas elegíveis entram conforme a competência financeira. Não é necessário criar uma fatura para recebimentos avulsos. Capital social não entra no rateio.
- **Incluir mesmo sem pagamento:** exclusivo das faturas de clientes; seleciona explicitamente uma fatura aberta para a base de produção da competência financeira informada, sem inventar recebimento. Em caso de pagamento parcial, considera apenas o saldo ainda não apropriado, descontando recibos já considerados. No caso de transações bancárias e contas a pagar, não aplicar: a seleção não antecipa nem altera pagamentos.
- **Ignorar na produção:** exclui o lançamento do rateio, sem cancelar nem excluir documento ou transação do Akaunting; útil para capital social, adiantamentos ou outros valores que não devam afetar a produção. A categoria de capital social já é excluída por padrão, independentemente da seleção.

O campo antigo **Ignorar na produção cooperativista** (`archive`) continua sendo lido para compatibilidade, até que todos os valores existentes sejam migrados para a opção **Ignorar na produção**. A nova seleção prevalece quando preenchida. Não altere registros antigos de exclusão sem conferência.

## Receita geral sem cliente de contrato

Entradas bancárias do tipo receita sem CNPJ ou referência de um contrato, como GitHub Sponsors via Stripe, fazem parte da **receita geral**, desde que não sejam capital social nem estejam ignoradas. São distribuídas entre os cooperados segundo as horas trabalhadas ponderadas da competência, sem exigir CNPJ fictício ou emissão de fatura artificial.

Entradas de clientes contratuais continuam usando a associação com o cliente Kimai para seus custos e distribuição. Os tributos calculados especificamente sobre receitas de clientes mantêm sua própria base; a classificação fiscal dos apoios e patrocínios é tratada pela contabilidade fora deste seletor.

## Controle de apropriação e não duplicação

A tabela independente `production_income_appropriations` registra, por empresa, tipo e ID original de receita, competência da produção e valor em centavos, qual receita já originou documentos individuais. Ela **não** integra as tabelas temporárias removidas na reimportação.

Quando uma fatura antecipada é quitada em competência posterior, o sistema encontra a apropriação anterior e não a distribui novamente. Recebimentos parciais posteriores só podem participar da próxima apuração pelo saldo ainda não apropriado. A prévia e a publicação utilizam as mesmas regras e a revisão protege contra alterações entre conferência e gravação.

Antes de cada tentativa de publicação são criadas reservas `reserved`. Depois que todos os documentos são gravados com êxito, elas passam a `confirmed`. Se a operação externa falhar, **não faça um segundo envio automaticamente**: a reserva fica pendente para conciliação com os documentos já existentes no Akaunting. Não apague reservas ou reinicie o banco para contornar esta proteção.

Para evitar apropriação integral de receita com publicação individual parcial, a seleção de cooperados é bloqueada quando o período contém uma fatura antecipada ou receita geral. Revise a produção completa antes de publicar.

## Fluxo operacional

1. No Akaunting, confira os valores e a competência financeira das faturas e transações. A receita que não foi recebida permanece com o status financeiro verdadeiro.
2. Para faturas ainda não recebidas que já devem compor a produção, selecione **Incluir mesmo sem pagamento**. Para entradas avulsas, mantenha o tratamento **Automático**. Para capital social ou outras exclusões, use **Ignorar na produção** quando necessário.
3. Abra `Fechar a produção`, selecione o mês trabalhado, importe e confira horas e vínculos dos cooperados.
4. Clique em **Calcular valores para publicação** e confira a base, a taxa administrativa, custos, FRRA, impostos, adiantamentos e valores líquidos. A presença de faturas pendentes no relatório não significa que seus recebimentos foram inventados.
5. Ao confirmar a prévia, publique os documentos de produção e verifique no Akaunting os documentos criados. A publicação **não executa pagamentos bancários**.
6. Efetue os repasses somente depois da conferência do caixa e concilie os pagamentos. Na próxima competência, os recebimentos das faturas já apropriadas não gerarão novo rateio.

Se uma fatura antecipada for cancelada ou corrigida **após** sua apropriação, interrompa o fechamento seguinte e reconcilie a diferença; não altere o histórico manualmente. Valores de NFS-e e competências fiscais são independentes da competência financeira da produção.
