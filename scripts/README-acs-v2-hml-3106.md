# ACS MCPv2 V2 — homologacao isolada em 127.0.0.1:3106

## Evidencia do ACS 14/11/2026

A imagem `jrconect-mcp:acs-per-handler-stage-20261114T031338Z` foi construida pelo comando `stage` e o log confirmou 26 handlers, 16 handlers com argumentos opcionais, JS OK e **nenhum container ou Nginx alterado**.

Esta PR prepara a proxima etapa sem afetar:
- MCP original na 3100
- ACS independente na 3103
- MCPv2 publicado na 3104
- HML V1 na 3105

## Operacoes permitidas

`scripts/acs-v2-hml-3106.py plan`: somente preflight. Compara imagem V1, valida estado/portas 3100/3103/3104/3105, redes, volumes, flags e imagem V2 exata. Exige 3106 livre e sem container preexistente.

`scripts/acs-v2-hml-3106.py start`: **apenas apos `plan` OK**. Cria novo container `jrconect-mcp-acs-hml-v2`, na porta **127.0.0.1:3106**, sem alterar os existentes. Copia somente o `connections.json` de HML V1 para pasta nova sob `/root/jrc-acs-hml-3106/data`; confere JSON e impede reutilizar volume de dados existente. Copia o conjunto exato das variaveis da HML V1 sem imprimir valores; cria env-file de uso temporario com mode 0600, remove automaticamente. Monta somente RO os segredos e os tres arquivos host `/proc/uptime`, `/proc/loadavg`, `/proc/meminfo`, igual a HML V1. Root filesystem readonly, sem privileges, sem capabilities, executa como UID 1000, com limites de memoria/CPU e sem Docker socket. Conecta redes de HML iguais. Exige 401 sem token, 200 em /health, flags ACS admin desabilitada, todas as portas anteriores saudaveis.

`scripts/acs-v2-hml-3106.py status`: somente leitura.

## Procedimento

Após o merge, baixar o script com `curl -fsSL` de SHA imutavel e `python3 -m py_compile` antes de executar.

```bash
sudo python3 /tmp/acs-v2-hml-3106.py plan
```

**Somente se `PLANO OK - NENHUMA ALTERACAO`:**

```bash
sudo python3 /tmp/acs-v2-hml-3106.py start
sudo python3 /tmp/acs-v2-hml-3106.py status
```

Se houver bloqueio, nao renomear/remover os containers para forcar o teste. Verificar motivo. O script remove apenas o container `jrconect-mcp-acs-hml-v2` se falhar no startup e nao faz rollback de Nginx/producao porque ele nao toca nesses componentes.

## Teste OAuth real na nova HML 3106 (apos start)

O teste ja homologado de Keycloak, `scripts/acs-oauth-pkce-hml.py`, agora aceita `--hml-port 3106`; por padrao continua 3105. Ele compara a lista de tools na 3104 com a 3106, rejeita ferramentas antigas ausentes e executa somente as duas ferramentas ACS de leitura **na 3106**. Nao executa tools/call na producao 3104.

```bash
python3 /tmp/acs-oauth-pkce-hml.py --check --hml-port 3106
python3 /tmp/acs-oauth-pkce-hml.py --hml-port 3106
```

**IMPORTANTE:** atualizar o arquivo `/tmp/acs-oauth-pkce-hml.py` com o commit aprovado mais recente ANTES de usar `--hml-port`. A versao antiga da PR #156 nao reconhece esse argumento. Nunca colar a URL de retorno com `code` e `state` no ChatGPT, screenshots ou mensagens. Copiar a URL completa apenas ao campo oculto do PuTTY conforme o procedimento ja homologado pelo usuario. O login Keycloak usa `mcp-teste` sem modificar sua senha ou o cliente OAuth.

## Gates para incorporar as ferramentas

Ainda precisamos:
1. Executar autenticação OAuth real e `tools/list` na **3106** (o script PKCE anterior aceita 3104/3105, precisa de adaptacao aprovada para 3106).
2. Conferir que o token real do `mcp-teste` contem a role ACS, sem imprimir JWT, subject ou token.
3. Confirmar catalogo de 47 ferramentas legadas + `acs_recursos_host` e `acs_status_integracoes` somente para usuario autorizado.
4. Invocar as duas ferramentas para verificar leitura. Testar negacao ao usuario sem role/cliente Opa. Auditar logs sem valores sensiveis.
5. Testar todas as consultas legadas funcionais e as permissoes por handler. Comparacao de nomes sozinha nao basta.

**NÃO executar nenhum `activate`, não alterar Nginx ou MCP 3104, nao remover plugin, nao habilitar administracao ACS** antes de todos os gates.
