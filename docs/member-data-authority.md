# Autoridade de dados e vínculo de identidade entre cooperados

## Uma fonte por campo

- Nextcloud `profile_fields`: CPF/CNPJ de referência, peso e número
  atual de dependentes. Zero dependentes deve ser explícito; ausência
  ou valor inválido bloqueiam a importação dos cooperados participantes
  da competência, sem presumir zero para os demais usuários do Kimai.
- Kimai: identificador local, apontamentos e projetos. A preferência
  `tax_number` é usada para confirmar a identidade no Nextcloud.
  Dependentes não são importados do Kimai.
- Akaunting: contato e documentos financeiros. O CPF/CNPJ do contato
  é cópia de conciliação, não fonte para preencher dependentes ou
  substituir automaticamente o CPF informado no Nextcloud.
- ETL: fotografias por competência, não um cadastro mestre independente.

O painel de configuração permite editar dependentes exclusivamente na fonte
Nextcloud. A importação exige que a quantidade esteja informada explicitamente
para todos os usuários referenciados pelos apontamentos do mês e bloqueia
registros participantes com referência fiscal divergente entre sistemas.

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
