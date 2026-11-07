# Ferramenta MCP: pabx_relatorio_atendentes

A ferramenta nova recebe apenas `dias` = "7", "15" ou "30", consulta a API HTTPS do PABX com o token READ existente e retorna contagens agregadas. Nao le diretamente logs ou credenciais do PABX.

## Preservar a instalacao real
**Nao substituir o `/opt/jrconect-mcp/server.js` em producao pelo `mcp/server.js` deste repositorio.** O arquivo principal versionado e mais simples e nao representa todas as ferramentas em execucao.

No ACS em producao, os registros PABX sao feitos por `registerPabxTools(mcp)` importado de `./pabx.js`. Para integrar:
1. Comparar a implementacao atual de `/opt/jrconect-mcp/pabx.js` com este novo modulo.
2. Copiar `pabx-queue-report.js` para um modulo isolado que o carregador atual consiga importar (por exemplo `/opt/jrconect-mcp/pabx-queue-report.js`); nao modificar outras funcoes.
3. Importar `registerPabxQueueReport` no arquivo que registra as ferramentas de consulta e chamá-lo uma unica vez no mesmo `McpServer` usado por `registerPabxTools`. **Nao incluir na rota MCP administrativa e nao habilitar escrita.**
4. Reutilizar `/run/secrets/pabx-read-token` somente se for o caminho real do token READ montado no ambiente atual; senão, passar o caminho correto ao registrar (sem versionar segredo).
5. Validar `node --check` e `node --test` antes de reiniciar apenas o processo MCP. Confirmar as ferramentas antigas apos o deploy.

Exemplo de integracao dentro da funcao de registro ja existente (adaptar a localizacao do modulo e token path):

```js
import { registerPabxQueueReport } from './pabx-queue-report.js';
// Depois de registrar as ferramentas PABX existentes:
registerPabxQueueReport(mcp, { tokenFile: '/run/secrets/pabx-read-token' });
```

## Antes de ativar
Instalar e testar primeiro o endpoint `queue_report` da PR correspondente em `JRC-PABX`. Se a API nao estiver implantada, a ferramenta retornara "Relatorio indisponivel". Ela nao modifica filas, ramais ou URA.

## Interpretacao
`conectadas` = eventos CONNECT; `toques_sem_resposta` = eventos RINGNOANSWER. Tentativas podem se repetir numa mesma ligacao. `toques_no_almoco` e `toques_fora_almoco` usam os intervalos cadastrados no PABX (Maria 12h-13h e Emylly 13h-14h), nao determinam culpa. `abandono_cliente` pertence a fila, nao ao agente. Os logs retidos podem nao cobrir o periodo inteiro.

## Testes
`node --check mcp/integrations/pabx-queue-report.js`
`node --test mcp/integrations/pabx-queue-report.test.js`
