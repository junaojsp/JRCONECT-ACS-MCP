# Piloto SNMP no coletor ACS

Componente do JRC Collector, executado no host, com Python 3 e Net-SNMP já instalado.
Entrada opcional: `php collector/cli.php --snmp-pilot`. Também pode ser executado
com `python3 collector/snmp_pilot.py`. Não precisa do GenieACS ou IXC neste modo.
A imagem Docker shadow atual NÃO contém Python/Net-SNMP nem volume de saída;
este piloto deve ser executado no host, não dentro daquela imagem.

Consulta no máximo 10 índices explícitos, uma requisição SNMP GET por ONU,
2s de timeout SNMP, sem retry, limite externo de 3s por requisição.
Não faz descoberta da frota, SET, TL1 ou tarefas TR-069. Não altera o inventário,
classificações ou relatórios atuais. Só solicita colunas ópticas do ramo
`1.3.6.1.4.1.5875.800.3.9.3.3.1`.

O padrão é a amostra 11/4/2, 11/4/3 e 12/1/2 pelos índices observados na OLT:
371196416, 371196672 e 403177984. Cada consulta confere o campo de posição
retornado, mas ainda não realiza associação ao cadastro pelo serial.

## Configuração e execução no host

- `JRC_SNMP_HOST`: IPv4 da OLT; padrão 172.30.50.2.
- `JRC_SNMP_PILOT_INDICES`: até 10 índices distintos separados por vírgula.
- `JRC_SNMP_COMMUNITY_FILE`: arquivo privado, padrão
  `/run/jrc-collector-secrets/snmp_community` (0600). Nunca colocar o segredo no git.

Executar como usuário com acesso ao segredo e a `/var/lib/jrc-collector`.
O segredo é passado por um snmp.conf temporário 0600 em diretório privado,
sem community na linha de comando. O arquivo é removido ao terminar.

Saídas atômicas 0600:
- `/var/lib/jrc-collector/snmp-pilot.json`: amostra com valores brutos, OIDs,
  conversões candidatas e duração por ONU.
- `/var/lib/jrc-collector/snmp-pilot-status.json`: resultado da última tentativa.

Falha total preserva a última amostra e atualiza só o status da tentativa.
Consumidores precisam conferir AMBOS os horários. Falha parcial gera uma amostra
parcial explícita; não mistura valores antigos nos campos que falharam.
Bloqueio de arquivo impede execuções simultâneas. Nenhum agendamento é instalado.

## Validação obrigatória antes de usar nas telas

Mapa candidato AN5116/5516: RX coluna 6, TX 7, voltagem 8, corrente 9 e temperatura
10; divisores 100. Referência:
https://pdfcoffee.com/fiberhome-gepon-5116-5516-mib-open-interface-specifications-pdf-free.html
(ONU Uplink Interface Optical Power). Outras séries/firmwares podem divergir.
Comparar todos os campos com a MESMA ONU no UNM2000 antes de homologar.

`candidate_value` não é uma medição homologada; `validated` fica falso.
Zeros, sentinelas e valores fora das faixas conservadoras do piloto não são
classificados como normais. O bruto é preservado. As faixas NÃO são limites de
alarme. Um valor rejeitado requer investigação e não prova defeito na ONU.

`polled_at_epoch` é a hora da resposta SNMP, NÃO a data da medição óptica da OLT.
`measured_at_epoch` permanece nulo porque a idade real da amostra não foi comprovada.
Não há estado online/offline inferido de RX zero. Não há agregação de frota ou
alegação de cobertura além das ONUs da amostra.

Após comparar o resultado com UNM e medir o tempo de execução, planejar o
agendamento no coletor existente e o vínculo por serial. Só então habilitar a
fonte no painel, com regras explícitas para frescor e indisponibilidade.
