# Padronização visual do módulo Firmware (JR Conect ACS)

## Problema
As novas páginas Firmware utilizam estilos próprios, mas o ACS de produção possui CSS global e rodapé compartilhados. No painel aparecem faixas quase pretas (sobretudo no rodapé), cartões com contraste inconsistente e diferenças visuais quando comparados ao menu MCP Server.

## Solução
Criar **um único stylesheet de tema compartilhado**: `assets/css/firmware-shared-theme.css`.

Ele atua exclusivamente nas páginas que contêm `.jrc-fw`, `.jrc-fw-lib` ou `.jrc-fwb`: Gerenciamento legado, Repositório Central e Homologação. Aplique a paleta oficial azul-marinho do ACS, cartões azuis, bordas e tabelas coerentes. A faixa do rodapé compartilhado fica **oculta somente nessas três telas**, sem editar `footer.php` nem `style.css` global. As áreas em branco recebem o mesmo fundo do painel. Nenhum cadastro, tarefa de firmware ou API é alterado.

**Esta abordagem é intencionalmente uma camada visual, não uma alteração global no layout.** Evita mudanças em MCP Server, Visão Geral, dispositivos e outros módulos. O rodapé global continua sendo usado nas demais páginas.

## Instalação segura
Com PR aprovada e merge:

```bash
cd /tmp/jrc-acs-firmware-ui && git pull --ff-only && python3 scripts/deploy-firmware-shared-theme.py plan
```

Somente após `PLAN_OK`:

```bash
cd /tmp/jrc-acs-firmware-ui && sudo python3 scripts/deploy-firmware-shared-theme.py apply
```

O instalador cria o stylesheet e insere uma referência CSS em cada página após seu `</style>` existente. Não reescreve os controladores PHP nem sobrescreve o layout de produção. Salva backup de cada página, valida com `php -l` e faz rollback em erro.

Após `APLICADO_OK`, pressione Ctrl+F5 nas três telas de Firmware. Compare com MCP Server. Verifique especialmente ausência das faixas pretas, bordas, campos, botões e espaçamento. A instalação não permite executar firmware.

## Futuras páginas
Não criar novas cores isoladas. Toda nova interface do módulo Firmware deve usar a paleta e o stylesheet comum, com escopo limitado ao módulo, em vez de CSS duplicado e ajustes pontuais de rodapé. O layout base deve continuar no header/footer compartilhados, sem alteração global.

## Limitações
O ambiente de produção utiliza header/footer e CSS customizados não idênticos aos do GitHub. A PR foi preparada para implantação com `plan` e deve ser validada visualmente em produção após `apply`. Se algum elemento específico ainda gerar faixa preta, inspecionar o DOM e a regra computada antes de outra modificação.
