<?php

/**
 * @copyright Copyright (c) 2026, LibreCode contributors
 * @license GNU AGPL version 3 or any later version
 */

declare(strict_types=1);

namespace App\Service\Calculation;

/**
 * Public, non-sensitive diagnosis. Full exception details belong only in the
 * server log, never in streamed browser events or generated HTML.
 */
final readonly class FailureGuide
{
    public function __construct(
        public string $code,
        public string $summary,
        public string $instructions,
    ) {
    }

    public static function diagnose(string $rawMessage): self
    {
        return self::guideFor(self::identify(mb_strtolower($rawMessage)));
    }

    /**
     * Only error recognition belongs here. User-facing explanations are
     * defined once in guideFor(), so tests assert the public contract rather
     * than maintaining a second copy of the text.
     */
    private static function identify(string $message): string
    {
        return match (true) {
            str_contains($message, 'source_connection_failed: nextcloud profile fields') => 'NEXTCLOUD_PROFILE_TIMEOUT',
            str_contains($message, 'source_connection_failed') => 'IMPORT_CONNECTION_TIMEOUT',
            str_contains($message, 'source_api_access') => 'AKAUNTING_API_ACCESS_ERROR',
            str_contains($message, 'profile_kimai_link') => 'PROFILE_KIMAI_LINK',
            str_contains($message, 'profile_accounting_link') => 'PROFILE_ACCOUNTING_LINK',
            str_contains($message, 'profile_accounting_invalid') => 'PROFILE_ACCOUNTING_INVALID',
            str_contains($message, 'profile_tax_mismatch') => 'PROFILE_TAX_MISMATCH',
            str_contains($message, 'profile_tax_missing') => 'PROFILE_TAX_MISSING',
            str_contains($message, 'profile_source') => 'PROFILE_SOURCE',
            str_contains($message, 'profile_dependents') => 'PROFILE_DEPENDENTS',
            str_contains($message, 'publication_write_attempts') => 'REGISTRO_PUBLICACAO_AUSENTE',
            str_contains($message, 'gravação pendente'),
            str_contains($message, 'resultado incerto'),
            str_contains($message, 'tentativa ficou bloqueada') => 'PUBLICACAO_INCERTA',
            str_contains($message, 'contacts::$'),
            str_contains($message, 'contacts::$id'),
            str_contains($message, 'cannot assign string to property') => 'CONTACT_MAPPING',
            str_contains($message, 'código de cliente inválido'),
            str_contains($message, 'customer_reference') => 'CLIENTE_SEM_CODIGO',
            str_contains($message, 'must contain valid json'),
            str_contains($message, 'itemsids'),
            str_contains($message, 'json syntax') => 'CONFIG_JSON',
            str_contains($message, 'could not resolve host'),
            str_contains($message, 'getaddrinfo'),
            str_contains($message, 'connection refused'),
            str_contains($message, 'falha de conexão'),
            str_contains($message, 'transportexception') => 'GENERIC_CONNECTION_FAILURE',
            preg_match('/\b(?:http|status|erro)\s*429\b/u', $message) === 1,
            str_contains($message, 'too many attempts'),
            str_contains($message, 'limite de requisições') => 'API_LIMITADA',
            preg_match('/\b(?:http|status|erro)\s*(?:401|403)\b/u', $message) === 1,
            str_contains($message, 'autenticação'),
            str_contains($message, 'credencial') => 'INTEGRATION_PERMISSION_ERROR',
            str_contains($message, 'campo data ausente'),
            str_contains($message, 'undefined array key "data"'),
            str_contains($message, 'resposta inesperada') => 'API_RESPOSTA',
            str_contains($message, 'sem movimentações'),
            str_contains($message, 'sem transações') => 'SEM_MOVIMENTOS',
            str_contains($message, 'não há horas ponderadas'),
            str_contains($message, 'peso inválido') => 'PESOS_HORAS',
            str_contains($message, 'base administrativa indisponível'),
            str_contains($message, 'capital elegível'),
            str_contains($message, 'sobra negativa') => 'BASE_ECONOMICA',
            str_contains($message, 'already exists'),
            str_contains($message, 'tabela já existe') => 'SCHEMA_DIVERGENTE',
            str_contains($message, 'headers already sent') => 'SSE_CABECALHOS',
            str_contains($message, 'não aceitou a operação'),
            str_contains($message, 'http 422') => 'DOCUMENTO_INVALIDO',
            default => 'FALHA_INESPERADA',
        };
    }

    private static function guideFor(string $signal): self
    {
        return match ($signal) {
            'NEXTCLOUD_PROFILE_TIMEOUT' => new self(
                'SERVICO_INACESSIVEL',
                'A API Nextcloud Profile Fields não respondeu à consulta de vínculo Kimai–Nextcloud.',
                'Há uma falha intermitente de rede ou resposta da API de perfis do Nextcloud. Confira a latência do Nextcloud na competência indicada e tente importar novamente, sem presumir vínculos ou alterar dados fiscais. Não repita operações de publicação financeira antes de conciliar o resultado anterior.'
            ),
            'IMPORT_CONNECTION_TIMEOUT' => new self(
                'SERVICO_INACESSIVEL',
                'A conexão com uma integração expirou ou foi interrompida durante a importação.',
                'Verifique a disponibilidade e a latência da integração informada na etapa. Nenhum dado fiscal deve ser corrigido por causa de uma falha de rede.'
            ),
            'AKAUNTING_API_ACCESS_ERROR' => new self(
                'API_ACESSO',
                'O Akaunting recusou o acesso a uma consulta da integração.',
                'Confira a empresa selecionada, os filtros mantidos na paginação e as permissões da conta de integração no Akaunting. Não altere credenciais nem documentos financeiros sem identificar a requisição recusada nos logs.'
            ),
            'PROFILE_KIMAI_LINK' => new self(
                'PROFILE_KIMAI_LINK',
                'Cooperado com horas registradas no Kimai, mas sem vínculo explícito com uma conta Nextcloud.',
                'Confira se a pessoa identificada no Kimai é a mesma do Nextcloud. No cadastro do cooperado, informe o ID Kimai e associe o contato financeiro correto do Akaunting. Não vincule pessoas somente pelo nome; depois reimporte a competência.'
            ),
            'PROFILE_ACCOUNTING_LINK' => new self(
                'PROFILE_ACCOUNTING_LINK',
                'Cooperado associado ao Nextcloud, mas sem contato financeiro vinculado no Akaunting.',
                'Localize ou cadastre o contato correto no Akaunting, confirme a identidade fiscal e selecione-o em Configuração → Cooperados e vínculos. Reimporte somente após conferir o vínculo.'
            ),
            'PROFILE_ACCOUNTING_INVALID' => new self(
                'PROFILE_ACCOUNTING_INVALID',
                'Contato financeiro vinculado não foi encontrado ou não é elegível para esta empresa.',
                'Confira se o contato existe, pertence à empresa correta e está ativo no Akaunting; ajuste o vínculo pelo painel e reimporte.'
            ),
            'PROFILE_TAX_MISMATCH' => new self(
                'PROFILE_TAX_MISMATCH',
                'Identificação fiscal divergente entre o perfil Nextcloud e o contato financeiro.',
                'Confira os dois cadastros na origem, sem alterar CPF/CNPJ para apenas fazer a importação passar. Após confirmar a identidade, corrija o dado incorreto e reimporte.'
            ),
            'PROFILE_TAX_MISSING' => new self(
                'PROFILE_TAX_MISSING',
                'Cooperado com horas registradas, mas sem identificação fiscal válida no Nextcloud.',
                'Abra o cadastro do cooperado, confira e preencha a identificação fiscal no Nextcloud Profile Fields, confirme o contato no Akaunting e reimporte.'
            ),
            'PROFILE_SOURCE' => new self(
                'PROFILE_SOURCE',
                'Há um cadastro ou vínculo inconsistente entre Kimai, Nextcloud e Akaunting.',
                'Abra o cadastro do cooperado indicado, confira a identidade entre Kimai, Nextcloud e Akaunting, corrija apenas o dado comprovadamente incorreto e reimporte. Não associe contas automaticamente pelo nome.'
            ),
            'PROFILE_DEPENDENTS' => new self(
                'PROFILE_DEPENDENTS',
                'Cadastro de cooperado sem quantidade válida de dependentes no Nextcloud.',
                'Em Configuração → Cooperados e vínculos, abra o usuário Kimai indicado, confira a conta Nextcloud e informe o número inteiro de dependentes (inclusive zero quando confirmado). Salve na origem e reimporte. Não presuma zero sem conferência.'
            ),
            'REGISTRO_PUBLICACAO_AUSENTE' => new self(
                'REGISTRO_PUBLICACAO_AUSENTE',
                'A estrutura de auditoria de publicações não foi instalada no banco do ETL.',
                'Antes de publicar, execute a migration pendente no banco intermediário aprovado; não altere o banco do Akaunting. Consulte o procedimento de implantação.'
            ),
            'PUBLICACAO_INCERTA' => new self(
                'PUBLICACAO_INCERTA',
                'Existe uma operação financeira cujo resultado ainda não foi conciliado.',
                'Consulte publication:pending em modo somente leitura, verifique os documentos no Akaunting e não repita a gravação antes de conciliar o histórico.'
            ),
            'CONTACT_MAPPING' => new self(
                'CONTACT_MAPPING',
                'Dados ou tipos do contato incompatíveis com o mapeamento do Akaunting.',
                'Confira a versão do Akaunting, os tipos e colunas da entidade Contacts e a migração local. Não altere o banco financeiro manualmente: corrija o mapeamento e repita os testes.'
            ),
            'CLIENTE_SEM_CODIGO' => new self(
                'CLIENTE_SEM_CODIGO',
                'Movimentação com código de cliente ausente ou inválido.',
                'Localize o lançamento indicado no Akaunting e confirme a natureza da receita. Se for serviço prestado a um cliente, corrija a referência fiscal verdadeira. Se for patrocínio ou outra receita sem cliente identificável, peça à contabilidade que revise sua classificação antes de recalcular. Não invente CPF/CNPJ nem reclassifique automaticamente.'
            ),
            'CONFIG_JSON' => new self(
                'CONFIG_JSON',
                'Configuração de itens ou tributos com JSON inválido.',
                'Revise as variáveis AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS e AKAUNTING_IMPOSTOS_*, confirme as chaves e IDs e valide o JSON sem expor credenciais.'
            ),
            'GENERIC_CONNECTION_FAILURE' => new self(
                'SERVICO_INACESSIVEL',
                'Um serviço externo ou banco de dados está inacessível.',
                'Verifique se os contêineres e redes estão ativos, a resolução de DNS, a URL configurada e a conexão a partir do contêiner da aplicação. Não reexecute a geração sem conferir eventuais documentos gravados.'
            ),
            'API_LIMITADA' => new self(
                'API_LIMITADA',
                'A API recusou novas requisições temporariamente.',
                'Verifique os limites da API, aguarde a janela indicada pelo serviço e reduza a frequência da importação.'
            ),
            'INTEGRATION_PERMISSION_ERROR' => new self(
                'API_ACESSO',
                'Credenciais ou permissões de integração incorretas.',
                'Confira a URL, a credencial e as permissões da conta de serviço no sistema externo. Não cole tokens ou senhas nos logs.'
            ),
            'API_RESPOSTA' => new self(
                'API_RESPOSTA',
                'A API retornou uma estrutura diferente da esperada.',
                'Confirme o endpoint, a versão da API e a autenticação. Inspecione o status HTTP e o esquema da resposta, sem registrar conteúdo sensível.'
            ),
            'SEM_MOVIMENTOS' => new self(
                'SEM_MOVIMENTOS',
                'Não foram encontradas movimentações na competência selecionada.',
                'Confirme ano/mês, execute a importação em modo controlado e confira categorias e competência de faturas e transações no Akaunting.'
            ),
            'PESOS_HORAS' => new self(
                'PESOS_HORAS',
                'Apontamentos ou pesos dos cooperados não permitem distribuir a produção.',
                'Revise horas e projetos no Kimai e os pesos e CPFs no Profile Fields. Confira a competência e as regras de trabalho interno.'
            ),
            'BASE_ECONOMICA' => new self(
                'BASE_ECONOMICA',
                'A base econômica calculada não permite a distribuição.',
                'Confira receitas elegíveis, custos por cliente, despesas internas e percentuais, sem substituir a base por saldo bancário. Encaminhe valores negativos para conciliação.'
            ),
            'SCHEMA_DIVERGENTE' => new self(
                'SCHEMA_DIVERGENTE',
                'O histórico das migrações não corresponde ao esquema do banco.',
                'Faça backup e compare a tabela existente com o histórico de migrações. Não apague tabelas nem execute migrations às cegas.'
            ),
            'SSE_CABECALHOS' => new self(
                'SSE_CABECALHOS',
                'O servidor iniciou a resposta HTTP antes de preparar o streaming.',
                'Verifique a versão da aplicação, a implementação de StreamedResponse e eventual saída PHP antes dos cabeçalhos.'
            ),
            'DOCUMENTO_INVALIDO' => new self(
                'DOCUMENTO_INVALIDO',
                'O Akaunting recusou algum campo do documento.',
                'Revise a categoria, os contatos, os itens configurados e o número do documento no Akaunting. Confira se o documento já existe antes de repetir a gravação.'
            ),
            'FALHA_INESPERADA' => new self(
                'FALHA_INESPERADA',
                'A execução falhou e requer investigação.',
                'Procure o ID da execução no log do servidor, identifique a etapa e a exceção e confira os documentos do período antes de reexecutar.'
            ),
        };
    }
}
