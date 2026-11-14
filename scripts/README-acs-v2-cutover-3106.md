# Promoção ACS V2 para JR Conect MCPv2 (3104 -> 3106)

## Evidência técnica, em 14/11/2026

Após a PR #160, o ACS-V2 HML foi iniciado no container `jrconect-mcp-acs-hml-v2`, porta local **3106**, com /health HTTP 200, sem-token HTTP 401, volumes /data isolados, mounts de segredo read-only e administração desabilitada.

Teste OAuth **real** concluído:
- Porta 3104: 47 ferramentas, login OAuth + initialize OK.
- Porta 3106: 49 ferramentas, login OAuth + initialize OK.
- Tools ACS: `acs_recursos_host`, `acs_status_integracoes`.
- Nenhuma ferramenta anterior ausente da 3106.
- Execução de ambas tools ACS: `CONSULTA OK` no 3106.
- Administração ACS continua DESABILITADA; não foi testada ou ativada.

Validações legadas também realizadas via plugin JR Conect MCPv2 em ChatGPT: `opa_fila_resumo`, `ixc_os_resumo` e `shield_fluxo` responderam. **Não equivale** a regressão funcional de todas as 47 ferramentas. Teste com **JWT real sem a role `acs-mcp-admin`** continua pendente. O teste de função com JWT simulado sem role já passou mas não substitui teste de autenticação end-to-end.

## Script de mudança controlada

`scripts/acs-v2-cutover-3106.py` possui:

- `plan` — somente leitura. Exige /health nas portas 3100, 3103, 3104, 3105 e 3106; ambas 3104/3106 respondendo 401 sem token; verifica imagem real da 3106, redes, /data exclusivo, readonly rootfs, mounts de segredos legados e que TODOS os parâmetros antigos sob FINANCE_MCP_, IXC_WS_, OPA_API_, SHIELD_MCP_ e KEYCLOAK_ são idênticos na 3104 e 3106. Rejeita administração ACS habilitada. Confirma `nginx -t` e bloco exato `location = /mcp` apontando para 3104. **Se divergir, não ativar nem mudar configurações na mão.**
- `activate` — somente após `plan` OK, negativo sem role validado e revisão pelo operador. Exige `JRC_APPROVE_ACS_V2_CUTOVER=YES` e ausência de state/backup anteriores. Salva backup do arquivo Nginx efetivo e SHA256 antes de qualquer alteração; troca SOMENTE um `proxy_pass http://127.0.0.1:3104;` por `3106;` na localização EXATA `/mcp`; executa `nginx -t`, `nginx -s reload` e valida health e 401 no HTTPS público Nginx loopback. Restaura o arquivo antigo automaticamente em falha, desde que ninguém mais o tenha alterado.
- `rollback` — restaura backup **somente** quando config atual é idêntica em SHA à candidata, evitando sobrescrever alterações paralelas. O antigo MCP na 3104 permanece em execução. Se a aplicação falhar, usar rollback imediatamente.
- `status` — mostra upstream exato, portas e hashes sem imprimir env values, JWT, segredo ou dados de cliente.

**Não executa** docker restart/stop/rm, docker compose, alteração de Keycloak, permissão, imagem, plugin, volumes compartilhados ou rotas Nginx diferentes de `location = /mcp`.

## Passo 1: validar PR de migração (SEM mudanças)

Baixar script por SHA imutável (link disponibilizado após CI) e verificar `py_compile`, depois:

```bash
sudo python3 /tmp/acs-v2-cutover-3106.py plan
```

Se `BLOQUEADO`, não usar `activate`; enviar somente o código do bloqueio e corrigir com nova PR, mantendo os servidores como estão.

## Passo 2: validar negação de acesso ACS ao usuário sem role

Antes de promover, usar uma conta de homologação Keycloak **distinta** do usuário `mcp-teste`, autenticada com OAuth e sem `acs-mcp-admin`, para verificar que 3106 NÃO anuncia as tools ACS. Não retire a role de `mcp-teste` nem altere suas senhas/credenciais. Caso não exista conta de testes sem role, NÃO alegar que o gate passou.

As funções de autorização ACS já rejeitam JWT fictício sem role; falta comprovar o fluxo real. Guardar somente a evidência sem token/URL de retorno, não registrar dados pessoais.

## Passo 3: ativação controlada

Só depois dos gates e de confirmar manutenção operacional aceitável para possível reconexão de sessões MCP:

```bash
sudo env JRC_APPROVE_ACS_V2_CUTOVER=YES python3 /tmp/acs-v2-cutover-3106.py activate
sudo python3 /tmp/acs-v2-cutover-3106.py status
```

Se alguma chamada do ChatGPT ou qualquer sistema antigo falhar:

```bash
sudo python3 /tmp/acs-v2-cutover-3106.py rollback
```

A nova imagem 3106 continua disponível para investigar; o upstream anterior volta para 3104. Sessões MCP antigas podem precisar reconectar; não existe garantia de zero reconexões.

## Passo 4: atualizar a ferramenta JR Conect MCPv2 no ChatGPT

Na interface atual de **Plugins** para ChatGPT Business:

1. Abra **Workspace Settings → Plugins** e localize a conexão personalizada **JR Conect MCPv2**.
2. No gerenciamento da conexão, selecione atualizar/refresh do catálogo de ferramentas (também pode aparecer em **Action control / Configure Actions**, dependendo da interface).
3. Revise as **duas novas ferramentas somente leitura** e habilite-as, mantendo as 47 existentes.
4. Se necessário, refaça apenas a autenticação da conexão; o endpoint público `https://mcp.jrconect.com/mcp` e o cliente Keycloak `chatgpt-mcp` não mudaram.
5. Em uma nova conversa, use @JR Conect MCPv2 e teste `acs_recursos_host` e `acs_status_integracoes`.

Conforme ajuda oficial atual para plugins MCP personalizados, administradores/proprietários Business podem atualizar a lista de ferramentas existentes sem recriar o plugin, desde que a conexão e as permissões estejam válidas:
https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt

O catálogo publicado não é atualizado automaticamente por um merge GitHub ou troca Nginx. Atualmente, não há nesta sessão uma ferramenta conectada que execute a operação de refresh do catálogo no ChatGPT. Essa etapa exige ação administrativa na interface.

## Restrições finais

- Não remover o antigo MCP 3104 ou instâncias de homologação logo após mudança.
- Não remover o plugin ACS independente até confirmação funcional e decisão separada.
- Não habilitar `acs_admin_executar` ou comandos de escrita nesta fase.
- Não expor códigos OAuth, JWT, senha, ou URLs completas de callback em screenshots.
