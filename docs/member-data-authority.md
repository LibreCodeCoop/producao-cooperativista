# Autoridade de dados e vínculo de identidade entre cooperados

## Uma fonte por campo

- Nextcloud `profile_fields`: CPF/CNPJ de referência, peso e número
  atual de dependentes. Zero dependentes deve ser explícito; ausência
  ou valor inválido bloqueiam a importação LibreCode.
- Kimai: identificador local, apontamentos e projetos. A preferência
  `tax_number` permanece apenas para correspondência com Nextcloud.
  A preferência histórica `dependents` não é lida nem editada pelo ETL.
- Akaunting: contato e documentos financeiros. O CPF/CNPJ do contato
  é cópia de conciliação, não fonte para preencher dependentes ou
  substituir automaticamente o CPF informado no Nextcloud.
- ETL: fotografias por competência, não um cadastro mestre independente.

O painel de configuração só permite editar dependentes na fonte
Nextcloud. A importação não assume zero nem utiliza valores legados do
Kimai como fallback. Contas com referência fiscal divergente não podem
ser editadas pelo painel até que a correspondência seja esclarecida.

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

## Migração do Kimai

A atualização do plugin `LibreCodeCoop/CustomFieldsBundle` remove
o campo visível de dependentes. Os registros antigos do Kimai só poderão
ser limpos por comando explícito após conferência de cobertura no
Nextcloud e preservação do histórico necessário. Não executar a limpeza
no Kimai de produção como teste.
