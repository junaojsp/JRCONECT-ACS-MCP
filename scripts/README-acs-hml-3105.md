# Homologacao isolada do MCPv2 ACS na porta 3105

## Contexto confirmado em 14/11/2026

O operador confirmou no ACS:
- Imagem candidata: `jrconect-mcp:acs-readonly-stage-20261114T022110Z`.
- `3100` (MCP antigo), `3103` (monitoramento ACS independente), `3104` (MCPv2 atual) responderam HTTP 200.
- `3105` livre.
- Teste simulado do modulo ACS da PR #150: duas tools registradas, JWT ficticio autorizado, role ausente negada; nenhum servico alterado.
- PR #154 corrigiu a assinatura real `makeMcpHandler` e `plan`/ `stage` anteriores passaram.

## Proposta nesta PR

`scripts/acs-hml-3105.py` com comandos `plan`, `start` e `status`.

`plan` **nao altera nenhum servico**, checa:
- Imagem candidata disponivel e MCPv2 real protegido e ativo.
- HTTP healthy nas portas 3100, 3103, 3104.
- Nenhum container HML existente e porta 3105 disponivel.
- Redes ativas sao exatamente `jrconect-mcp_default` e `jrconect-auth_auth-internal`.
- Mounts existentes sao um /data bind de escrita (que **nao** sera compartilhado) e segredos bind readonly sob /run/secrets/.
- Flags Opa habilitadas para leitura e escrita Opa desativada; `ACS_*_MCP_ENABLED` ainda nao definidas na producao.
- Opa e SHIELD usam exatamente **o mesmo unico subject UUID** de `mcp-teste`, sem mostrar seu valor. Caso contrario, bloquear e revisar o codigo, sem supor permissoes.

`start` cria apenas `jrconect-mcp-acs-hml`, uma NOVA instancia, com:
- Port mapping **somente loopback** `127.0.0.1:3105:3100`; Nginx nao e alterado.
- Imagem exata ja construida, `--read-only`, `--cap-drop ALL`, `--security-opt no-new-privileges`, usuario UID 1000, limites de memoria/CPU/PIDs e sem restart automatico.
- Copia privada de `connections.json` para `/root/jrc-acs-hml-3105/data` com permissoes restritas; **nunca** usa o mesmo volume /data da producao.
- Mounts secret RO exatamente como a 3104, nenhum outro mount de escrita; /proc/uptime, /proc/loadavg, /proc/meminfo individualmente em /host/proc/* como readonly. Nao monta o socket Docker.
- Copia configuracoes de ambiente 3104 sem imprimir valores, e acrescenta somente `ACS_MONITOR_MCP_ENABLED=true`, `ACS_MONITOR_ALLOWED_SUB` derivado da mesma identidade ja permitida em Opa/SHIELD e `ACS_ADMIN_MCP_ENABLED=false`.
- Redes do MCPv2 atual para consultar Keycloak. Nao publica endpoint para internet nem cria plugin ChatGPT.
- Valida imagem, redes, mounts, data isolado, /health na 3105 e nas portas anteriores. Se falhar depois de criar a HML, remove **somente a HML**, nunca o MCP existente.
- **NUNCA** altera rota /mcp ativa, Nginx, Keycloak, outros containers, Compose, env de producao ou secret files.

`status` verifica o container HML, suas flags ACS e sua disponibilidade local.

## Passos apos merge e aprovacao

1. Baixar o arquivo com URL de commit **imutavel**, conferir sintaxe.
2. Rodar `sudo python3 /tmp/acs-hml-3105.py plan` (sem alterar producao).
3. Se e SOMENTE se plano passar, rodar `sudo python3 /tmp/acs-hml-3105.py start`.
4. Rodar `sudo python3 /tmp/acs-hml-3105.py status` e mostrar saida para validacao.

## Gates ainda pendentes, NAO ACHAR QUE ESTA EM PRODUCAO

A subida da 3105 somente confirma processo e HTTP /health, nao comprova autenticacao ou invocacao MCP real. Em etapa posterior:
- Autenticar via Keycloak com permissao REAL `acs-mcp-admin` do `mcp-teste` e verificar `tools/list` na 3105 sem exibir token.
- Validar efetivamente `acs_recursos_host` e `acs_status_integracoes`; confirmar JWT role e que bearer Opa ou usuario sem role nao consegue acessar.
- Investigar compatibilidade de contexto `AsyncLocalStorage` do handler MCP no ciclo de sessao HTTP.
- Comparar ferramentas Opa/IXC/Financeiro/Wiki/SHIELD/PABX antes de pensar em mudanca de Nginx.
- Ainda nao ha agente administrativo local nem auditoria de restart; modulo admin permanece desabilitado e nao incluso na candidata.
- Manter plugin Monitoramento ACS independente ate validar tudo pelo unico JR Conect MCPv2.

## Recuperacao

Se um erro impedir inicio, script nao altera 3100/3103/3104 nem Nginx. A HML isolada pode ser inspecionada. **Nao** executar script de rollback Opa ou recriar MCP da 3104 para resolver erros da 3105.
