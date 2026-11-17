# ACS: tema aprovado compartilhado

O rodapé de produção enviado contém `jrc-command-center` e um `MutationObserver` que recoloca seu CSS azul no fim do documento. Esse mecanismo vence os arquivos novos e explica a mistura persistente de temas. O instalador remove apenas esse link e o script de pinagem identificado, mantém os scripts operacionais e instala um único tema compartilhado.

Cobertura: rodapé comum de Visão geral, Dispositivos, Central VoIP, Configuração, Firmware, Alertas, Relatórios, Endereçamento IP e Administração; documentos independentes na raiz e em `views`, incluindo MCP Server quando não utiliza o rodapé comum. O script identifica esses documentos por HTML com fechamento do body. Não altera páginas de login, endpoints de API, autenticação ou dados.

Paleta aprovada: fundo #10141F, painéis #1B2231, borda #2C394E, azul #3499F5 e verde-água #20CAB9. Padroniza cards, KPIs, tabelas, formulários, abas, modais e estados, preservando grades operacionais existentes. Ações recebe grade própria entre lista de comandos e painel, sem posicionamento que sobreponha o menu do dispositivo. Histórico e Óptico seguem o mesmo acabamento, sem mudar dados ou desenhar amostras ausentes.

## Implantação após merge

```bash
cd /opt/jrc-acs-snmp-source
git checkout main
git pull --ff-only origin main
python3 scripts/deploy-acs-approved-theme.py plan
python3 scripts/deploy-acs-approved-theme.py apply
```

O plan enumera os documentos independentes efetivamente encontrados. O apply exige validação PHP antes de gravar, cria backup, preserva permissões e utiliza rollback automático. Interrompe se o observador antigo não corresponder ao mecanismo identificado ou se encontrar asset novo divergente.

## CPU e memória: diagnóstico necessário

A normalização da #259 não confirmou que o CPE disponibiliza esses campos. A nova auditoria consulta somente o cache local do GenieACS, por serial exato. Retorna percentuais normalizados e caminhos numéricos de CPU/RAM encontrados em DeviceInfo, inclusive extensões de fabricante, sem retornar credenciais, clientes, SSID ou senhas. Não envia tarefas, refresh ou provisões ao CPE e não altera a coleta.

```bash
php scripts/audit-acs-device-resources.php FHTT99F5A9D0
```

`AUSENTE_OU_NAO_MAPEADO_NO_CACHE` não significa que o equipamento é incapaz de reportar: distingue uma pendência de leitura/mapeamento de um erro visual. A saída real é necessária para definir a correção de coleta/caminho específico deste modelo. Não se substitui percentual desconhecido por zero e não se usa CPU/RAM do servidor ACS no lugar dos recursos da ONU.

## Validação

`python3 tests/device-reference/shared-theme.test.py`: remoção do observador antigo, preservação de scripts, recusa de observador desconhecido, cobertura de footer/documento independente, login excluído e reaplicação idempotente. Também aplicado à cópia do rodapé de produção enviado. Gramática PHP analisada por php-parser; CSS por tinycss2. PHP real é exigido pelo instalador. Não houve implantação, consulta NBI ou aprovação visual das páginas nesta sessão. O navegador conectado não tinha uma aba ACS disponível para conferência.

Revisão pós-deploy: conferir as oito áreas citadas, Ações/Histórico/Óptico e tamanhos desktop/celular com Ctrl+F5. `APLICADO_OK` confirma arquivos instalados; a padronização visual de cada tela ainda precisa de conferência no ambiente real.
