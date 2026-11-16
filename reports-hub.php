<?php
declare(strict_types=1);

/**
 * Central separada de Consultas e Relatorios JR CONECT.
 *
 * No IXC, TR-069, GenieACS, or filesystem requests are triggered here.
 * Operators explicitly choose a live diagnostic or a cached report.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'Relatórios e Consultas';
$currentPage = 'logs';
include __DIR__ . '/views/layouts/header.php';
?>

<style>
.jrc-reports-home {
    --jrc-reports-surface: #0c2035;
    --jrc-reports-border: #244763;
    --jrc-reports-soft: #9db8cf;
    --jrc-reports-ink: #e7f1f8;
    color: var(--jrc-reports-ink);
}
.jrc-reports-home .jrc-reports-lead {
    margin: 0 0 22px;
    color: var(--jrc-reports-soft);
}
.jrc-reports-home .jrc-reports-section { margin: 0 0 28px; }
.jrc-reports-home .jrc-reports-section h2 {
    font-size: 19px; font-weight: 700; margin: 0 0 6px;
}
.jrc-reports-home .jrc-reports-section > p {
    margin: 0 0 14px; color: var(--jrc-reports-soft);
}
.jrc-reports-home .jrc-reports-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
    gap: 14px;
}
.jrc-reports-home .jrc-reports-card {
    border: 1px solid var(--jrc-reports-border);
    background: var(--jrc-reports-surface);
    border-radius: 10px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.jrc-reports-home .jrc-reports-card h3 {
    margin: 0; font-size: 17px; font-weight: 700;
    color: var(--jrc-reports-ink);
}
.jrc-reports-home .jrc-reports-card p {
    margin: 0; line-height: 1.55;
    color: var(--jrc-reports-soft);
}
.jrc-reports-home .jrc-reports-note {
    border-left: 3px solid #d99b30;
    background: #12283e;
    padding: 12px 16px;
    border-radius: 4px 9px 9px 4px;
    color: #d9e6f1;
    line-height: 1.5;
}
.jrc-reports-home .jrc-reports-label {
    font-size: 12px;
    color: var(--jrc-reports-soft);
    letter-spacing: 0.025em;
}
.jrc-reports-home .jrc-reports-action {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    align-self: flex-start;
    text-decoration: none;
    background: #1968b9;
    color: white;
    border: 1px solid #287acb;
    border-radius: 6px;
    padding: 9px 15px;
    font-weight: 650;
    margin-top: auto;
}
.jrc-reports-home .jrc-reports-action:hover,
.jrc-reports-home .jrc-reports-action:focus-visible {
    background: #2c82d4;
    color: white;
}
.jrc-reports-home .jrc-reports-action:focus-visible {
    outline: 2px solid #9ad0ff;
    outline-offset: 3px;
}
</style>

<main class="jrc-reports-home">
    <h1 style="font-size: 24px; font-weight: 750; margin: 0 0 10px;">
        Relatórios e Consultas
    </h1>
    <p class="jrc-reports-lead">
        Escolha entre consultar a rede e abrir os dados já coletados.
        Esta página não inicia nenhuma consulta ao IXC ou TR-069.
    </p>

    <section class="jrc-reports-section" aria-labelledby="jrc-consultas-title">
        <h2 id="jrc-consultas-title">Consultas de diagnóstico</h2>
        <p>Operações sob demanda. Podem levar mais tempo e depender de integrações externas.</p>
        <div class="jrc-reports-grid">
            <article class="jrc-reports-card">
                <div class="jrc-reports-label">CONSULTA · IXC + TR-069</div>
                <h3>Consulta de sinal da frota</h3>
                <p>
                    Sinal fraco, sinal crítico, sem leitura e ONUs offline há mais de 60 dias.
                    Os dados podem ser obtidos da leitura mais recente ou de cache temporário.
                </p>
                <a class="jrc-reports-action" href="/view-logs.php">
                    Abrir consulta de sinal
                </a>
            </article>
        </div>
        <p class="jrc-reports-note" role="note" style="margin-top: 12px;">
            <strong>Sobre resultados parciais:</strong>
            se a consulta informar falha ou demora do IXC, valores zerados durante a
            leitura não significam que a frota está sem problemas. Aguarde a conclusão
            ou repita a consulta antes de tomar decisões.
        </p>
    </section>

    <section class="jrc-reports-section" aria-labelledby="jrc-relatorios-title">
        <h2 id="jrc-relatorios-title">Relatórios de dados coletados</h2>
        <p>Consultam informações já armazenadas no ACS, sem varrer a frota quando a tela é aberta.</p>
        <div class="jrc-reports-grid">
            <article class="jrc-reports-card">
                <div class="jrc-reports-label">RELATÓRIO · INVENTÁRIO ACS</div>
                <h3>Roteadores</h3>
                <p>
                    Equipamentos identificados como roteadores comuns, clientes PPPoE,
                    modelos, seriais, IP WAN, busca e paginação.
                </p>
                <a class="jrc-reports-action" href="/router-report.php">
                    Visualizar roteadores
                </a>
            </article>
        </div>
    </section>
</main>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
