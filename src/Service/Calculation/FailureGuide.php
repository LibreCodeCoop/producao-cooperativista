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
        $message = mb_strtolower($rawMessage);
        return match (true) {
            str_contains($message, 'publication_write_attempts') => new self(
                'REGISTRO_PUBLICACAO_AUSENTE',
                'A estrutura de auditoria de publicações não foi instalada no banco do ETL.',
                'Antes de publicar, execute a migration pendente no banco intermediário aprovado; não altere o banco do Akaunting. Consulte o procedimento de implantação.'
            ),
            str_contains($message, 'gravação pendente'),
            str_contains($message, 'resultado incerto'),
            str_contains($message, 'tentativa ficou bloqueada') => new self(
                'PUBLICACAO_INCERTA',
                'Existe uma operação financeira cujo resultado ainda não foi conciliado.',
                'Consulte publication:pending em modo somente leitura, verifique os documentos no Akaunting e não repita a gravação antes de conciliar o histórico.'
            ),
            str_contains($message, 'contacts::$'),
            str_contains($message, 'contacts::$id'),
            str_contains($message, 'cannot assign string to property') => new self(
                'CONTACT_MAPPING',
                'Dados ou tipos do contato incompatíveis com o mapeamento do Akaunting.',
                'Confira a versão do Akaunting, os tipos e colunas da entidade Contacts e a migração local. Não altere o banco financeiro manualmente: corrija o mapeamento e repita os testes.'
            ),
            str_contains($message, 'código de cliente inválido'),
            str_contains($message, 'customer_reference') => new self(
                'CLIENTE_SEM_CODIGO',
                'Movimentação com código de cliente ausente ou inválido.',
                'No Akaunting, abra o lançamento indicado no log, ajuste o CNPJ/CPF e o setor no campo de referência do cliente, importe novamente e confira o relatório de movimentações.'
            ),
            str_contains($message, 'must contain valid json'),
            str_contains($message, 'itemsids'),
            str_contains($message, 'json syntax') => new self(
                'CONFIG_JSON',
                'Configuração de itens ou tributos com JSON inválido.',
                'Revise as variáveis AKAUNTING_PRODUCAO_COOPERATIVISTA_ITEM_IDS e AKAUNTING_IMPOSTOS_*, confirme as chaves e IDs e valide o JSON sem expor credenciais.'
            ),
            str_contains($message, 'could not resolve host'),
            str_contains($message, 'getaddrinfo'),
            str_contains($message, 'connection refused'),
            str_contains($message, 'falha de conexão'),
            str_contains($message, 'transportexception') => new self(
                'SERVICO_INACESSIVEL',
                'Um serviço externo ou banco de dados está inacessível.',
                'Verifique se os contêineres e redes estão ativos, a resolução de DNS, a URL configurada e a conexão a partir do contêiner da aplicação. Não reexecute a geração sem conferir eventuais documentos gravados.'
            ),
            str_contains($message, '429'),
            str_contains($message, 'too many attempts'),
            str_contains($message, 'limite de requisições') => new self(
                'API_LIMITADA',
                'A API recusou novas requisições temporariamente.',
                'Verifique os limites da API, aguarde a janela indicada pelo serviço e reduza a frequência da importação.'
            ),
            str_contains($message, '401'),
            str_contains($message, '403'),
            str_contains($message, 'autenticação'),
            str_contains($message, 'credencial') => new self(
                'API_ACESSO',
                'Credenciais ou permissões de integração incorretas.',
                'Confira a URL, a credencial e as permissões da conta de serviço no sistema externo. Não cole tokens ou senhas nos logs.'
            ),
            str_contains($message, 'campo data ausente'),
            str_contains($message, 'undefined array key "data"'),
            str_contains($message, 'resposta inesperada') => new self(
                'API_RESPOSTA',
                'A API retornou uma estrutura diferente da esperada.',
                'Confirme o endpoint, a versão da API e a autenticação. Inspecione o status HTTP e o esquema da resposta, sem registrar conteúdo sensível.'
            ),
            str_contains($message, 'sem movimentações'),
            str_contains($message, 'sem transações') => new self(
                'SEM_MOVIMENTOS',
                'Não foram encontradas movimentações na competência selecionada.',
                'Confirme ano/mês, execute a importação em modo controlado e confira categorias e competência de faturas e transações no Akaunting.'
            ),
            str_contains($message, 'não há horas ponderadas'),
            str_contains($message, 'peso inválido') => new self(
                'PESOS_HORAS',
                'Apontamentos ou pesos dos cooperados não permitem distribuir a produção.',
                'Revise horas e projetos no Kimai e os pesos e CPFs no Profile Fields. Confira a competência e as regras de trabalho interno.'
            ),
            str_contains($message, 'base administrativa indisponível'),
            str_contains($message, 'capital elegível'),
            str_contains($message, 'sobra negativa') => new self(
                'BASE_ECONOMICA',
                'A base econômica calculada não permite a distribuição.',
                'Confira receitas elegíveis, custos por cliente, despesas internas e percentuais, sem substituir a base por saldo bancário. Encaminhe valores negativos para conciliação.'
            ),
            str_contains($message, 'already exists'),
            str_contains($message, 'tabela já existe') => new self(
                'SCHEMA_DIVERGENTE',
                'O histórico das migrações não corresponde ao esquema do banco.',
                'Faça backup e compare a tabela existente com o histórico de migrações. Não apague tabelas nem execute migrations às cegas.'
            ),
            str_contains($message, 'headers already sent') => new self(
                'SSE_CABECALHOS',
                'O servidor iniciou a resposta HTTP antes de preparar o streaming.',
                'Verifique a versão da aplicação, a implementação de StreamedResponse e eventual saída PHP antes dos cabeçalhos.'
            ),
            str_contains($message, 'nfse'),
            str_contains($message, 'prefeitura') => new self(
                'NFSE_IMPORTACAO',
                'Falha ou ausência de dados na integração de notas fiscais municipais.',
                'Confira a prefeitura configurada, credenciais e disponibilidade do portal. Verifique se a competência exige novas NFS-e antes da apuração.'
            ),
            str_contains($message, 'não aceitou a operação'),
            str_contains($message, 'http 422') => new self(
                'DOCUMENTO_INVALIDO',
                'O Akaunting recusou algum campo do documento.',
                'Revise a categoria, os contatos, os itens configurados e o número do documento no Akaunting. Confira se o documento já existe antes de repetir a gravação.'
            ),
            default => new self(
                'FALHA_INESPERADA',
                'A execução falhou e requer investigação.',
                'Procure o ID da execução no log do servidor, identifique a etapa e a exceção e confira os documentos do período antes de reexecutar.'
            ),
        };
    }
}
