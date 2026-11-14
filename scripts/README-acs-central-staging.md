# Integrar monitoramento ACS no MCPv2 com preservacao de handlers

## Diagnostico confirmado no ACS (14/11/2026)

O operador inspecionou o **container REAL** `jrconect-mcp-opa-ready`, porta 3104:

- Fabrica `makeMcpHandler` com combinacoes diferentes de permissoes e handlers.
- Rotas e handlers atuais para Opa, IXC, Financeiro, SHIELD e PABX. Nao podemos substituir o server.js pelo exemplo antigo do GitHub.
- JWT Keycloak e validado com `validarToken(...)` ANTES da selecao de handler.
- `acs-monitor-central-tools.js` da PR #150 ainda nao esta instalado na imagem ativa.
- `acs-admin-guarded-tools.js` da PR #151 ainda nao esta instalado.
- Flag `OPA_API_MCP_ENABLED` existente. Flags ACS de monitoramento e administracao ainda ausentes no container.
- ACS independente na porta 3103 ativo; MCP antigo na 3100 ativo.

## Esta PR (stage-only)

Inclui `scripts/stage-acs-readonly-central.py`:

1. Verifica /health das portas 3100, 3103 e 3104.
2. Copia `/app/server.js` SOMENTE para diretorio TEMPORARIO root-only no ACS (nao publica o codigo nem o altera no container).
3. Verifica marcadores, aridade exata (6 flags) de `makeMcpHandler`, o bloco OAuth real e a existencia dos guards antigos. Se houver divergencia, cancela sem modificar o container.
4. Em copia PRIVADA do server.js, acrescenta import do modulo da PR #150 e `AsyncLocalStorage` com payload JWT ja verificado.
5. Cria **um unico handler ACS de leitura** com os escopos legados completos. Esse handler SO pode ser selecionado para uma requisicao OAuth se **todas** as autorizacoes antigas forem simultaneamente verdadeiras: shield allowlist exata, Financeiro, Opa, IXC, PABX admin, e **acsMonitorAuthorized** (role `acs-mcp-admin`, allowlist ACS, `azp=chatgpt-mcp`, flag habilitada). Nao promove permissoes de outros usuarios.
6. O codigo de cada ferramenta ACS ainda verifica a identidade por invocacao, usando um contexto assíncrono por requisicao. Se o SDK nao propagar esse contexto na chamada MCP real, **a homologacao deve falhar** e a integracao precisa ser redesenhada.
7. Com `plan`, so verifica e compila uma copia em container efemero sem rede. Nao constroi imagem.
8. Com `stage`, cria uma imagem candidata LOCAL baseada na imagem exata da porta 3104, sobrepondo apenas server.js derivado do original e `acs-monitor-central-tools.js`, e cria arquivos placeholders para mounts individuais de /host/proc. **Nao inicia container, nao cria Nginx route, nao faz deploy nem restart.**

O modulo de administracao da PR #151 NAO e importado: ainda faltam executor local restrito e auditoria persistente para reinicio do monitor; a administracao continua DESATIVADA.

## Passos operacionais apos testes e merge

Baixar o script usando URL de **commit fixo**, conferir sintaxe e executar:

    sudo python3 /tmp/stage-acs-readonly-central.py plan

O resultado `PLANO OK` e a confirmacao dos marcadores do codigo real sao necessarios para permitir:

    sudo python3 /tmp/stage-acs-readonly-central.py stage

Mesmo o `stage` nao muda containers nem Nginx. Antes de qualquer teste/ativacao do MCP candidato, preparar separadamente container isolado, diretório /data privado persistente, redes e mounts idênticos aos existentes, mais os tres arquivos de host proc SOMENTE leitura. Preservar todos os tokens read-only.

**NAO executar `activate` da migracao Opa nesse contexto!** O script de blue-green anterior controla imagem com ID e porta fixa 3104, nao e um mecanismo generico de ativacao de ACS.

## Gates antes da producao

- Testar a autenticacao real Keycloak de `mcp-teste` e a presenca de `acs-mcp-admin` no JWT validado, sem imprimir token ou dados pessoais.
- Confirmar `acs_recursos_host` e `acs_status_integracoes` no MCP candidato.
- Negar usuario sem role, bearer estatico Opa e usuario com apenas ACS mas sem todas as demais autorizacoes. Escopos antigos nao podem ser ampliados.
- Confirmar o contexto de identidade dentro da chamada MCP real (nao apenas no middleware HTTP).
- Comparar catalogo e consultas Opa, IXC, Financeiro, Wiki, SHIELD, PABX, ACS com o MCP ativo na 3104.
- Testar rollback de futura rota com Nginx; manter 3100, 3103 e 3104 intactos.
- Planejar uma PR **separada** para executor local auditado e ferramentas administrativas (role `acs-mcp-admin` nao implica execucao root).
- Nao excluir o plugin ACS independente no ChatGPT ate equivalencia completa comprovada.

## Seguranca

Esta PR nao chama `docker stop`, `docker restart`, `docker compose up`, `nginx -s reload`, nem escreve em /opt/jrconect-mcp, /data ou configuracoes existentes. O codigo de producao e copiado unicamente para a area temporaria local e descartado ao final. Imagens criadas com `stage` nao executam. Nao habilita shell, SSH, sudo ou socket Docker no MCP.
