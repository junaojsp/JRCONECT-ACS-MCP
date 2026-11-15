# Homologação de firmware em bancada — fase de pré-validação

Esta entrega não executa atualizações. Ela complementa a biblioteca da PR #167 com o vínculo explícito de uma ONU de bancada a uma imagem já cadastrada e com registros persistentes de pré-homologação.

## Funcionalidades
- Acesso pelo link **Homologação de bancada**, na página `/firmware-library.php`.
- Seleção de uma imagem cadastrada como `nao_homologado`.
- Registro do identificador GenieACS, serial, fabricante, modelo, revisão e versão atualmente instalada, informados pelo operador.
- Confirmação explícita de que o equipamento é de bancada.
- Bloqueio quando fabricante/modelo/hardware informado não corresponde aos metadados da imagem.
- Histórico persistente em `/var/lib/jrc-acs-firmware/bench-register.json`, com lock e escrita atômica.
- Botão de atualização propositalmente **desabilitado**.

## Limitações críticas
A validação de compatibilidade compara apenas os **dados declarados**, sem consulta ao vivo ao GenieACS. Portanto, o estado do vínculo é `aguardando_verificacao_acs`, nunca `homologado`. O cadastro da imagem, mesmo com SHA-256 calculado, não comprova assinatura/autenticidade de origem, identidade do conteúdo ou compatibilidade com hardware. O firmware RP2864 informado para a HG6143D3 pode ser da mesma versão instalada; esse dado ainda precisa ser validado contra o arquivo real.

Esta PR não altera o banco de dispositivos, não modifica firmware em produção, não chama NBI/FS/CWMP e não habilita atualização individual ou em massa. Não cria o card na Visão Geral.

## Instalação
Após merge, verificar primeiro:
```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only && python3 scripts/deploy-firmware-bench.py plan
```
Se `PLAN_OK`, aplicar:
```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-firmware-bench.py apply
```

O instalador não sobrescreve o catálogo, os binários ou os vínculos já existentes. Ele adiciona somente o link da nova página e cria um backup de `firmware-library.php`. Há validação de sintaxe PHP antes de alterar o arquivo em produção.

## Próxima etapa técnica
Antes de permitir o download de firmware para uma ONU, será necessário estudar os endpoints reais de `api/device-tools.php` e a implementação de `firmwareStart()` presentes na instalação de produção, além do fluxo GenieACS FS/NBI, ACL do usuário, confirmação humana, logs, limites de concorrência e validação posterior ao reboot. Não deduzir APIs ou identificadores de dispositivo a partir dos prints.
