# Conteúdo de referência: CPU/RAM, WAN, LAN e aparelhos

Continuação das PRs #257/#258 após comparação com as capturas reais de 17/11/2026.

- Corrige o escopo do tema: usa ID estável no container em vez de depender de `body.jrc-live-device-v6`. O CSS anterior deixava de alcançar ambientes com outra classe live ativa.
- Substitui o renderizador WAN: IP/gateway, usuário PPPoE, DNS/IPv6, ações existentes. Informações técnicas e gráficos operacionais ficam em detalhes recolhidos; não remove IDs de telemetria nem inventa qualidade.
- Substitui a composição LAN: campos da rede local em grid, edição DHCP original, desenho das portas e tabela compacta. Exibe somente portas coletadas. O gateway não é apresentado como se fosse outro endereço IP medido.
- Renderizador de aparelhos com avatar, hostname, fabricante, IP, MAC, conexão e estado; logos locais Simple Icons quando `vendor/manufacturer` identifica a marca. Sem atribuir fabricante por hostname. Sem marca conhecida, usa ícone de tipo ou computador genérico. Hotspot mantém seus seletores/células e tem status em detalhes recolhidos.
- API: adiciona normalizador isolado dos parâmetros já armazenados no GenieACS. CPU em DeviceInfo.ProcessStatus.CPUUsage; RAM usada calculada de MemoryStatus.Total/Free na mesma unidade. Aceita as raízes Device e InternetGatewayDevice e valores zero. Não lança tarefas no equipamento, não altera configuração ou coleta e não transforma campo ausente em zero. Referência: https://cwmp-data-models.broadband-forum.org/tr-157-1-2-0-igd.html

Se o HG6143D3 não tiver esses parâmetros na leitura atual, o painel continuará sem percentual; isso exige verificar os parâmetros reais/atualizar a coleta, não fabricar um valor no layout. Não foi confirmada disponibilidade desses parâmetros no CPE nesta sessão.

## Implantação

```bash
cd /opt/jrc-acs-snmp-source
git checkout main
git pull --ff-only origin main
python3 scripts/deploy-acs-device-reference-content.py plan
python3 scripts/deploy-acs-device-reference-content.py apply
```

Requer #257/#258 instaladas. O instalador verifica renderizadores exatos e a âncora da API instalada; valida JS e PHP antes de escrever, usa backup/rollback e preserva permissões. Interrompe se a API/JS em produção divergirem, sem substituir arquivos completos pela versão base do repositório.

## Validação

`node --test tests/device-reference/content.test.cjs` cobre seleção de logos, marca desconhecida, escape, seletores de tráfego, estados offline/desconhecido, portas reais, ações WAN e gráficos recolhidos. Aplicação sobre fixture de produção, reaplicação e recusa de fonte divergente testadas. Sintaxe PHP analisada por php-parser; execução PHP e dados reais do CPE ainda dependem do servidor. O instalador exige `php -l` real. CSS analisado por tinycss2. Não houve aprovação visual no navegador nesta sessão; conferir com Ctrl+F5 após implantação.
