# ACS — estilo aprovado em todas as páginas e abas

Tema compartilhado de dashboard/Dispositivos: fundo #10141F, cards #1B2231, borda #2C394E, azul #3499F5 e verde-água #20CAB9. Padroniza KPIs, cards, tabelas, abas, formulários, botões, resultados de ações e modais. Estados críticos continuam distinguíveis e comandos destrutivos mantêm vermelho.

## Cobertura conferida no navegador autenticado

| Área | Rota real | Componentes tratados |
|---|---|---|
| Dashboard | dashboard.php | Tema compartilhado e estrutura aprovada existente |
| Dispositivos | devices.php / device-detail.php | Abas Informações, WAN, LAN, Wi-Fi, Dispositivos, Voz, Ações, Histórico e Óptico |
| Central VoIP | voip-central.php | Cards, KPIs, navegação, estoque, monitoramento e formulários |
| Configuração | configuration.php | Cards, abas, campos e botões |
| MCP Server | mcp-server.php | jrc-mcp-hub, cards/sections, tabelas e modais |
| Firmware | firmware-library.php e demais páginas de firmware | fw-card, tabelas, campos e ações |
| Alertas | alerts.php | jrc-al-stat, filtros, linhas e estados |
| Relatórios | view-logs.php | Cards e tabelas com precedência sobre o azul legado |
| Endereçamento IP | ipam.php | jrc-ip-card/stat/table, abas, formulários e botões |
| Administração | users.php | Cards, tabela, formulários e modais |

O tema é incluído pelo rodapé comum e pelos documentos HTML independentes que o instalador detectar na raiz/em views. Não altera login, autenticação, APIs, regras de negócio, permissões ou dados.

## Conflitos confirmados e corrigidos

A inspeção autenticada confirmou `jrc-approved-acs` ativo. O problema restante não era ausência do CSS: módulos tinham estilos com prioridade maior ou carregados após o rodapé. A versão v2 usa seletores dos componentes reais, incluindo `jrc-ip` (a rota instalada é ipam.php) e `jra` (renderer real de Ações), para prevalecer sem depender da ordem de carregamento.

`jrc-acoes-width.js?v=2` percorre ancestrais de `.jra` e injeta largura fixa e `grid-column:1/-1 !important` em `#deviceTabContent`. Isso coloca Ações sobre o menu interno. O instalador remove apenas o include desse adaptador **visual**, após conferir seu SHA256 com a cópia inspecionada. Mantém intacto `jrc-acoes.js`, seus handlers, a API e os comandos. No próximo carregamento, as propriedades inline não voltam. A grade é definida pelo CSS: navegação e conteúdo em colunas diferentes, com o menu de ações dentro do conteúdo; no celular passam a linhas distintas.

A migração também preserva a remoção do antigo observador `pinCommandCenter`. A versão do CSS no link muda por hash para invalidar o cache sem depender de Ctrl+F5. O upgrade aceita somente o CSS v1 conhecido ou os bytes atuais; arquivos divergentes interrompem a implantação.

## Implantação após merge

```bash
cd /opt/jrc-acs-snmp-source
git checkout main
git pull --ff-only origin main
python3 scripts/deploy-acs-approved-theme.py plan
python3 scripts/deploy-acs-approved-theme.py apply
```

O plan enumera documentos encontrados e o dispositivo quando existe. O apply exige `php -l`, verifica âncoras/hash do adaptador visual, cria backup, preserva permissões e utiliza rollback automático. Não substitui o PHP inteiro pelos templates base do repositório.

## Validação

- `python3 tests/device-reference/shared-theme.test.py`: mecanismo legado, scripts preservados, documentos comuns/independentes e idempotência.
- `python3 tests/device-reference/live-style.test.py`: migração v1→v2, hash de cache, remoção do adaptador verificado e preservação do renderer de comandos.
- `PYTHONPATH=... python3 tests/device-reference/style-cascade.test.py` (tinycss2 + cssselect2): prioridade contra regras legadas observadas, inclusive carregadas depois do tema; estados seguro/destrutivo; grade desktop/celular.
- Gramática do PHP gerado analisada por php-parser; `php -l` real é obrigatório no servidor.

A inspeção no navegador confirmou os defeitos da versão anterior. Os testes da correção são de patch/cascata, não medem geometria de navegador. A nova versão ainda precisa ser implantada e conferida visualmente no ACS. Verificar todas as rotas da tabela e todas as abas do dispositivo, especialmente Ações após trocar de aba e redimensionar a janela.

## CPU e memória

O layout não substitui leitura ausente por número. A auditoria da #260 não encontrou parâmetros numéricos de CPU/RAM no cache consultado da ONU. A ausência no cache não comprova falta de suporte do modem. A coleta/mapeamento específicos continuam pendentes e não são resolvidos por esta alteração visual.

```bash
php scripts/audit-acs-device-resources.php FHTT99F5A9D0
```

A auditoria é somente GET ao NBI local, por serial exato; não executa tarefas no CPE. Não usar CPU/RAM do servidor no lugar dos recursos da ONU.
