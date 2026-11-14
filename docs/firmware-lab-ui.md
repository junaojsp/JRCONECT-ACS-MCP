# Firmware Lab UI — primeira etapa

## Objetivo
Usar a entrada Firmware do menu existente em producao para abrir uma nova tela de Catalogo, Atualizacoes e Historico, sem duplicar o menu nem iniciar atualizacoes de equipamentos.

## Entrega
- `firmware-manager.php`: interface responsiva com abas, estados vazios verdadeiros e aviso de bancada.
- `scripts/deploy-firmware-lab-ui.py`: inspecciona a entrada Firmware real do header local, planeja mudanca ou aplica com backup e sintaxe PHP validada.
- A rota antiga de Firmware permanece disponivel em um link dentro da nova tela.
- Nao faz upload, nao dispara tasks TR-069, nao escreve no banco, nao altera MCP nem API.

## Como homologar
1. Fazer merge desta PR somente apos revisar os arquivos.
2. Obter checkout do commit aprovado em pasta separada (nao sobrescrever checkout de producao).
3. Executar `python3 scripts/deploy-firmware-lab-ui.py plan` dessa copia. O script aborta caso o item Firmware ou a rota anterior nao correspondam ao formato esperado.
4. Somente apos `PLAN_OK`, executar `sudo python3 scripts/deploy-firmware-lab-ui.py apply`.
5. Abrir Firmware no ACS e validar Catálogo, Atualizações e Histórico, responsividade e link para a tela anterior.
6. Para desfazer: restaurar o backup indicado e remover somente a pagina `firmware-manager.php`, apos conferir a ausencia de mudancas concorrentes.

## Proxima fase (nao incluida)
Definir um equipamento real de bancada (serial/modelo/revisao de hardware/firmware atual) e uma imagem oficial correspondente com checksum e aprovacao de compatibilidade. Depois, criar catalogo persistente e tarefas controladas em PR separada. Nunca executar atualização de firmware sem homologacao e plano de recuperacao.
