# PR proposta — ampliar acesso IXC no MCPv2 existente (ACS)

## Escopo e diagnóstico (12/11/2026)
Código em produção: `/opt/jrconect-mcp` (Node.js/Docker, porta 3100). **Não substituir pelo dashboard PHP deste repositório.**
Arquivos já existentes em produção: `server.js`, `integrations/ixc-webservice-auth.js`, `ixc-webservice-client.js`, `ixc-webservice-tools.js`, `ixc-report-tools.js`, `ixc-voip-tools.js`, `ixc-os-support-tools.js`.
O arquivo `integrations/ixc-webservice-auth.js` autoriza apenas quando `IXC_WS_MCP_ENABLED=true`, `azp=chatgpt-mcp`, `sub` pertence à allowlist e o token contém `ixc-mcp-admin`.
No `server.js` examinado, a seleção de handlers IXC está aninhada no bloco `SHIELD_MCP_ALLOWED_SUB === payload.sub`; isso acopla indevidamente o acesso IXC à autorização do Shield. O fallback fora desse bloco não seleciona o handler IXC.

## Mudança solicitada — exclusivamente permissões/roteamento
1. **Preservar** todos os handlers e integrações atuais, inclusive Shield, Financeiro, Opa, PABX, Wiki, e os nomes das ferramentas IXC.
2. **Desacoplar** o roteamento IXC do `SHIELD_MCP_ALLOWED_SUB`: após validar JWT e preencher `req.mcpUser`/registrar `connections.touch`, selecionar o handler IXC somente se `ixcWebserviceAuthorized(payload)` retornar verdadeiro. Não usar o acesso Shield como pré-requisito para IXC.
3. Para o operador OAuth autorizado, permitir papel Keycloak `ixc-mcp-read` **ou** `ixc-mcp-admin`, mantendo `IXC_WS_MCP_ENABLED=true`, `azp=chatgpt-mcp` e a allowlist `IXC_WS_ALLOWED_SUB`. Isso **não** concede escrita: as ferramentas existentes permanecem de consulta agregada.
4. Manter todas as decisões de roteamento PABX, Shield, Financeiro e Opa com precedência e combinações já existentes; avaliar testes de combinação antes de editar o servidor. Não trocar o handler padrão por handler com Shield para usuários não autorizados ao Shield.
5. Não ampliar os dados retornados nem a superfície da API IXC nesta PR. Consultas detalhadas exigem revisão separada de privacidade e autorização.

## Testes obrigatórios antes do merge
- JWT inválido -> 401.
- IXC desabilitado -> nenhuma ferramenta IXC.
- `sub` fora da allowlist -> nenhuma ferramenta IXC.
- `azp` diferente de `chatgpt-mcp` -> nenhuma ferramenta IXC.
- Papel `ixc-mcp-read` + allowlist + flag -> ferramentas IXC, **sem** Shield ou escrita.
- Papel `ixc-mcp-admin` + allowlist + flag -> ferramentas IXC existentes.
- Financeiro/Opa/PABX/Shield mantêm ferramentas e permissões anteriores, inclusive em combinações.
- Falha da API IXC não derruba o MCP.
- `tools/list` no ChatGPT contém ferramentas IXC para usuário autorizado.

## Implantação
- Esta PR **não é deploy** e não contém credenciais.
- Fazer revisão do `server.js` real e testes antes de portar a alteração para o ACS.
- Manter o plugin direto IXC até validar `tools/list` e consultas reais pelo MCPv2.
- Não executar `docker compose up`, reiniciar containers, nem remover plugin por efeito desta PR.

## Limitação
O repositório GitHub atualmente contém o dashboard PHP do ACS; o código Node.js em produção não está presente na branch `main` inspecionada. Por segurança, este primeiro PR documenta o patch e os critérios de aceitação **sem adicionar uma cópia parcial do servidor**. Para PR executável, versionar de forma segura a árvore atual do MCP (sem `secrets/`, tokens, backups, `runtime/`, nem `node_modules/`) no repositório correto, preservando o dashboard.
