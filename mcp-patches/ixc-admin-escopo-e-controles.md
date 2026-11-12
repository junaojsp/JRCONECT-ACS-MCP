# Escopo aprovado — IXC MCPv2 administrativo (PR #134)

Autorização do operador: **acesso administrativo completo**, incluindo consultas e operações de escrita **com confirmação individual**, controle de segurança e trilha de auditoria. Este documento é uma especificação de implementação; **não concede privilégios por si só**.

## Princípios
- Preservar integrações MCPv2 existentes: Financeiro, PABX, Shield, Opa e Wiki.
- Reutilizar o OAuth/Keycloak existente e o cliente IXC; não criar outro MCP.
- Separar papéis: `ixc-mcp-read` apenas leitura, `ixc-mcp-admin` habilita ferramentas administrativas *explicitamente autorizadas*, nunca escrita irrestrita.
- Validar `azp=chatgpt-mcp`, `sub` na allowlist, flag IXC ativa e papel do Keycloak em **toda** ferramenta administrativa.
- Usar allowlist de recursos e ações por endpoint; negar por padrão endpoints desconhecidos.
- Nunca permitir comandos arbitrários, URL/endpoint livre, SQL livre, alteração de permissões ou leitura de credenciais.
- Cada operação de escrita exige confirmação explícita do usuário **para aquela ação**, com recurso, ID e campos exibidos antes da execução. A confirmação não pode ser reutilizada em outra ação; preferir nonce/TTL no servidor e revalidar o estado do registro para evitar corrida.
- Registros de auditoria: usuário/sub, ação, recurso, identificador, data, resultado, correlation ID; mascarar dados pessoais, tokens e segredos.
- Não registrar dados pessoais completos em logs. Aplicar minimização de dados, paginação, limites, timeouts, rate limit e segregação de informações sensíveis.
- Ações de alto impacto (cancelamento de contrato, baixa financeira, exclusão, alteração em massa) requerem aprovação reforçada e testes isolados. Nunca executar em lote na primeira fase.

## Recursos previstos
| Domínio | Leitura administrativa | Escrita condicionada |
| --- | --- | --- |
| Clientes | consulta por ID, vínculos, situação, histórico | atualização de campos permitidos, sem alterar autenticação |
| Contratos | status, plano, eventos, ativações e cancelamentos | alterações de campos autorizados; cancelamento com dupla confirmação |
| OS | listagem por período/assunto/status, relato, motivo, histórico | criação/atualização/encerramento quando endpoint validado |
| Financeiro IXC | títulos, inadimplência, negociação e vínculos | apenas ações financeiras aprovadas individualmente, sem pagamento bancário automático |
| Produtos/planos | planos e serviços vinculados | alteração de vínculo apenas com checagens de faturamento |
| Rede | consulta de dados técnicos disponíveis na API | sem provisionamento automático nesta fase |

## Entrega incremental, mantendo escopo administrativo completo
1. Inventariar as rotas e métodos reais disponíveis no cliente IXC do ACS, incluindo schemas e regras de autorização. O repositório PHP do ACS não contém a árvore Node.js completa; a implementação deve ser feita sobre o código real, sem substituições parciais.
2. Entregar leitura detalhada e paginada de OS de cancelamento para o plano de retenção, validando datas, motivos, status, filtros e mascaramento.
3. Implementar wrappers de escrita específicos, com validação de entrada, autorização por recurso, confirmação individual e auditoria.
4. Testes de regressão para acessos somente leitura, ausência de role, sub não autorizado, cliente OAuth incorreto, confirmação ausente/reutilizada, erros de API, concorrência e isolamento das demais integrações.
5. Implantação separada após revisão; sem reiniciar ACS ou alterar Keycloak por efeito do merge.

## Estado
O patch de OS existente na PR #134 é um **protótipo não validado**, não uma implementação completa da autorização administrativa. Antes de aplicar, revisar o código Node.js real e verificar que o patch corresponde aos métodos e campos existentes. **Não fazer merge/deploy como se a escrita estivesse pronta.**
