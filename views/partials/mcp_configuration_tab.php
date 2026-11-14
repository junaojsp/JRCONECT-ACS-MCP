<?php
// Aba informativa. Nao expor credenciais nem oferecer controle do Docker no PHP.
?>
<div class="tab-pane fade" id="mcp-config" role="tabpanel" aria-labelledby="mcp-tab">
    <div class="card mt-3">
        <div class="card-header">
            <i class="bi bi-diagram-3"></i> Servidor MCP - JR Conect
            <span class="badge bg-secondary float-end">Nao verificado</span>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                O servidor MCP ainda nao foi validado em Docker. A integracao com o Opa Suite nao esta configurada nesta tela.
            </div>
            <div class="mb-3">
                <label class="form-label">Container planejado</label>
                <input class="form-control" readonly value="jrconect-mcp">
            </div>
            <div class="mb-3">
                <label class="form-label">Acesso local planejado</label>
                <input class="form-control" readonly value="127.0.0.1:3100">
            </div>
            <div class="mb-3">
                <label class="form-label">Endereco HTTPS proposto (nao publicado)</label>
                <input class="form-control" readonly value="https://mcp.jrconect.com/mcp">
            </div>
            <p class="text-muted mb-0">
                Teste de conexao, logs e controles administrativos serao adicionados apos implementar autenticacao e autorizacao no backend.
            </p>
        </div>
    </div>
</div>
