# Autoridade de dados e vínculo de identidade entre cooperados

## Uma fonte por campo

- Nextcloud `profile_fields`: CPF/CNPJ de referência, peso e número
  atual de dependentes. Zero dependentes deve ser explícito; ausência
  ou valor inválido bloqueiam a importação LibreCode.
- Kimai: identificador local, apontamentos e projetos. A preferência
  `tax_number` é usada para confirmar a identidade no Nextcloud.
  Dependentes não são importados do Kimai.
- Akaunting: contato e documentos financeiros. O CPF/CNPJ do contato
  é cópia de conciliação, não fonte para preencher dependentes ou
  substituir automaticamente o CPF informado no Nextcloud.
- ETL: fotografias por competência, não um cadastro mestre independente.

O painel de configuração permite editar dependentes exclusivamente na fonte
Nextcloud. A importação exige que a quantidade esteja informada explicitamente
e bloqueia registros com referência fiscal divergente entre sistemas.

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
