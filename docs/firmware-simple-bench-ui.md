# Firmware — fluxo simplificado de bancada

## Objetivo

Transformar a tela atual de pré-homologação em três passos simples, sem copiar ID de dispositivo:

1. **Buscar ONU:** por MAC, serial, ID ou modelo usando a API de busca já homologada.
2. **Selecionar firmware:** após clicar na ONU, o sistema preenche internamente os campos de identificação e mostra somente imagens cadastradas com fabricante, modelo e revisão de hardware correspondentes. Quando há apenas uma imagem correspondente, a seleção é automática.
3. **Preparar teste:** operador confirma que a ONU é de bancada e registra o vínculo existente. **Não envia a imagem para a ONU.**

## Segurança e limites

- A atualização de firmware continua desabilitada nesta fase. Não há NBI POST, CWMP Download, GenieACS FS upload nem atualização em massa.
- O status de pré-homologação permanece `aguardando_verificacao_acs`. Correspondência de metadados **não comprova** assinatura, autenticidade ou compatibilidade final do arquivo.
- O backend PHP de gravação de vínculos e os registros persistentes não são alterados. Campos manuais são substituídos por campos ocultos preenchidos pelos dados da consulta ao ACS.
- Se modelo, hardware ou versão não estiverem disponíveis no inventário, o botão de preparar teste fica indisponível. O backend continua rejeitando dados incompatíveis.
- Imagem já cadastrada da FiberHome HG6143D3 / WKE2.094.423A01 / RP2864 é indicada para essa ONU apenas como correspondência cadastral, ainda não validada para reinstalação.
- A confirmação de posse física da ONU de bancada permanece obrigatória para registro de teste.

## Implantação

Depois do merge:

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only && python3 scripts/deploy-firmware-simple-ui.py plan
```

Somente após `PLAN_OK`:

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-firmware-simple-ui.py apply
```

O instalador reconhece a tela da PR #169, substitui só o painel visual e script de busca, acrescenta CSS isolado e mantém a lógica PHP de backend, o catálogo e os dados. Faz backup e valida `php -l` antes da aplicação; aborta se encontrar estrutura inesperada.

## Teste na interface

- Acesse Firmware → Homologação de bancada.
- Busque `FHTT99F5A9D0` e selecione a FiberHome HG6143D3.
- Confira serial, hardware e RP2864 exibidos.
- Veja a imagem compatível automaticamente selecionada (se for a única).
- Confirme a ONU de bancada e clique **Preparar teste (sem atualizar)**.
- Confira o registro no histórico recolhível.
- Verifique que **Atualizar ONU** segue desabilitado.

A melhoria visual não implementa envio de firmware: a próxima etapa é validar a rotina real de distribuição e o comportamento pós-reboot da ONU de bancada.
