# Fonte de identidade dos cooperados

## Cadastro e vínculos

- **Nextcloud Profile Fields**: fonte cadastral de CPF/CNPJ, peso,
  dependentes e dos vínculos `kimai_user_id` e `akaunting_contact_id`.
  Os dois identificadores são editáveis somente por administradores e
  têm exposição `hidden`.
- **Kimai**: fornece seu ID nativo de usuário, nome, e-mail, projetos e
  apontamentos. Não mantém CPF/CNPJ ou dependentes duplicados para a
  Produção Cooperativista. O `CustomFieldsBundle` é desnecessário.
- **Akaunting**: mantém os dados fiscais próprios do contato financeiro
  e os documentos. O CPF/CNPJ é comparado ao valor de referência do
  Nextcloud, nunca utilizado para selecionar automaticamente um contato.
- **Banco do ETL**: conserva uma fotografia por competência para
  cálculo e auditoria. Não é um cadastro mestre de identidade.

A associação de um cooperado é determinada pelo ID nativo da origem
Kimai e pela correspondência *explícita* no Profile Fields, que retorna
a conta Nextcloud e o ID do contato financeiro. Não há pesquisa por CPF,
nome ou e-mail para vincular registros na importação mensal.

O CPF/CNPJ de referência permanece necessário para obrigações fiscais e
conciliação com o contato selecionado. Divergências são sinalizadas e
nunca corrigidas por propagação automática entre sistemas.

O número de dependentes de cada participante do mês deve constar
explicitamente no Profile Fields (inclusive zero). Usuários sem
apontamentos na competência não são presumidos como participantes. Os
apontamentos são consultados ao vivo no Kimai, e o mesmo snapshot é
utilizado nas etapas de usuários e de horas.

## Administração e privacidade

O editor exibe uma única área cadastral. Os IDs técnicos ficam em seção
administrativa recolhida. Consultas sem privilégio não recebem CPF/CNPJ,
IDs técnicos nem controles de gravação. Alterações cadastrais exigem
permissão administrativa e CSRF.

Não grave senhas, tokens ou credenciais de integração no Profile Fields.
O diretório de identidades não substitui o provedor de autenticação.
