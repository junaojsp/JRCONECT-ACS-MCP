# Layout de detalhes do dispositivo — referência v2

Baseado no pacote `acs-device-layout-atual.tar.gz` enviado pelo operador em 17/11/2026. O JavaScript de produção difere do arquivo base no repositório; o instalador aplica fragmentos exatos à cópia instalada, sem substituir o arquivo inteiro.

## Resultado

- Navegação interna vertical em desktop; abas originais e alvos Bootstrap preservados. Menu global inalterado.
- WAN: quatro indicadores existentes, uma única seção, interfaces em largura total com campos em duas colunas e ações no topo. Tráfego e consumo existentes preservados.
- LAN: utiliza a antiga aba DHCP, com mesmo ID, edição original, indicadores disponíveis, portas Ethernet coletadas e tabela de status/velocidade/duplex/MAC. Não fabrica portas quando a leitura não existe.
- Dispositivos: inventário em cards responsivos, indicadores de online/Wi-Fi/Ethernet/total. Mantém a tabela semântica, `data-mac`, as células 5/7/8 e os seletores usados pelo enriquecimento e atualização de tráfego Hotspot.
- Wi-Fi: indicadores antes do título, SSID no título dos cards, detalhes das bandas, modal escuro e traduzido. Não adiciona configurações de canal/largura que o formulário atual não oferece.

Não replica pontuações de qualidade, agentes Mesh, DMZ, redirecionamentos ou dados IPv6 das imagens sem coleta correspondente. As referências orientam a organização e aparência; não representam novos recursos implementados.

## Implantação após merge

Requer layouts das PRs #254 e #255 instalados. Compatível com a #256 antes ou depois de sua instalação (o cabeçalho extra, quando existente, fica oculto; traduções são idempotentes).

```bash
cd /opt/jrc-acs-snmp-source
git checkout main
git pull --ff-only origin main
python3 scripts/deploy-acs-device-reference-complete.py plan
python3 scripts/deploy-acs-device-reference-complete.py apply
```

O instalador exige PHP e Node, valida JS/PHP antes de escrever, verifica as âncoras do pacote, cria backup, preserva permissões e desfaz os arquivos já alterados se uma escrita falhar. Não modifica endpoints, autenticação, dados ou comandos de provisionamento. Se a produção tiver mudado nos fragmentos envolvidos, interrompe.

Para restaurar manualmente, use o caminho exato exibido em `BACKUP:` e copie seus arquivos sobre `/var/www/gacs/`. O CSS novo pode permanecer sem efeito quando o footer original é restaurado.

## Validação

Sobre a cópia fornecida: sintaxe do JS completo pelo Node, renderizadores com dados presentes/ausentes, escape de nomes de hosts, estado `active='false'`, instalação repetida, recusa de código divergente e rollback com falha de escrita injetada. O runtime local não possui PHP; a validação `php -l` é obrigatória no instalador em produção. A renderização visual no navegador local ficou bloqueada pelo ambiente e ainda precisa ser conferida no ACS após o deploy.

Após Ctrl+F5, conferir abas WAN, LAN, Wi-Fi e Dispositivos; abrir e cancelar os modais; conferir atualização do Hotspot e auto-refresh. A edição Wi-Fi mantém o seletor WLAN e a API originais: escolha a interface correta antes de salvar. `APLICADO_OK` confirma instalação, não aprovação visual.
