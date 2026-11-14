# Homologar OAuth REAL do ACS em 3105 — sem mexer na producao

## O que ja esta confirmado (14/11/2026)

- Keycloak publico `chatgpt-mcp` em `https://auth.jrconect.com`, realm `jrconect`.
- Standard Flow ativado, PKCE **S256 obrigatorio**, Device Grant desabilitado (HTTP 400 `unauthorized_client` e esperado).
- URI autorizada `http://127.0.0.1:8765/callback` ja esta cadastrada. Nao alterar o Keycloak.
- MCPv2 em `127.0.0.1:3104/mcp` e HML ACS em `127.0.0.1:3105/mcp`; ambos retornam HTTP 401 sem token.
- ACS HML 3105 esta ativa com montagem readonly de arquivos `/proc` e administracao ACS explicitamente DESABILITADA.
- Usuario Keycloak `mcp-teste` recebeu role `acs-mcp-admin`.
- Teste de modulo com JWT FICTICIO passou; precisa verificar OAuth real e as chamadas MCP.

## Script (somente leitura)

`scripts/acs-oauth-pkce-hml.py` usa exclusivamente a biblioteca padrao Python. Ele nao abre portas, nao faz tunel SSH, nao escreve arquivos de token e nao altera Keycloak, Docker, Nginx ou MCP.

Fluxo:
1. Executar `python3 /tmp/acs-oauth-pkce-hml.py --check` no ACS; confirma /health 3104 e 3105.
2. Executar `python3 /tmp/acs-oauth-pkce-hml.py` no PuTTY do ACS; script gera URL OIDC de **Authorization Code + PKCE S256** com state aleatorio e prompt login.
3. Copiar a URL de autorizacao que aparecer no PuTTY e abrir no navegador do Windows. Fazer login como `mcp-teste`, **nao como administrador**.
4. O navegador tentara redirecionar para `http://127.0.0.1:8765/callback?...` e podera mostrar `ERR_CONNECTION_REFUSED` / `nao foi possivel conectar`. Isso e **esperado**: o teste NAO cria listener local. O URL na barra tem um **codigo de autorizacao confidencial**.
5. Copiar a URL **completa da barra de enderecos**, colar APENAS no campo oculto do PuTTY `Cole aqui a URL completa...:` e pressionar Enter. **NAO** colar aqui no ChatGPT, enviar por e-mail, screenshot, tickets ou salvar em arquivo. A entrada do PuTTY fica sem eco.
6. O script valida origin/port/caminho/state, troca o code por access token diretamente no Keycloak via HTTPS, conserva o token SOMENTE em memoria, negocia MCP `initialize`, lista `tools/list` de 3104 e 3105, compara nomes e executa **APENAS** as tools ACS de leitura `acs_recursos_host` e `acs_status_integracoes` no ambiente 3105.
7. Imprime somente contagem de ferramentas e `CONSULTA OK` ou codigo de erro sem exibir JWT, dados de clientes ou detalhes da resposta.

## Regras de seguranca

- Nenhum `client_secret`, senha ou token precisa ser compartilhado com ChatGPT.
- Apenas client_id publico `chatgpt-mcp`, callback pre-cadastrado e duas portas localhost.
- Nenhum `tools/call` pode ser feito em producao 3104; nela ha SOMENTE initialize e tools/list.
- O script nao faz `acs_admin_executar`, Docker restart, SSH remoto, Nginx reload, atualizacao de flags nem altera a rota publica.
- Se faltar qualquer ferramenta antiga, ou as duas ACS, ou as chamadas ACS retornarem erro, o resultado final sera **BLOQUEADO**, nao promover ao Nginx.
- O teste cobre a identidade `mcp-teste` autorizada. Para comprovar **negacao de outro usuario sem role**, usar em etapa separada um usuario de testes com escopo minimo. Nao remover roles de `mcp-teste` para testar.

## Limites

O PKCE protege o code trocado pela instancia que possui o verifier; o retorno manual exige cuidado com a URL confidencial. Codigos de autorizacao expiram rapidamente: conclua a copia em poucos minutos (o script limita 240 s). Se nao conseguir copiar a URL do navegador, NAO altere o callback do Keycloak; podemos elaborar outro teste com um receptor local temporario via PuTTY SSH tunneling.
O teste de catalogo nao comprova o comportamento das ferramentas antigas, apenas seus nomes; a regressao funcional completa ainda e obrigatoria antes de ativar.
O modulo de administracao planejado na PR #151 **nao esta implantado nem foi testado**. Permanece DESABILITADO.

## Passos de uso (apos merge e revisao)

Baixar o script por URL de commit imutavel (do commit dessa PR), compilar e executar no ACS:
```bash
python3 -m py_compile /tmp/acs-oauth-pkce-hml.py
python3 /tmp/acs-oauth-pkce-hml.py --check
python3 /tmp/acs-oauth-pkce-hml.py
```
Compartilhe somente as linhas de resultado do terminal, nunca o URL de autorizacao de retorno ou seu conteudo.
