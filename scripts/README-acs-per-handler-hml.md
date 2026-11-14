# ACS no MCPv2: catalogo com permissao individual, sem perder ferramentas legadas

## Diagnostico comprovado no ACS (14/11/2026)

- OAuth real funcionou no Keycloak, MCP em 3104 e candidata anterior em 3105.
- Ambas listaram **47 ferramentas**; 0 ferramentas antigas ausentes.
- **Nenhuma** das 2 ferramentas ACS apareceu na 3105.
- Diagnostico HML em 3105 confirmou `ACS_MONITOR_MCP_ENABLED=true`, allowlist igual para Opa/SHIELD, e a funcao ACS aceitou um JWT **ficticio** com role `acs-mcp-admin`; sem role bloqueou.
- O V1 acrescentou um handler ACS apenas se **todas** as autorizacoes Financeiro, Opa, IXC, PABX e SHIELD fossem verdadeiras ao mesmo tempo. Isso bloqueou a selecao ou a role nao chegou no JWT real (ambas possibilidades).
- Nenhum resultado comprovou ainda que a role `acs-mcp-admin` esta inclusa no **JWT real**.

## Correcao V2 nesta PR

`scripts/stage-acs-per-handler-hml.py`:

- Lê `/app/server.js` **somente do container HML** `jrconect-mcp-acs-hml` ativo na 3105, nunca da producao na 3104.
- Em uma COPIA LOCAL PRIVADA, remove exatamente o handler novo com todos os escopos e seu `if` adicional criado no V1.
- Conserva todas as estruturas antigas de roteamento (PABX, SHIELD, Opa, Financeiro, IXC, Wiki) exatamente como estavam, inclusive suas bitmasks e ordem de preferencia.
- Para cada chamada original `toNodeHandler(makeMcpHandler(b1,b2,b3,b4,b5,b6))`, troca somente por um wrapper com os MESMOS seis booleanos.
- O wrapper, quando o JWT real ja validado nao satisfaz `acsMonitorAuthorized`, encaminha a requisicao ao MESMO handler antigo. Se satisfaz, cria (sob demanda) a **mesma combinacao legada** com `includeAcs=true`, sem ampliar nenhum dos seis escopos.
- Usa `AsyncLocalStorage.enterWith(payload)` SOMENTE depois de `validarToken` para tornar a identidade disponivel na chamada da ferramenta. Ainda exige homologar propagacao real do contexto pelo SDK MCP.
- O token estatico Opa nao passa pelo bloco de OAuth e continua no handler antigo.
- Nenhum recurso administrativo ACS e importado; somente duas consultas de monitoramento read-only.
- Abortara em `plan` se o codigo real divergir, existirem referencias antigas ao handler ACS V1, aparecer assinatura nao prevista, houver menos de oito handlers legados ou Node nao compilar a candidata.
- `plan` NAO altera nada. `stage` apenas gera uma **imagem Docker local nova**. Nao inicia containers nem modifica 3100/3103/3104/3105, Nginx, Keycloak ou /data.
- Verifica /health das quatro instancias e que a imagem do container HML nao mudou antes/depois do stage.
- Testes unitarios exercitam o patch com factory, handlers, varias bitmasks e bloqueios em divergencias.

## Uso apos review/merge

Baixar por URL de commit fixa (fornecida apos CI), executar:

```bash
sudo python3 /tmp/stage-acs-per-handler-hml.py plan
```

Somente apos resultado `PLANO OK` e revisao do log:

```bash
sudo python3 /tmp/stage-acs-per-handler-hml.py stage
```

O resultado imprimira `IMAGEM_CANDIDATA_V2: jrconect-mcp:acs-per-handler-stage-...`.
**NAO iniciar a nova imagem diretamente na porta 3105** e nao executar `activate` ou `rollback`. Preparar **uma nova HML em 3106**, com dados independentes e nova porta local em PR ou script separado, antes de ensaiar a candidata, sem destruir a HML 3105.

## Gates para qualquer futura ativacao

1. Testar OAuth real e catalogo `tools/list` da imagem V2 na 3106. O usuario `mcp-teste` deve ter todas as suas ferramentas antigas **mais** `acs_recursos_host` e `acs_status_integracoes`.
2. Confirmar se `realm_access.roles` do JWT real (sem expor token) inclui `acs-mcp-admin`; se nao incluir, nao alterar Keycloak da producao precipitadamente: corrigir scope/mapeamento em ambiente seguro.
3. Invocar ambas consultas ACS na 3106 e confirmar dados read-only.
4. Testar sem role e token estatico Opa: nao podem acessar ACS; os catalogos legados nao devem ser expandidos.
5. Fazer regressao funcional IXC, Opa, Financeiro, Wiki, SHIELD e PABX; comparar ferramentas e permissoes.
6. Somente apos todos gates verdes planejar rollout com rollback do `/mcp` sem reconfigurar o Keycloak e sem apagar plugins.
7. Administracao ACS (restart ou comandos locais) segue **DESABILITADA** ate agente restrito e auditoria persistente.

Nao compartilhe tokens, passwords, codigos OAuth ou screenshots de URL callback.
