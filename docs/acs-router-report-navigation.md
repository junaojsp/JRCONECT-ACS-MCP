# ACS - Roteadores clicaveis e relatórios no padrão azul

## Resultado esperado

Na Visão geral, dentro de **Sinal da frota**, clicar em **Roteadores**
abre `/router-report.php`. A tela mostra somente os roteadores
encontrados no inventário ACS, com equipamento, cliente PPPoE,
serial, IP WAN e última comunicação, busca e paginação.

O relatório usa a mesma classificação que produz a contagem Roteadores
e lê o snapshot MySQL `jrc_device_inventory_*`, sem nova consulta por
ONU ao abrir a tela. Os filtros ópticos Critico, Fraco e Sem leitura
permanecem com suas URLs, contagens, ações e fonte IXC intactas.

**Padrão visual:** A tela de roteadores já nasce no layout azul
do dashboard. O relatório óptico existente (`view-logs.php`), que estava
com a tabela de fundo cinza, passa a carregar a folha de estilo
`assets/css/jrc-signal-report-blue.css`. Essa folha altera apenas CSS
daquela página: cartões, cabeçalhos e linhas em azul escuro. Preserva
legendas, ícones, valores e cores dos badges de sinal.

## Escopo da PR

- Arquivo novo `router-report.php`: relatório autenticado, somente
  leitura, busca e paginação por 20 roteadores.
- Arquivo novo `lib/JrcRouterReportRows.php`: filtra e projeta a partir
  da mesma classificação do contador Roteadores.
- Arquivo novo `assets/css/jrc-signal-report-blue.css`: tema azul apenas
  para a página de relatório óptico existente.
- JS `assets/js/jrc-router-signal.js`: adiciona evento de clique e
  acessibilidade na linha Roteadores clonada, removendo eventuais
  handlers ópticos antes de apontar para a nova página.
- Instalador `scripts/deploy-acs-router-report.py`: protege os arquivos
  personalizados e cria backups com rollback em falha.

## Operação depois dos testes e merge

Simular primeiro, sem escrever no ACS:

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only origin main && python3 scripts/deploy-acs-router-report.py plan
```

O esperado é `PLAN_OK`, com: novo relatório, helper, CSS, alteração
**somente da linha que importa o estilo** em `view-logs.php` e atualização
do JS de roteadores. Se o layout do relatório personalizado não tiver
o include de header esperado, o instalador recusa, sem tocar nos arquivos.

Aplicar somente depois de `PLAN_OK`:

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-acs-router-report.py apply
```

Conferir `APLICADO_OK; backup=/var/backups/jrc-acs-router-report/...`.
Atualizar navegador com Ctrl+F5. Clicar em Roteadores e confirmar
listagem, busca, paginação, atualização dos contadores, e retorno
para relatórios. Depois abrir Sem leitura, Fraco e Crítico e conferir
que filtros e resultados originais continuam funcionando.

## Reversão

Os arquivos modificados preexistentes são apenas
`assets/js/jrc-router-signal.js` e `view-logs.php`. O instalador
salva cópia original de ambos no caminho impresso em `APLICADO_OK`.
Em caso de problema, restaurar esses dois arquivos do backup depois
de conferir o destino. Os arquivos novos não interferem na navegação
sem o script e poderão ficar até remoção controlada.

## O que não é alterado

Fontes IXC/TR-069, `api/get-onu-optical.php`, estatísticas de sinal,
firmware, reboot, tarefas de ONU, credenciais, dashboard.php,
footer.php, dispositivos, flags, cron, MCP ou configuração do coletor.

Os números de Roteadores (fonte GenieACS) e Sem leitura (fonte óptica)
não são somados/subtraídos entre si, pois ainda usam universos de
dados diferentes, aguardando homologação da base consolidada.
