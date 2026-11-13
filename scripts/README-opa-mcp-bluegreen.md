# Finalizacao Opa Suite -> MCP JRConect (blue/green)

## Contexto confirmado no ACS
- MCP atual funciona na porta 127.0.0.1:3100.
- Imagem candidata homologada: jrconect-mcp:opa-candidate-20261113T195425Z.
- Consultas de fila AG com token real e usuario node UID 1000 passaram.
- Instancia antiga de homologacao funciona na porta 3101, mas sem todas as credenciais de outros sistemas.
- Nginx em /etc/nginx/sites-enabled/mcp.jrconect.com faz proxy de /mcp, /mcp-finance e /mcp-admin para 3100. /mcp-acs usa 3103.
- Keycloak real: usuario mcp-teste tem role opa-mcp-admin; OPA_API_ALLOWED_SUB e OPA_API_MCP_ENABLED da homologacao foram confirmadas.
- Teste de OAuth real para ChatGPT **ainda nao foi executado**. Consultar tools/list autenticado apos ativacao, e reverter imediatamente se falhar.

## 1. Prepare: sem interrupcao e sem alterar Nginx
Baixar o script pelo commit exato publicado apos revisao, validar com Python e executar:

    sudo python3 /tmp/opa-mcp-bluegreen.py prepare

Este modo cria APENAS a instancia adicional jrconect-mcp-opa-ready em 127.0.0.1:3102.
- Usa a imagem candidata ja aprovada e o ambiente do MCP em producao, com as quatro variaveis Opa do Compose proposto e os valores Keycloak homologados.
- Preserva os nomes e valores de TODAS as variaveis do MCP original.
- Monta as mesmas credenciais de producao somente leitura; adiciona o token Opa API somente leitura.
- Usa /data exclusivo em tmpfs, nunca compartilha a pasta gravavel original.
- Conecta as duas redes que o MCP original ja utiliza.
- Confere a paridade do ambiente, montagens e redes, /health de ambos, chaves publicas Keycloak e consulta Opa AG.
- Guarda um arquivo de estado restrito em /root/jrc-opa-bluegreen/state.json.
- Nao executa nenhuma ferramenta administrativa, nao altera Docker Compose, Nginx, servidor original, Keycloak ou credenciais.

**Atencao:** uma segunda instancia com credenciais read-only no filesystem ainda possui capacidade de chamar APIs com permissoes preexistentes. Nao fazer consultas de escrita durante o teste. /data nao contem a mesma base persistente do servidor original; qualquer ferramenta que dependa desse estado deve ser validada antes da troca.

## 2. Activate: altera SOMENTE rota /mcp do Nginx (apenas com autorizacao)
Apos prepare aprovado, planejar janela/observacao de conexoes e executar:

    sudo python3 /tmp/opa-mcp-bluegreen.py activate

- Recusa a operacao se a imagem do MCP original, a imagem da candidata, o arquivo Nginx ou o health mudaram desde prepare.
- Faz backup Nginx em /root/jrc-opa-bluegreen/nginx-mcp-pre-switch.conf.
- Troca exclusivamente o proxy_pass de location = /mcp de 3100 para 3102.
- Preserva /mcp-finance e /mcp-admin em 3100, /mcp-acs em 3103 e o endpoint de metadata OAuth.
- Valida nginx -t e faz nginx -s reload gracioso, sem parar o processo Nginx nem reiniciar o MCP.
- Em erro de configuracao/health, restaura imediatamente o backup e recarrega Nginx.
- O MCP original continua rodando em 3100 para retorno rapido.

**Nao e possivel garantir zero reconexoes de clientes** durante o novo roteamento MCP. Sessoes existentes podem precisar reconectar.

## 3. Smoke test APOS ativacao
- Atualizar/refrescar as ferramentas da conexao principal MCP JRConect no ChatGPT.
- Testar status_servidor, opa_fila_resumo, opa_catalogo_recursos, IXC e Financeiro com operador autorizado mcp-teste (azp chatgpt-mcp).
- Se OAuth nao reconhecer o novo endpoint, se qualquer ferramenta desaparecer ou se houver timeout, executar rollback imediatamente.
- Conferir que o token do cliente opa-suite nao recebe as tools do operador.
- Nao remover o plugin separado do Opa antes de confirmar esta paridade.

## 4. Rollback (somente Nginx, sem parar containers)
    sudo python3 /tmp/opa-mcp-bluegreen.py rollback

Devolve somente /mcp para a porta 3100 a partir do backup protegido.
Nao modifica /mcp-finance, /mcp-admin, /mcp-acs, os containers ou os Compose.
O script recusa sobrepor mudancas no Nginx feitas por terceiros apos o cutover.

## 5. Status
    sudo python3 /tmp/opa-mcp-bluegreen.py status

## Importante
Esta PR cria codigo de preparacao e transicao, mas NAO executa o procedimento.
Nunca copiar mcp/server.js do GitHub sobre /opt/jrconect-mcp/server.js.
Nao apagar a imagem de producao antiga ou o plugin Opa separado ate teste completo.
A principal meta e manter todas as integracoes ativas com o minimo risco e rollback imediato.
