# ONU Offline vs Online sem RX — contrato de evidência

Objetivo: nunca chamar de "offline" uma ONU apenas por não ter potência RX
ou por ter um último Inform TR-069 antigo.

## Regras
- `offline_confirmado`: serial exato encontrado na coleta SNMP da OLT
  com estado `offline`, `sem_energia` ou `sem_sinal`; snapshot inteiro,
  última tentativa bem-sucedida e campo de estado com até 15 minutos.
- `online_sem_rx`: OLT confirma `online`, mas o inventário não possui
  RX numérico com timestamp de medição recente. Indicar fabricante/modelo,
  firmware, `rx_issue` e revisar parâmetros TR-069/firmware/virtual parameters
  por modelo. NÃO disparar refresh ou alterar firmware automaticamente.
- `online_com_rx`: OLT online e RX válido com timestamp recente.
- `nao_confirmado`: OLT sem snapshot confiável, serial não casado ou
  estado desconhecido. Um Inform recente ou antigo só é contexto, não
  comprova online/offline na rede óptica.

`Offline +60 dias` é classificação comercial distinta, mantida no IXC
conforme sua regra original. O status SNMP da OLT não deve alimentá-la.

## Endpoint
`POST /api/jrc-onu-connectivity.php` autenticado, JSON:
`{"serials":["FHTT1234ABCD"]}`. Devolve mapa de estados,
`inventory_at` e motivo. Usa somente o inventário MySQL existente
e snapshots locais de SNMP; sem requisições NBI, IXC ou tasks CPE.
Recusa inventário antigo, duplicado, serial ambíguo ou entrada excessiva.

## Segurança / implantação
Sem alterar o relatório PHP personalizado de produção.
A API e a biblioteca são arquivos novos, instalação opt-in; não ativam
SNMP nem substituem leitura RX atual. O relatório deve passar a exibir
um grupo `Offline confirmado` separado do `Online sem RX`, e
manter `Não confirmado` visível. Evitar renomear em massa `Sem leitura`.

Antes de considerar o resultado final, confrontar com a interface UNM
e verificar se `snmp-fleet.json` contempla as mesmas OLTs que atendem
os assinantes (o coletor de frota atual tem perfil AN5516).

Testes: `php tests/jrc-onu-connectivity.test.php`.
