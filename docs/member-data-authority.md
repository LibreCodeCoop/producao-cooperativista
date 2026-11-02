# Autoridade de dados e vínculo de identidade entre cooperados

## Uma fonte por campo

- Nextcloud `profile_fields`: CPF/CNPJ de referência, peso e número
  atual de dependentes. Zero dependentes deve ser explícito; ausência
  ou valor inválido bloqueiam a importação dos cooperados participantes
  da competência, sem presumir zero para os demais usuários do Kimai.
- Kimai: identificador local, apontamentos e projetos. A preferência fiscal
  legada `tax_number` ainda pode existir no cadastro histórico, mas
  **não é chave de vínculo na importação mensal**. Se presente, pode ser
  comparada com o CPF/CNPJ do Nextcloud apenas para detectar divergências
  na interface de auditoria. A importação mensal ignora essa preferência
  legada: lê o CPF/CNPJ do perfil explicitamente vinculado no Nextcloud.
  Dependentes não são importados do Kimai.
- Akaunting: contato e documentos financeiros. O CPF/CNPJ do contato
  é cópia de conciliação, não fonte para preencher dependentes ou
  substituir automaticamente o CPF informado no Nextcloud.
- ETL: fotografias por competência, não um cadastro mestre independente.

O painel de configuração permite editar CPF/CNPJ de referência, peso e
dependentes exclusivamente no Nextcloud Profile Fields, com escrita restrita
aos administradores configurados da aplicação. Os outros sistemas não oferecem
três formulários de edição para o mesmo identificador. A importação mensal
exige vínculos explícitos `kimai_user_id` e `akaunting_contact_id`,
administrados no Nextcloud, e dependentes informados explicitamente para
todos os participantes. O CPF/CNPJ continua necessário nos documentos legais
do Akaunting, mas não é utilizado para escolher automaticamente o contato.
Divergências fiscais bloqueiam a importação; não são corrigidas por cópia
automática entre fontes.

A lista de participantes é determinada pelos apontamentos **atuais da competência**
consultados diretamente no Kimai, nunca por cadastros ativos ou por um snapshot
antigo do ETL. A mesma fotografia completa de apontamentos é reutilizada na
importação das horas para impedir divergências entre as duas etapas. Usuários
sem apontamentos no mês não são reimportados nem recebem dependentes fictícios;
se passarem a participar de uma competência futura, a validação voltará a ser
obrigatória. As linhas históricas do ETL não são apagadas por essa seleção.

## Identificadores e credenciais

**Recomendação para próximo incremento:** um identificador estável e
opaco de cooperado, gerado e administrado em diretório de identidade,
com vínculo explícito `nextcloud_uid`, `kimai_user_id` e
`akaunting_contact_id`. CPF/CNPJ deve ser atributo legal de
conciliação e descoberta inicial, não chave de autenticação nem vínculo
que se atualiza automaticamente mediante semelhança de nomes ou e-mail.

Não mover senhas, tokens, credenciais de serviço ou segredos para o
`profile_fields`. Autenticação e provisionamento devem permanecer
sob IdP/SSO ou no sistema de identidade existente; perfil de cooperado
é dado cadastral, não cofre de senhas.
