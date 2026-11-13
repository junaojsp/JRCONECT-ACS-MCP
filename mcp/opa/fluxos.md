# Fluxos - JR Conect (PROPOSTA NAO PUBLICADA)

## Comercial
Entrada > verificar cidade > se Francisco Morato/Franco da Rocha, coletar dados obrigatorios > validar CEP e campos > apresentar resumo para confirmacao > criar lead no IXC por API autorizada > confirmar retorno > notificar equipe comercial por canal interno autorizado. Se cidade fora da cobertura, encerrar cordialmente sem criar lead.

## Financeiro
Entrada > identificar intencao (segunda via, Pix, boleto, comprovante ou atendente) > validar identidade conforme politicas vigentes > consultar informacoes no IXC/Opa quando integrado > retornar informacoes minimas. Transferencia humana so durante horario e quando a fila estiver habilitada. Fora do horario, seguir autoatendimento.

## Atendimento geral
Menu principal > central/app/webchat/Telegram quando apropriado > abrir chamado e consultar protocolo somente se a integracao retornar sucesso. Mensagens padrao nao devem afirmar que a acao foi realizada sem comprovacao tecnica.

## Testes antes de publicar
1. Cidade atendida e nao atendida.
2. Nome/rua/bairro/CEP ausentes.
3. Cadastro com dados invalidos.
4. Dentro e fora de horario.
5. API indisponivel, erro 401/403/429/5xx.
6. Consentimento, vazamento entre usuarios, acesso a dados de terceiros.
7. Duplicidade de lead e tentativas repetidas.
8. Rollback documentado.

**Dependencia:** obter export e especificacao de fluxo do Opa antes de converter esses textos em alteracoes reais.
