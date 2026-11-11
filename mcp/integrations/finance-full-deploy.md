# Expandir a conexao Financeiro existente

Esta versao reaproveita registerFinanceSuite, a URL OAuth atual e o arquivo
/run/secrets/finance-admin-token. Acrescenta duas ferramentas de administracao
completa do app, mantendo as doze ferramentas antigas. Nao registra manutencao
do host, mesmo se FINANCE_MCP_HOST_ENABLED estiver definido.

Nao executar o staging do novo transporte /mcp-finance-full: ele pertence a uma
proposta anterior que foi retirada do escopo. Nao provisionar token, papel Keycloak,
UUID, chave SSH, endpoint ou conexao adicionais.

Primeiro aplicar a atualizacao da API JRC_ADM conforme docs/mcp-finance-full.md.
Ela habilita a ampliacao para o administrador do app usando o token admin atual.

No ACS, preservar server.js, compose, Dockerfile, segredos e autenticacao atuais.
Identificar a pasta real integrations da instancia Financeiro e fazer backup.
Atualizar somente:
- finance-suite.js
- finance-full-tools.js
- finance-full-client.js
- finance-host-client.js (dependencia importada; suas ferramentas ficam desabilitadas)

Comparar a finance-suite.js local antes de substituir, preservando customizacoes.
Verificar sintaxe dos modulos e reiniciar somente a instancia MCP correspondente
pelo procedimento ja usado no servidor. A forma exata depende de como a pasta
esta montada/buildada na instalacao ativa; nao substituir o compose do servidor
pelo do repositorio.

A conexao atual passa a listar financeiro_acesso_total_consultar e
financeiro_acesso_total_executar. Atualizar o catalogo no ChatGPT mantendo a URL
atual e testar capabilities. Confirmar que o handler compartilhado Opa nao
registra registerFinanceSuite (mesma restricao da integracao financeira atual).

Validacao local: node --test mcp/test/finance-full.test.js. O teste garante que
registerFinanceSuite publica 14 ferramentas e nenhuma de manutencao de servidor.
