# Diagnóstico do incidente IXC (#138)

Esta PR NÃO comprova nem corrige a causa do incidente. Não fazer merge/deploy como se restaurasse o IXC. Ela prepara observabilidade mínima para descobrir qual condição falha no OAuth real, antes de propor correção.

## Evidências revisadas

O server.js enviado pelo operador em 12/11/2026 já inclui IXC nos catálogos SHIELD e PABX quando ixcWebserviceAuthorized(payload) retorna true. O arquivo de autorização de produção enviado aceita tanto ixc-mcp-admin quanto ixc-mcp-read; a versão no GitHub aceita somente admin. Não copiar o server.js básico nem a autorização do repositório sobre produção.

Ainda falta confirmar a versão realmente carregada no container, as condições da autorização do token atual e o tools/list efetivo. Nenhum JWT real foi inspecionado. Ausência no catálogo do ChatGPT, isoladamente, não demonstra que esta função retorna false.

## Preparação sem alterar produção

Na raiz do checkout atualizado desta branch:

```bash
python3 -m unittest discover -s tests -p test_ixc_auth_diagnostic.py -v
python3 scripts/stage-ixc-auth-diagnostic.py
```

O script lê /opt/jrconect-mcp/server.js e cria um diretório temporário exclusivo, modo 0700, com server.before.js, server.candidate.js e manifest.json. Valida a sintaxe ES module com Node. Recusa uma estrutura diferente ou instrumentação repetida. Não instala o candidato nem executa Docker. O diff só adiciona um Set e um bloco de diagnóstico depois da validação OAuth da rota /mcp. Registros, handlers, autorizações e outros endpoints permanecem idênticos.

Os arquivos temporários contêm o código de produção: mantenha-os privados. O backup temporário deve ser copiado para o diretório de backups do operador antes de qualquer aplicação; não usar /tmp como backup durável.

## Aplicação e reversão pendentes de revisão

Antes de instalar, revisar o diff local, conferir o SHA-256 da origem contra manifest.json e preservar cópia durável com proprietário e permissões originais. A instrumentação exige atualizar o código efetivamente carregado pelo container; o método depende dos volumes e da imagem atuais e não foi inferido nesta PR. Não executar rebuild só por fazer merge. Se necessário, manter ambos os arquivos Compose e todos os overrides atualmente usados.

A reversão consiste em restaurar a cópia exata anterior com seus atributos e carregar essa versão pelo mesmo método usado na instalação. Não alterar Keycloak, variáveis IXC, credenciais ou arquivos de integração.

## Leitura após instalação revisada

Fazer uma consulta pelo ChatGPT e consultar apenas as linhas de diagnóstico:

```bash
docker logs --since 5m jrconect-mcp 2>&1 | grep '^JRC_IXC_AUTH_DIAGNOSTIC_V1 '
```

As linhas contêm exclusivamente booleans:

- enabled: IXC_WS_MCP_ENABLED é exatamente true.
- client_matches: azp é chatgpt-mcp.
- subject_matches: sub está na lista IXC (sem expor identificadores).
- role_admin / role_read: presença das funções IXC.
- authorized: resultado da função realmente instalada.
- shield_branch: a condição original do ramo SHIELD corresponde ao token.

As linhas não identificam pessoas ou sessões. Produzir uma consulta controlada e observar a janela temporal; não atribuir uma linha automaticamente a um usuário. Há no máximo 128 combinações por processo, registradas uma vez cada. Um erro de diagnóstico não interrompe a requisição.

Se authorized=false, investigar as condições falsas sem remover controles. Se authorized=true mas faltarem ferramentas, inspecionar registradores, versão da imagem e catálogo tools/list da mesma sessão. Se não houver linha, verificar se a imagem instrumentada está ativa e se uma requisição OAuth chegou à rota /mcp; ausência de log não prova falha de autorização.

Depois de coletar evidência, remover a instrumentação por reversão e preparar a correção causal. Validar as 11 ferramentas IXC e regressão dos demais módulos. A expansão de OS de cancelamento permanece suspensa. Não fecha #138.
