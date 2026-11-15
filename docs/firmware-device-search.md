# Localização de ONU no firmware — busca no ACS

Esta entrega melhora a página de pré-homologação sem mudar o cadastro de firmware nem iniciar tarefas. Em `/firmware-homologation.php`, o operador pode pesquisar **por MAC, serial, ID ou modelo** e clicar **Selecionar ONU** para preencher os campos da bancada.

## Implementação

- `api/firmware-device-search.php`: endpoint PHP autenticado, GET somente, com token de sessão, validação do termo, consulta exclusiva ao GenieACS NBI local e projeção de campos. Consulta `/devices/` em modo leitura e retorna no máximo 40 resultados.
- Busca por MAC contempla os caminhos TR-069 mais comuns. Alguns fabricantes usam outros caminhos de MAC: ausência de resultado não significa que a ONU não existe. Priorizar serial quando necessário.
- Busca por modelo procura `_deviceId._ProductClass`, `Device.DeviceInfo.ModelName` e `InternetGatewayDevice.DeviceInfo.ModelName`.
- O formulário preserva conferência do operador e confirmação de bancada. Resultados não são atualização em massa; botões de atualização continuam desabilitados.
- ID, serial, fabricante, modelo, hardware e versão são preenchidos com dados **já coletados**; campos ausentes ficam vazios para conferência. Nenhum refresh TR-069 é solicitado.
- O endpoint usa `http://127.0.0.1:7557` como valor inicial e permite definir `JRC_FW_NBI_URL` apenas com host loopback e porta local. Não transforma a API NBI em acesso público.
- Se o NBI real do ACS não estiver em localhost:7557, a página retorna erro de conexão explicativo e precisa de configuração no PHP-FPM. A integração não foi testada contra o servidor de produção nesta PR.

## Implantação

Após merge:

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only && python3 scripts/deploy-firmware-device-search.py plan
```

Somente se `PLAN_OK`:

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-firmware-device-search.py apply
```

O instalador **não substitui** a tela personalizada: insere apenas blocos de busca e script no PHP existente, sem alterar a lógica de cadastro, histórico, biblioteca ou integração anterior. Cria backup de `firmware-homologation.php`, valida sintaxe PHP e cria o novo endpoint.

## Homologação visual

1. Abra Firmware > Homologação de bancada.
2. Pesquise pelo serial conhecido da FiberHome HG6143D3.
3. Confira ID, modelo, hardware e versão: valores ausentes são possíveis porque dependem da árvore realmente reportada ao ACS.
4. Selecione a ONU e confirme que os campos foram preenchidos corretamente.
5. Não inicie nenhuma atualização; a função permanece desabilitada.
6. Repita por modelo, confira a listagem e o limite de 40.

## Segurança e limitações

O GenieACS NBI é uma interface sensível e não deve ficar exposto à Internet. A busca só consulta dispositivos armazenados e não realiza comandos CWMP. A seleção não garante autenticidade do arquivo, compatibilidade do firmware nem elegibilidade para atualização. A regra de marcação de bancada ainda deve ser revisada contra o inventário real do ACS antes de permitir atualização.

A busca é limitada por termo e resultados. Em redes grandes, refine pelo modelo completo ou serial. O card da Visão Geral e a atualização em massa não fazem parte desta PR.
