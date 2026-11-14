<?php

require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

// Verificar se o Genie está configurado
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';

?>
<script src="/assets/js/jrc-tr069-apto.js?v=227a5dad58"></script>
<!-- JRC_TR069_APTO_V1 -->


<style>

/* ==========================================================
   JR CONECT DASHBOARD V2
   ========================================================== */

.jrc-dashboard {
    width: 100%;
}


/* ----------------------------------------------------------
   CABEÇALHO
   ---------------------------------------------------------- */

.jrc-dashboard-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 16px;
}

.jrc-dashboard-title h2 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

.jrc-dashboard-title p {
    margin: 4px 0 0;
    color: #708da0;
    font-size: 11px;
}

.jrc-live-status {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    padding: 7px 11px;

    border:
        1px solid
        rgba(32, 213, 155, .15);

    border-radius: 8px;

    background:
        rgba(32, 213, 155, .045);

    color: #60dfb5;

    font-size: 10px;
    font-weight: 700;

    letter-spacing: .04em;
}

.jrc-live-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: #20d59b;

    box-shadow:
        0 0 9px
        rgba(32, 213, 155, .65);
}


/* ----------------------------------------------------------
   GRID PRINCIPAL
   ---------------------------------------------------------- */

.jrc-dashboard-grid {
    display: grid;

    grid-template-columns:
        minmax(360px, 1.15fr)
        minmax(320px, .9fr)
        minmax(300px, .9fr);

    gap: 14px;

    align-items: stretch;

    margin-bottom: 14px;
}


/* ----------------------------------------------------------
   PAINÉIS
   ---------------------------------------------------------- */

.jrc-panel {
    position: relative;

    background:
        linear-gradient(
            145deg,
            rgba(11, 27, 41, .99),
            rgba(6, 17, 28, .99)
        );

    border:
        1px solid
        rgba(106, 159, 187, .13);

    border-radius: 13px;

    overflow: hidden;

    box-shadow:
        0 12px 36px
        rgba(0, 0, 0, .18);
}

.jrc-panel::before {
    content: "";

    position: absolute;

    top: 0;
    left: 0;
    right: 0;

    height: 1px;

    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(0, 189, 227, .45),
            transparent
        );
}

.jrc-panel-header {
    min-height: 60px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 13px 16px;

    border-bottom:
        1px solid
        rgba(106, 159, 187, .09);
}

.jrc-panel-title {
    display: flex;
    align-items: center;
    gap: 9px;
}

.jrc-panel-title i {
    color: #35c5e4;
    font-size: 14px;
}

.jrc-panel-title strong {
    display: block;

    color: #e8f5fa;

    font-size: 12px;
    font-weight: 700;
}

.jrc-panel-title small {
    display: block;

    margin-top: 2px;

    color: #648397;

    font-size: 9px;
}

.jrc-panel-body {
    padding: 18px;
}


/* ----------------------------------------------------------
   BOTÕES DOS PAINÉIS
   ---------------------------------------------------------- */

.jrc-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 6px 9px;

    border:
        1px solid
        rgba(0, 189, 227, .14);

    border-radius: 7px;

    background:
        rgba(0, 189, 227, .05);

    color: #6dd3ea;

    font-size: 10px;

    cursor: pointer;

    text-decoration: none;
}

.jrc-action:hover {
    color: #b6effa;

    background:
        rgba(0, 189, 227, .1);
}


/* ----------------------------------------------------------
   STATUS DOS DISPOSITIVOS
   ---------------------------------------------------------- */

.jrc-availability {
    padding: 2px 2px 20px;
}

.jrc-availability-top {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 20px;
}

.jrc-availability-value {
    font-size: 42px;
    font-weight: 700;

    line-height: 1;

    letter-spacing: -.04em;

    color: #effaff;
}

.jrc-availability-label {
    display: block;

    margin-top: 7px;

    color: #718da0;

    font-size: 10px;
}

.jrc-total-mini {
    text-align: right;
}

.jrc-total-mini strong {
    display: block;

    font-size: 22px;

    color: #dbeaf2;
}

.jrc-total-mini span {
    color: #648397;

    font-size: 9px;
}


/* barra */

.jrc-progress {
    width: 100%;
    height: 7px;

    margin-top: 18px;

    overflow: hidden;

    border-radius: 999px;

    background:
        rgba(115, 148, 167, .12);
}

.jrc-progress-bar {
    width: 0%;
    height: 100%;

    border-radius: inherit;

    background:
        linear-gradient(
            90deg,
            #00b5dc,
            #20d59b
        );

    box-shadow:
        0 0 13px
        rgba(32, 213, 155, .28);

    transition:
        width .5s ease;
}


/* ----------------------------------------------------------
   CARDS ONLINE / OFFLINE
   ---------------------------------------------------------- */

.jrc-status-grid {
    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 10px;

    margin-top: 12px;
}

.jrc-status-card {
    display: flex;
    align-items: center;

    gap: 11px;

    min-height: 72px;

    padding: 12px;

    border:
        1px solid
        rgba(105, 159, 187, .09);

    border-radius: 10px;

    background:
        rgba(255, 255, 255, .014);

    text-decoration: none;

    transition: .17s ease;
}

.jrc-status-card:hover {
    transform: translateY(-2px);

    border-color:
        rgba(0, 189, 227, .19);

    background:
        rgba(0, 189, 227, .035);
}

.jrc-status-icon {
    width: 35px;
    height: 35px;

    display: grid;
    place-items: center;

    flex-shrink: 0;

    border-radius: 9px;

    font-size: 15px;
}

.jrc-status-card.online .jrc-status-icon {
    color: #37dfa9;

    background:
        rgba(32, 213, 155, .08);
}

.jrc-status-card.offline .jrc-status-icon {
    color: #ff818d;

    background:
        rgba(240, 100, 114, .08);
}

.jrc-status-value {
    display: block;

    font-size: 22px;
    font-weight: 700;

    line-height: 1;

    color: #eaf5f9;
}

.jrc-status-name {
    display: block;

    margin-top: 6px;

    color: #728ea1;

    font-size: 9px;

    text-transform: uppercase;

    letter-spacing: .06em;
}


/* ----------------------------------------------------------
   GRÁFICOS
   ---------------------------------------------------------- */

.jrc-chart-box {
    position: relative;

    width: 100%;
    max-width: 245px;

    margin: 0 auto;
}

.jrc-chart-box canvas {
    width: 100% !important;
}


/* ----------------------------------------------------------
   RESUMO  / IA
   ---------------------------------------------------------- */

.jrc-ai-panel {
    border-color:
        rgba(161, 84, 255, .18);
}

.jrc-ai-panel::before {
    background:
        linear-gradient(
            90deg,
            transparent,
            rgba(174, 78, 255, .6),
            transparent
        );
}

.jrc-ai-icon {
    color: #ad6cff !important;
}

.jrc-ai-content {
    min-height: 232px;

    display: flex;
    flex-direction: column;

    justify-content: space-between;
}

.jrc-ai-intro {
    color: #8ba2b1;

    font-size: 11px;

    line-height: 1.7;
}

.jrc-ai-list {
    display: grid;
    gap: 9px;

    margin-top: 16px;
}

.jrc-ai-item {
    display: flex;
    align-items: center;

    gap: 9px;

    padding: 9px 10px;

    border:
        1px solid
        rgba(115, 148, 168, .08);

    border-radius: 8px;

    color: #9cb3c0;

    font-size: 10px;

    background:
        rgba(255, 255, 255, .012);
}

.jrc-ai-item i {
    color: #a871ff;
}

.jrc-ai-footer {
    margin-top: 17px;

    padding-top: 13px;

    border-top:
        1px solid
        rgba(115, 148, 168, .08);

    color: #657f91;

    font-size: 9px;
}


/* ----------------------------------------------------------
   LINHA SECUNDÁRIA
   ---------------------------------------------------------- */

.jrc-secondary-grid {
    display: grid;

    grid-template-columns:
        1fr 1fr;

    gap: 14px;

    margin-bottom: 14px;
}


/* ----------------------------------------------------------
   ATIVIDADE
   ---------------------------------------------------------- */

.jrc-recent-panel {
    margin-bottom: 0;
}

.jrc-recent-body {
    padding: 4px 15px 14px;
}

#recent-devices {
    min-height: 90px;
}


/* ----------------------------------------------------------
   TABELA V2
   ---------------------------------------------------------- */

#recent-devices .table-responsive {
    border-radius: 8px;

    overflow-x: auto;
}

#recent-devices .table {
    margin-bottom: 0;
}

#recent-devices .table thead th {
    background:
        rgba(5, 17, 27, .3);

    color: #647f91 !important;

    font-size: 8px !important;

    letter-spacing: .07em;
}

#recent-devices .table tbody td {
    font-size: 10px;

    color: #a9bfcc !important;
}

#recent-devices .table tbody tr:hover {
    background:
        rgba(0, 189, 227, .035) !important;
}


/* ----------------------------------------------------------
   RODAPÉ INFORMATIVO DO STATUS
   ---------------------------------------------------------- */

.jrc-status-footer {
    display: flex;
    align-items: center;

    gap: 16px;

    margin-top: 16px;

    padding-top: 13px;

    border-top:
        1px solid
        rgba(105, 159, 187, .08);

    color: #688596;

    font-size: 9px;
}

.jrc-status-footer span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.jrc-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;
}

.jrc-dot.green {
    background: #20d59b;

    box-shadow:
        0 0 7px
        rgba(32, 213, 155, .4);
}

.jrc-dot.red {
    background: #f06472;

    box-shadow:
        0 0 7px
        rgba(240, 100, 114, .4);
}


/* ----------------------------------------------------------
   RESPONSIVO
   ---------------------------------------------------------- */

@media (max-width: 1250px) {

    .jrc-dashboard-grid {
        grid-template-columns:
            1fr 1fr;
    }

    .jrc-ai-panel {
        grid-column:
            1 / -1;
    }

}

@media (max-width: 850px) {

    .jrc-dashboard-grid,
    .jrc-secondary-grid {
        grid-template-columns:
            1fr;
    }

}

@media (max-width: 560px) {

    .jrc-status-grid {
        grid-template-columns:
            1fr;
    }

    .jrc-dashboard-title {
        align-items: flex-start;
        flex-direction: column;
    }

}



/* ===== JR CONECT DASHBOARD MODERN V3 ===== */
.jrc-dashboard{
    --dash-bg:#101827;
    --dash-card:#182235;
    --dash-card-2:#1b2638;
    --dash-card-soft:#141f30;
    --dash-border:#2a3850;
    --dash-border-soft:#223148;
    --dash-text:#e7edf6;
    --dash-muted:#8592a8;
    --dash-cyan:#35cfe3;
    --dash-green:#27d39f;
    --dash-red:#ef6876;
    --dash-purple:#9f7aea;
    width:100%;
}

/* Cabeçalho como barra operacional */
.jrc-dashboard-title{
    min-height:72px;
    margin:0 0 14px;
    padding:14px 16px;
    border:1px solid var(--dash-border);
    border-radius:12px;
    background:linear-gradient(180deg,#182235,#151f30);
}
.jrc-dashboard-title h2{
    color:var(--dash-text);
    font-size:20px;
    letter-spacing:-.02em;
}
.jrc-dashboard-title p{
    color:var(--dash-muted);
    font-size:10px;
}
.jrc-live-status{
    color:#83f0c8;
    border-color:rgba(39,211,159,.32);
    background:rgba(39,211,159,.07);
    border-radius:999px;
    padding:7px 12px;
}

/* Grid principal mais equilibrado */
.jrc-dashboard-grid{
    grid-template-columns:1.08fr .92fr .92fr;
    gap:12px;
    margin-bottom:12px;
}
.jrc-secondary-grid{
    grid-template-columns:1fr 1fr;
    gap:12px;
    margin-bottom:12px;
}

/* Cards */
.jrc-panel{
    background:linear-gradient(180deg,var(--dash-card-2),var(--dash-card)) !important;
    border:1px solid var(--dash-border) !important;
    border-radius:12px;
    box-shadow:0 8px 20px rgba(0,0,0,.12);
}
.jrc-panel::before{
    height:2px;
    background:linear-gradient(90deg,transparent,rgba(53,207,227,.42),transparent);
}
.jrc-panel-header{
    min-height:52px;
    padding:11px 14px;
    border-bottom:1px solid var(--dash-border-soft);
}
.jrc-panel-title{
    gap:10px;
}
.jrc-panel-title i{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:8px;
    background:rgba(53,207,227,.07);
    color:var(--dash-cyan);
    font-size:13px;
}
.jrc-panel-title strong{
    color:var(--dash-text);
    font-size:11px;
}
.jrc-panel-title small{
    color:var(--dash-muted);
    font-size:8px;
}
.jrc-panel-body{
    padding:14px;
}

/* Botões discretos */
.jrc-action{
    min-height:30px;
    padding:0 10px;
    border-radius:8px;
    border:1px solid #2d4960;
    background:#142336;
    color:#a9d8e3;
    font-size:9px;
    font-weight:700;
}
.jrc-action:hover{
    background:#192a40;
    color:#eaf7fb;
    border-color:#3a6079;
}

/* KPI principal */
.jrc-availability{
    padding:4px 2px 16px;
}
.jrc-availability-value{
    font-size:46px;
    color:#f2f7fb;
}
.jrc-availability-label,
.jrc-total-mini span{
    color:var(--dash-muted);
}
.jrc-total-mini strong{
    color:#eaf1f7;
    font-size:24px;
}
.jrc-progress{
    height:8px;
    margin-top:16px;
    background:#111b2a;
    border:1px solid #223148;
}
.jrc-progress-bar{
    background:linear-gradient(90deg,#24bddd,#27d39f);
    box-shadow:none;
}

/* Online / offline */
.jrc-status-grid{
    gap:8px;
    margin-top:10px;
}
.jrc-status-card{
    min-height:68px;
    padding:11px 12px;
    border-color:var(--dash-border-soft);
    background:#151f30;
}
.jrc-status-card:hover{
    transform:none;
    background:#19263a;
    border-color:#33465f;
}
.jrc-status-icon{
    width:36px;
    height:36px;
    border-radius:9px;
}
.jrc-status-value{
    font-size:21px;
}
.jrc-status-name{
    font-size:8px;
}
.jrc-status-footer{
    margin-top:12px;
    padding-top:10px;
    border-top-color:var(--dash-border-soft);
    color:var(--dash-muted);
}

/* Gráficos mais compactos */
.jrc-chart-box{
    max-width:225px;
}
.jrc-dashboard-grid .jrc-chart-box{
    padding:4px 0;
}
.jrc-secondary-grid .jrc-chart-box{
    max-width:210px;
}

/* IA no mesmo padrão, sem roxo excessivo */
.jrc-ai-panel{
    border-color:var(--dash-border) !important;
}
.jrc-ai-panel::before{
    background:linear-gradient(90deg,transparent,rgba(159,122,234,.5),transparent);
}
.jrc-ai-icon{
    color:#ae91ef !important;
    background:rgba(159,122,234,.08) !important;
}
.jrc-ai-content{
    min-height:218px;
}
.jrc-ai-intro{
    color:#9aa8ba;
    font-size:10px;
    line-height:1.55;
}
.jrc-ai-list{
    gap:7px;
    margin-top:12px;
}
.jrc-ai-item{
    min-height:36px;
    padding:8px 10px;
    border:1px solid var(--dash-border-soft);
    background:#151f30;
    color:#aab7c7;
    font-size:9px;
}
.jrc-ai-item:hover{
    background:#19263a;
    border-color:#33465f;
}
.jrc-ai-item i{
    width:24px;
    color:#9f7aea;
}
.jrc-ai-footer{
    color:#6f7f94;
    border-top-color:var(--dash-border-soft);
}

/* Acessos rápidos */
.jrc-secondary-grid .jrc-panel:nth-child(2) .jrc-panel-body>div{
    gap:8px !important;
}
.jrc-secondary-grid .jrc-panel:nth-child(2) .jrc-ai-item{
    min-height:46px;
    border-radius:9px;
    font-size:10px;
}
.jrc-secondary-grid .jrc-panel:nth-child(2) .jrc-ai-item i{
    width:28px;
    height:28px;
    display:grid;
    place-items:center;
    border-radius:7px;
    background:rgba(53,207,227,.06);
    color:#66d7e8;
}

/* Atividade recente */
.jrc-recent-panel{
    border-radius:12px;
}
.jrc-recent-body{
    padding:0 12px 12px;
}
#recent-devices .table-responsive{
    border:1px solid var(--dash-border-soft);
    border-radius:9px;
    overflow:auto;
}
#recent-devices .table thead th{
    background:#141f30 !important;
    color:#8998ad !important;
    padding:10px 9px !important;
    font-size:8px !important;
}
#recent-devices .table tbody td{
    background:#182235 !important;
    border-color:#223148 !important;
    color:#c2ccd8 !important;
    padding:10px 9px !important;
    font-size:9px !important;
}
#recent-devices .table tbody tr:hover td{
    background:#1d2a3f !important;
}

/* Ajuste do espaço vertical geral */
.jrc-dashboard .spinner{
    width:32px;
    height:32px;
}
@media(max-width:1250px){
    .jrc-dashboard-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:850px){
    .jrc-dashboard-grid,.jrc-secondary-grid{grid-template-columns:1fr}
    .jrc-dashboard-title{align-items:flex-start;flex-direction:column}
}


/* =====  NOC DASHBOARD V4 ===== */
.acs-noc-head{
    display:flex;align-items:center;justify-content:space-between;gap:18px;
    padding:14px 16px;margin-bottom:12px;border:1px solid #2a3850;border-radius:12px;
    background:linear-gradient(180deg,#182235,#151f30);
}
.acs-noc-head-left{display:flex;align-items:center;gap:12px}
.acs-noc-head-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;background:#15364a;color:#35cfe3;font-size:19px}
.acs-noc-head h2{margin:0;color:#eef4f9;font-size:20px;font-weight:800}
.acs-noc-head p{margin:3px 0 0;color:#8592a8;font-size:10px}
.acs-noc-head-right{display:flex;align-items:center;gap:9px}

.acs-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.acs-kpi-card{
    min-height:104px;display:flex;align-items:center;gap:12px;padding:14px 15px;
    border:1px solid #2a3850;border-radius:11px;background:linear-gradient(180deg,#1b2638,#182235);
}
.acs-kpi-icon{width:43px;height:43px;display:grid;place-items:center;flex:0 0 auto;border-radius:10px;font-size:18px}
.acs-kpi-icon.cyan{background:rgba(53,207,227,.10);color:#35cfe3}
.acs-kpi-icon.green{background:rgba(39,211,159,.10);color:#27d39f}
.acs-kpi-icon.red{background:rgba(239,104,118,.10);color:#ef6876}
.acs-kpi-icon.blue{background:rgba(66,153,225,.10);color:#63b3ed}
.acs-kpi-card span{display:block;color:#aab5c4;font-size:9px;text-transform:uppercase;letter-spacing:.04em}
.acs-kpi-card strong{display:block;margin-top:3px;color:#f0f5fa;font-size:28px;line-height:1}
.acs-kpi-card small{display:block;margin-top:5px;color:#718096;font-size:8px}

.acs-noc-grid{
    display:grid;grid-template-columns:1.15fr .95fr;gap:12px;margin-bottom:12px;align-items:stretch;
}
.acs-network-body{display:grid;grid-template-columns:1.15fr .85fr;gap:12px;align-items:center;min-height:240px}
.acs-network-chart{display:flex;align-items:center;justify-content:center}
.acs-network-chart .jrc-chart-box{max-width:220px}
.acs-network-summary{display:flex;flex-direction:column;gap:7px}
.acs-network-summary-row{
    min-height:38px;display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:8px 10px;border:1px solid #223148;border-radius:8px;background:#151f30;
    color:#9caabd;font-size:9px;
}
.acs-network-summary-row span{display:flex;align-items:center;gap:7px}
.acs-network-summary-row span i{width:8px;height:8px;border-radius:50%;background:#718096}
.acs-network-summary-row.online span i{background:#27d39f}
.acs-network-summary-row.offline span i{background:#ef6876}
.acs-network-summary-row strong{color:#e7edf6;font-size:11px}
.acs-optical-chart{max-width:230px}
.acs-recent-modern{margin-bottom:0}

@media(max-width:1250px){
    .acs-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .acs-noc-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:760px){
    .acs-noc-head{align-items:flex-start;flex-direction:column}
    .acs-noc-head-right{width:100%;flex-wrap:wrap}
    .acs-kpi-grid,.acs-noc-grid{grid-template-columns:1fr}
    .acs-network-body{grid-template-columns:1fr}
}


/* =====  FINAL DASHBOARD V5 ===== */
.acs-final-head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 14px;margin-bottom:10px;border:1px solid #2a3850;border-radius:11px;background:linear-gradient(180deg,#182235,#151f30)}
.acs-final-brand{display:flex;align-items:center;gap:12px}.acs-final-head-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:10px;background:#15364a;color:#35cfe3;font-size:22px}.acs-final-brand h2{margin:0;color:#f0f5fa;font-size:20px;font-weight:800}.acs-final-brand p{margin:3px 0 0;color:#7f8da1;font-size:9px}
.acs-final-head-actions{display:flex;align-items:center;gap:9px}.acs-final-clock,.acs-final-operational,.acs-final-refresh{min-height:48px;border:1px solid #26384f;border-radius:9px;background:#142032}.acs-final-clock{display:flex;align-items:center;gap:10px;padding:7px 11px;color:#8fa0b5}.acs-final-clock>i{font-size:17px;color:#79dce9}.acs-final-clock span,.acs-final-refresh small{display:block;color:#7f8da1;font-size:8px}.acs-final-clock strong{display:block;color:#e8eef5;font-size:12px;margin-top:2px}
.acs-final-operational{display:flex;align-items:center;gap:9px;padding:7px 12px}.acs-final-operational>i{width:10px;height:10px;border-radius:50%;background:#27d39f;box-shadow:0 0 10px rgba(39,211,159,.35)}.acs-final-operational strong{display:block;color:#69ebba;font-size:10px}.acs-final-operational span{display:block;color:#709084;font-size:8px;margin-top:2px}.acs-final-refresh{display:flex;flex-direction:column;align-items:flex-start;justify-content:center;padding:6px 12px;color:#c5d2df}.acs-final-refresh>i{position:absolute;opacity:0}.acs-final-refresh span{font-size:10px;font-weight:700}.acs-final-refresh:hover{border-color:#35cfe3}

.acs-final-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px;margin-bottom:10px}.acs-final-kpi{min-height:104px;display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px solid #2a3850;border-radius:10px;background:linear-gradient(180deg,#1b2638,#182235)}.acs-final-kpi-icon{width:39px;height:39px;display:grid;place-items:center;flex:0 0 auto;border-radius:9px;font-size:18px}.acs-final-kpi-icon.cyan{background:rgba(53,207,227,.10);color:#35cfe3}.acs-final-kpi-icon.green{background:rgba(39,211,159,.10);color:#27d39f}.acs-final-kpi-icon.red{background:rgba(239,104,118,.10);color:#ef6876}.acs-final-kpi-icon.amber{background:rgba(214,173,56,.11);color:#e1b949}.acs-final-kpi-icon.slate{background:rgba(120,145,170,.11);color:#9fb1c4}.acs-final-kpi span{display:block;color:#a9b4c3;font-size:9px}.acs-final-kpi strong{display:block;margin-top:7px;color:#f0f5fa;font-size:25px;line-height:1}.acs-final-kpi small{display:block;margin-top:6px;color:#718096;font-size:8px}

.acs-final-main-grid{display:grid;grid-template-columns:1.05fr .92fr .82fr;gap:10px;margin-bottom:10px}.acs-final-card{border:1px solid #2a3850;border-radius:10px;background:linear-gradient(180deg,#1b2638,#182235);overflow:hidden}.acs-final-card-head{min-height:48px;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 12px;border-bottom:1px solid #223148}.acs-final-card-head>div{display:flex;align-items:center;gap:9px}.acs-final-card-head>div>i{width:28px;height:28px;display:grid;place-items:center;border-radius:7px;background:rgba(53,207,227,.06);color:#35cfe3}.acs-final-card-head strong{display:block;color:#e7edf6;font-size:11px}.acs-final-card-head small{display:block;margin-top:2px;color:#748297;font-size:8px}.acs-final-card-head a{color:#70dbe8;font-size:9px;text-decoration:none}.acs-final-card-head em{font-style:normal;color:#6ce8bb;font-size:8px;border:1px solid rgba(39,211,159,.25);border-radius:999px;padding:4px 7px}

.acs-final-status-body{min-height:242px;display:grid;grid-template-columns:1.05fr .95fr;gap:8px;align-items:center;padding:12px}.acs-final-donut-wrap{position:relative;max-width:225px;margin:0 auto}.acs-final-donut-center{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;pointer-events:none}.acs-final-donut-center strong{color:#f0f5fa;font-size:21px}.acs-final-donut-center span{color:#8c9bae;font-size:8px}.acs-final-status-list{display:flex;flex-direction:column;gap:7px}.acs-final-status-list>div{display:grid;grid-template-columns:1fr 55px 42px;align-items:center;gap:8px;color:#a8b5c5;font-size:9px}.acs-final-status-list span{display:flex;align-items:center;gap:7px}.acs-final-status-list i{width:12px;height:12px;border-radius:4px;background:#718096}.acs-final-status-list i.green{background:#27d39f}.acs-final-status-list i.red{background:#ef6876}.acs-final-status-list i.blue{background:#8aa0b8}.acs-final-status-list i.pink{background:#ee6f99}.acs-final-status-list strong{color:#e7edf6;text-align:right}.acs-final-status-list b{color:#9fb0c2;font-weight:500;text-align:right}

.acs-manufacturer-bars{padding:14px 14px 13px;display:flex;flex-direction:column;gap:10px}.acs-mfr-row{display:grid;grid-template-columns:80px 1fr 50px 42px;gap:9px;align-items:center;font-size:9px}.acs-mfr-row>span{color:#c3ceda;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.acs-mfr-track{height:10px;border-radius:999px;background:#111c2b;overflow:hidden}.acs-mfr-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#35cfe3,#397af6)}.acs-mfr-row strong{color:#dbe4ed;text-align:right}.acs-mfr-row b{color:#8493a7;font-weight:500;text-align:right}

.acs-final-right-stack{display:grid;grid-template-rows:1fr 1fr;gap:10px}.acs-final-alert-list{padding:8px 12px}.acs-final-alert-item{min-height:29px;display:grid;grid-template-columns:10px 1fr auto;align-items:center;gap:7px;border-bottom:1px solid #223148;color:#bcc7d4;font-size:8px}.acs-final-alert-item:last-child{border-bottom:0}.acs-final-alert-item i{width:8px;height:8px;border-radius:50%;background:#d6ad38}.acs-final-alert-item.critical i{background:#ef6876}.acs-final-alert-item.offline i{background:#ef6876}.acs-final-alert-item.good i{background:#27d39f}.acs-final-alert-item time{color:#748297}.acs-final-empty{padding:18px;color:#78879a;font-size:9px;text-align:center}
.acs-final-service-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:9px 10px 11px}.acs-final-service-grid>div{min-height:43px;display:flex;align-items:center;gap:8px;padding:7px;border:1px solid #223148;border-radius:7px;background:#151f30}.acs-final-service-grid>div>i{width:8px;height:8px;border-radius:50%;background:#27d39f}.acs-final-service-grid>div>i.neutral{background:#35cfe3}.acs-final-service-grid strong{display:block;color:#d9e2ec;font-size:8px}.acs-final-service-grid small{display:block;color:#728096;font-size:7px;margin-top:2px}

.acs-final-recent{margin-bottom:0}.acs-final-recent #recent-devices{padding:0 10px 10px}.acs-final-recent .table-responsive{border:1px solid #223148;border-radius:7px;overflow:auto}.acs-final-recent .table{margin:0}.acs-final-recent .table thead th{background:#141f30!important;color:#8898ad!important;font-size:7px!important;padding:8px!important}.acs-final-recent .table tbody td{background:#182235!important;color:#c2ccd8!important;border-color:#223148!important;font-size:8px!important;padding:8px!important}.acs-final-recent .table tbody tr:hover td{background:#1d2a3f!important}

@media(max-width:1400px){.acs-final-kpis{grid-template-columns:repeat(3,1fr)}.acs-final-main-grid{grid-template-columns:1fr 1fr}.acs-final-right-stack{grid-column:1/-1;grid-template-columns:1fr 1fr;grid-template-rows:auto}}
@media(max-width:900px){.acs-final-head{align-items:flex-start;flex-direction:column}.acs-final-head-actions{flex-wrap:wrap}.acs-final-kpis{grid-template-columns:repeat(2,1fr)}.acs-final-main-grid{grid-template-columns:1fr}.acs-final-right-stack{grid-column:auto;grid-template-columns:1fr}.acs-final-status-body{grid-template-columns:1fr}}
@media(max-width:560px){.acs-final-kpis{grid-template-columns:1fr}.acs-final-head-actions{flex-direction:column;align-items:stretch;width:100%}}


/* ===== JR REF DASHBOARD V6 ===== */
.jr-ref-shell{width:100%;color:#e7edf6}
.jr-ref-top{min-height:58px;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 2px 12px;margin-bottom:10px;border-bottom:1px solid #233149}
.jr-ref-brand{display:flex;align-items:center;gap:10px}.jr-ref-brand-icon{width:38px;height:38px;display:grid;place-items:center;border-radius:9px;background:#123249;color:#35cfe3;font-size:18px}.jr-ref-brand strong{display:block;font-size:14px;color:#eef4f9}.jr-ref-brand span{display:block;margin-top:2px;font-size:8px;color:#7e8da2}
.jr-ref-search{width:min(360px,38vw);height:38px;display:flex;align-items:center;gap:8px;padding:0 11px;border:1px solid #263850;border-radius:8px;background:#111c2b}.jr-ref-search i{color:#718299}.jr-ref-search input{width:100%;border:0!important;background:transparent!important;box-shadow:none!important;padding:0!important;color:#dce6ef!important;font-size:9px!important}

.jr-ref-grid{display:grid;grid-template-columns:1.02fr 1.18fr .92fr;gap:10px;margin-bottom:10px}.jr-ref-card{border:1px solid #2a3850;border-radius:10px;background:linear-gradient(180deg,#172337,#121d2d);overflow:hidden;box-shadow:0 8px 18px rgba(0,0,0,.1)}.jr-ref-card-head{min-height:44px;display:flex;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid #223148}.jr-ref-card-head>i{width:28px;height:28px;display:grid;place-items:center;border-radius:7px;background:#123249;color:#35cfe3}.jr-ref-card-head strong{font-size:11px;color:#e7edf6}.jr-ref-card-head.purple{color:#d55cff}.jr-ref-card-head.purple>i{background:rgba(209,74,255,.08);color:#d95cff}.jr-ref-card-head.purple strong{color:#e255ff}.jr-ref-card-head .beta{margin-left:auto;color:#d66aff;font-size:8px}.jr-ref-pills{margin-left:auto;display:flex;border:1px solid #263850;border-radius:7px;overflow:hidden}.jr-ref-pills span{padding:5px 11px;color:#7f90a6;font-size:8px}.jr-ref-pills span.active{background:#12364b;color:#d7f7ff}

.jr-ref-status{min-height:270px}.jr-ref-status-numbers{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:20px 18px 10px;text-align:center}.jr-ref-status-numbers strong{display:block;color:#f0f6fb;font-size:29px;line-height:1}.jr-ref-status-numbers span{display:block;margin-top:5px;color:#9aabba;font-size:9px}.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px}.dot.green{background:#27d39f}.jr-ref-progress{position:relative;height:8px;margin:12px 18px 8px;background:#101a28;border-radius:999px;overflow:visible}.jr-ref-progress>div{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#27d39f,#35cfe3)}.jr-ref-progress b{position:absolute;right:0;top:-20px;color:#bcd0dc;font-size:9px}.jr-ref-caption{text-align:center;color:#8b9aac;font-size:9px;margin:15px 0 6px}.jr-ref-hour-bars{height:74px;display:flex;align-items:end;gap:5px;padding:0 18px 14px}.jr-ref-hour-bars i{flex:1;min-width:3px;border-radius:3px 3px 0 0;background:#27d39f;opacity:.92}

.jr-ref-network{min-height:270px}.jr-ref-network-body{display:grid;grid-template-columns:.95fr 1.05fr;gap:12px;align-items:center;padding:14px}.jr-ref-ring{position:relative;max-width:175px;margin:0 auto}.jr-ref-ring>div{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;pointer-events:none}.jr-ref-ring>div strong{font-size:26px;color:#eef4f9}.jr-ref-ring>div span{font-size:8px;color:#7d8da1}.jr-ref-network-side{display:flex;flex-direction:column;gap:10px}.jr-ref-auto{min-height:48px;display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #223148;border-radius:8px;background:#162235;color:#a9b8c7;font-size:9px}.jr-ref-auto i{color:#35cfe3;font-size:18px}.jr-ref-mini-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.jr-ref-mini-grid>div{padding:9px 6px;text-align:center;border-left:1px solid #223148}.jr-ref-mini-grid>div:first-child{border-left:0}.jr-ref-mini-grid strong{display:block;color:#e7edf6;font-size:18px}.jr-ref-mini-grid span{display:block;margin-top:3px;color:#728197;font-size:7px}

.jr-ref-ai{min-height:270px;border-color:#8e2bb4}.jr-ref-ai-body{min-height:224px;display:flex;flex-direction:column;padding:16px}.jr-ref-ai-body p{margin:0;color:#c5c9d6;font-size:10px;line-height:1.6}.jr-ref-ai-body button{margin-top:auto;align-self:flex-start;padding:8px 12px;border:1px solid #7f24b7;border-radius:8px;background:#38105a;color:#e4b2ff;font-size:9px;font-weight:700}

.jr-ref-activity,.jr-ref-models,.jr-ref-alerts-card{min-height:230px}.jr-ref-activity-body{display:grid;grid-template-columns:.8fr 1.2fr;gap:12px;align-items:center;padding:16px}.jr-ref-activity-score{text-align:center}.jr-ref-activity-score strong{display:block;color:#f0f5fa;font-size:28px}.jr-ref-activity-score span{display:block;margin-top:5px;color:#7e8da2;font-size:8px}.jr-ref-activity-bars{height:120px;display:flex;align-items:end;gap:5px}.jr-ref-activity-bars i{flex:1;border-radius:3px 3px 0 0;background:linear-gradient(180deg,#35cfe3,#1f8fba)}

.jr-ref-models-body{display:grid;grid-template-columns:.85fr 1.15fr;gap:14px;padding:16px}.jr-ref-model-highlight{display:flex;flex-direction:column;justify-content:center;text-align:center}.jr-ref-model-highlight strong{color:#eef4f9;font-size:18px}.jr-ref-model-highlight span,.jr-ref-model-highlight small{color:#7e8da2;font-size:8px}.jr-ref-model-highlight b{margin-top:18px;color:#e1e7ee;font-size:17px}.jr-ref-model-bars{display:flex;flex-direction:column;gap:8px}.jr-ref-model-row{display:grid;grid-template-columns:76px 1fr 38px;gap:7px;align-items:center;font-size:8px}.jr-ref-model-row span{color:#b8c3d1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.jr-ref-model-track{height:9px;border-radius:999px;background:#101a28;overflow:hidden}.jr-ref-model-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,#27d39f,#35cfe3)}.jr-ref-model-row b{color:#8797aa;text-align:right;font-weight:500}

.jr-ref-alert-main{display:flex;flex-direction:column;align-items:center;padding:20px 12px 10px}.jr-ref-alert-main strong{font-size:28px;color:#eef4f9}.jr-ref-alert-main span{font-size:8px;color:#7d8da1}.jr-ref-alert-list{padding:0 12px 12px}.jr-ref-alert-row{min-height:28px;display:grid;grid-template-columns:8px 1fr;gap:7px;align-items:center;border-bottom:1px solid #223148;color:#b7c2cf;font-size:8px}.jr-ref-alert-row:last-child{border-bottom:0}.jr-ref-alert-row i{width:7px;height:7px;border-radius:50%;background:#d6ad38}.jr-ref-alert-row.critical i{background:#ef6876}.jr-ref-alert-row.good i{background:#27d39f}

.jr-ref-history{margin-bottom:0}.jr-ref-history .jr-ref-card-head{justify-content:flex-start}.jr-ref-history-tabs{margin-left:auto;display:flex;border:1px solid #263850;border-radius:7px;overflow:hidden}.jr-ref-history-tabs span{padding:5px 14px;color:#8090a4;font-size:8px}.jr-ref-history-tabs span.active{background:#12364b;color:#d9f5fb}.jr-ref-history #recent-devices{padding:0 10px 10px}.jr-ref-history .table-responsive{border:1px solid #223148;border-radius:7px;overflow:auto}.jr-ref-history .table{margin:0}.jr-ref-history .table thead th{background:#141f30!important;color:#8595a9!important;font-size:7px!important;padding:8px!important}.jr-ref-history .table tbody td{background:#182235!important;color:#c5ced8!important;border-color:#223148!important;font-size:8px!important;padding:8px!important}.jr-ref-history .table tbody tr:hover td{background:#1d2a3f!important}

@media(max-width:1300px){.jr-ref-grid{grid-template-columns:1fr 1fr}.jr-ref-ai{grid-column:1/-1}.jr-ref-alerts-card{grid-column:1/-1}.jr-ref-search{width:300px}}
@media(max-width:800px){.jr-ref-grid{grid-template-columns:1fr}.jr-ref-ai,.jr-ref-alerts-card{grid-column:auto}.jr-ref-network-body,.jr-ref-models-body,.jr-ref-activity-body{grid-template-columns:1fr}.jr-ref-top{align-items:flex-start;flex-direction:column}.jr-ref-search{width:100%}}


/* ===== JR RESETS CARD V7 ===== */
.jr-ref-resets{min-height:230px}
.jr-ref-resets-body{min-height:185px;display:grid;grid-template-columns:.9fr 1.1fr;gap:12px;align-items:center;padding:16px}
.jr-ref-reset-main{text-align:center}
.jr-ref-reset-main>strong{display:block;color:#eef4f9;font-size:30px;line-height:1}
.jr-ref-reset-main>span{display:block;margin-top:6px;color:#8a99ab;font-size:9px}
.jr-ref-reset-ok{margin-top:16px;display:flex;align-items:center;gap:8px;text-align:left;padding:9px 10px;border:1px solid #223148;border-radius:8px;background:#151f30;color:#9fb0c0;font-size:8px;line-height:1.4}
.jr-ref-reset-ok i{color:#27d39f;font-size:18px}
.jr-ref-reset-week{text-align:center}
.jr-ref-reset-week>strong{display:block;color:#dbe5ee;font-size:10px}
.jr-ref-reset-week>span{display:block;margin-top:10px;color:#8392a5;font-size:8px}
.jr-ref-reset-week>span b{color:#c6d2de}
.jr-ref-reset-bars{height:72px;display:flex;align-items:end;gap:6px;margin-top:8px;padding:0 4px;border-bottom:1px dashed #4b6176}
.jr-ref-reset-bars i{flex:1;min-width:5px;border-radius:4px 4px 0 0;background:#1c3146}
.jr-ref-reset-days{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;margin-top:5px;color:#7e8da2;font-size:7px}

</style>
<link rel="stylesheet" href="/assets/css/dashboard-reference-dark.css?v=20261029-1">


<div class="jrc-dashboard">


<?php if (!$genieacsConfigured): ?>


    <div class="alert alert-warning">

        <i class="bi bi-exclamation-triangle"></i>

        O Genie ainda não foi configurado.

        Configure primeiro em

        <a href="/configuration.php">
            Configurações
        </a>.

    </div>


<?php else: ?>


    <style>
    html, body, .main-content, #mainContent, .content-wrapper, .page-content, .dashboard-content, .container-fluid, .container, main {
        background: #0c0d10 !important;
        background-image: none !important;
    }
    .sidebar, .sidebar-header {
        background: #101114 !important;
        border-color: #1c1e24 !important;
    }
    #sidebarToggle.sidebar-toggle {
        background: #1c1f26 !important;
        border: 1px solid #32353d !important;
        color: #e8eaee !important;
        box-shadow: none !important;
        border-radius: 10px !important;
    }
    #sidebarToggle.sidebar-toggle:hover {
        background: #2a2d34 !important;
        color: #fff !important;
        transform: none !important;
    }
    .topbar, .navbar, header.topbar, body .topbar {
        background: #0c0d10 !important;
        border-bottom: 1px solid #1c1e24 !important;
    }
    .footer { display: none !important; }
    .fb-overview{display:block!important;color:#f3f5f7!important}
    .fb-overview .fb-lower{display:grid!important;grid-template-columns:minmax(0,1fr) 280px!important;gap:14px!important;align-items:start!important}
    .fb-overview *{box-sizing:border-box!important}
    .fb-overview h1{margin:0!important;color:#f7f8fa!important;font-size:28px!important;font-weight:650!important;letter-spacing:-.03em!important;line-height:1.15!important}
    .fb-overview .fb-page-head p{margin:6px 0 0!important;color:#8d939c!important;font-size:14px!important}
    .fb-overview .fb-stats{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important;margin:18px 0 14px!important}
    .fb-overview .fb-stat{display:block!important;background:#14161b!important;border:1px solid #2a2d34!important;border-radius:16px!important;padding:16px!important;min-height:118px!important;box-shadow:none!important}
    .fb-overview .fb-stat-icon{width:34px!important;height:34px!important;max-width:34px!important;border-radius:10px!important;display:grid!important;place-items:center!important;margin:0 0 16px!important;background:#1c2030!important;color:#8eb0ff!important}
    .fb-overview .fb-stat-icon.is-red{background:#2a1518!important;color:#ff5d5d!important}
    .fb-overview .fb-stat-icon.is-amber{background:#2c2416!important;color:#f5b942!important}
    .fb-overview .fb-stat-icon.is-bell{background:#2a1518!important;color:#ff6b6b!important}
    .fb-overview .fb-stat-icon i{font-size:16px!important;line-height:1!important;width:auto!important;height:auto!important;margin:0!important;background:transparent!important;border:0!important}
    .fb-overview .fb-stat strong{display:block!important;color:#f7f8fa!important;font-size:30px!important;font-weight:650!important;line-height:1!important;background:transparent!important;border:0!important}
    .fb-overview .fb-stat span{display:block!important;margin-top:6px!important;color:#8d939c!important;font-size:13px!important}
    .fb-overview .fb-panel{background:#14161b!important;border:1px solid #2a2d34!important;border-radius:16px!important;overflow:hidden!important}
    .fb-overview .fb-panel-head{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:12px!important;padding:14px 16px!important;border-bottom:1px solid #2a2d34!important}
    .fb-overview .fb-panel-head h2{margin:0!important;color:#f3f5f7!important;font-size:15px!important;font-weight:650!important}
    .fb-overview .fb-panel-head a{color:#8d939c!important;font-size:13px!important;text-decoration:none!important}
    .fb-overview #recent-devices{padding:0 8px 8px!important}
    .fb-overview #recent-devices .table{margin:0!important;color:#d7dbe2!important;--bs-table-bg:#14161b;--bs-table-color:#d7dbe2;--bs-table-border-color:#2a2d34;--bs-table-hover-bg:#1b1e25;--bs-table-hover-color:#fff}
    .fb-overview #recent-devices .table thead,
    .fb-overview #recent-devices .table thead tr,
    .fb-overview #recent-devices .table thead th{background:#14161b!important;background-color:#14161b!important;color:#8d939c!important;font-size:11px!important;letter-spacing:.04em!important;padding:10px 8px!important;border-color:#2a2d34!important;box-shadow:none!important}
    .fb-overview #recent-devices .table tbody td{background:#14161b!important;background-color:#14161b!important;color:#d7dbe2!important;font-size:13px!important;padding:10px 8px!important;border-color:#26282f!important}
    .fb-overview #recent-devices .table tbody tr:hover td{background:#1b1e25!important;background-color:#1b1e25!important}
    .fb-overview #recent-devices a,
    .fb-overview #recent-devices a:link,
    .fb-overview #recent-devices a:visited,
    .fb-overview #recent-devices .table a,
    .fb-overview #recent-devices .table a:link,
    .fb-overview #recent-devices .table a:visited{color:#e8eaee!important;text-decoration:none!important}
    .fb-overview #recent-devices a:hover,
    .fb-overview #recent-devices .table a:hover{color:#fff!important}
    .fb-overview .fb-equip{display:flex!important;align-items:center!important;gap:10px!important;color:#f3f5f7!important;text-decoration:none!important}
    .fb-overview .fb-equip strong{display:block!important;color:#f7f8fa!important;font-size:13px!important;font-weight:650!important}
    .fb-overview .fb-equip small,.fb-overview .fb-muted{display:block!important;color:#8d939c!important;font-size:12px!important}
    .fb-overview .fb-equip-icon{width:32px!important;height:32px!important;border-radius:10px!important;display:grid!important;place-items:center!important;background:#1c1f27!important;color:#c5cad3!important;flex:0 0 auto!important}
    .fb-overview .fb-signal{display:inline-flex!important;align-items:center!important;gap:6px!important;border-radius:999px!important;padding:3px 8px!important;font-size:12px!important;font-weight:650!important;background:#1c1f27!important;color:#c5cad3!important}
    .fb-overview .fb-signal i{font-size:8px!important}
    .fb-overview .fb-signal.is-good{background:#143222!important;color:#3ddc84!important}
    .fb-overview .fb-signal.is-warn{background:#3a2e14!important;color:#f5b942!important}
    .fb-overview .fb-signal.is-bad{background:#3a1518!important;color:#ff6b6b!important}
    .fb-overview .fb-side{padding-bottom:8px!important}
    .fb-overview .fb-side-row{display:grid!important;grid-template-columns:4px 1fr auto!important;gap:12px!important;align-items:center!important;padding:14px 16px!important;border-top:1px solid #26282f!important}
    .fb-overview .fb-side-row small{display:block!important;color:#8d939c!important;font-size:12px!important}
    .fb-overview .fb-side-row strong{color:#f7f8fa!important;font-size:22px!important;font-weight:650!important}
    .fb-overview .fb-side-bar{width:4px!important;height:28px!important;border-radius:4px!important;background:#3ddc84!important}
    .fb-overview .fb-side-bar.is-amber{background:#f5b942!important}
    .fb-overview .fb-side-bar.is-red{background:#ff5d5d!important}
    .fb-overview .fb-side-bar.is-muted{background:#6d727a!important}
    @media(max-width:1100px){.fb-overview .fb-lower{grid-template-columns:1fr!important}.fb-overview .fb-stats{grid-template-columns:1fr 1fr!important}}
    </style>

    <div class="fb-overview">
            <div class="fb-page-head">
                <h1>Visão geral</h1>
                <p>Saúde da frota de CPEs em tempo real.</p>
            </div>
            <div class="fb-stats">
                <article class="fb-stat">
                    <div class="fb-stat-icon"><i class="bi bi-router"></i></div>
                    <strong id="ref-total">-</strong>
                    <span>Equipamentos monitorados</span>
                </article>
                <article class="fb-stat">
                    <div class="fb-stat-icon is-bell"><i class="bi bi-bell"></i></div>
                    <strong id="fb-alerts">-</strong>
                    <span>Alertas ativos</span>
                </article>
                <article class="fb-stat">
                    <div class="fb-stat-icon is-amber"><i class="bi bi-exclamation-triangle"></i></div>
                    <strong id="fb-weak">-</strong>
                    <span>Sinal fraco</span>
                </article>
                <article class="fb-stat">
                    <div class="fb-stat-icon is-red"><i class="bi bi-x-circle"></i></div>
                    <strong id="ref-critical">-</strong>
                    <span>Sinal crítico</span>
                </article>
            </div>
        <div class="fb-lower">
            <section class="fb-panel">
                <div class="fb-panel-head">
                    <h2>Equipamentos</h2>
                    <a href="/devices.php">Ver todos</a>
                </div>
                <div id="recent-devices"><div class="spinner"></div></div>
            </section>
        <aside class="fb-panel fb-side">
            <div class="fb-panel-head"><h2>Sinal da frota</h2></div>
            <div class="fb-side-row"><span class="fb-side-bar"></span><div><small>Online nas últimas 24 h</small></div><strong id="ref-online">-</strong></div>
            <div class="fb-side-row"><span class="fb-side-bar is-amber"></span><div><small>Sinal fraco</small></div><strong id="fb-weak-side">-</strong></div>
            <div class="fb-side-row"><span class="fb-side-bar is-red"></span><div><small>Sinal crítico</small></div><strong id="fb-critical-side">-</strong></div>
            <div class="fb-side-row"><span class="fb-side-bar is-muted"></span><div><small>Sem leitura</small></div><strong id="fb-nosignal">-</strong></div>
        </aside>
        </div>
    </div>

<?php endif; ?>


</div>



<!-- =========================================================
     MODAL - SOLICITAR COMUNICAÇÃO
     ========================================================= -->

<div
    class="modal fade"
    id="summonModal"
    tabindex="-1"
>


    <div class="modal-dialog modal-dialog-centered">


        <div class="modal-content">


            <div class="modal-header">


                <h5 class="modal-title">

                    <i class="bi bi-lightning-charge"></i>

                    Confirmar solicitação ao equipamento

                </h5>


                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>


            </div>


            <div class="modal-body text-center py-4">


                <i
                    class="bi bi-exclamation-triangle"
                    style="
                        font-size:3rem;
                        color:var(--warning-color);
                    "
                ></i>


                <h5 class="mt-3">

                    Solicitar comunicação com o equipamento?

                </h5>


                <p class="text-muted mb-0">

                    Deseja realmente enviar uma solicitação
                    de conexão para este equipamento?

                </p>


                <p class="text-muted mb-0">

                    <small>

                        ID do equipamento:

                        <strong id="summon-device-id"></strong>

                    </small>

                </p>


            </div>


            <div class="modal-footer">


                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >

                    <i class="bi bi-x-lg"></i>

                    Cancelar

                </button>


                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="confirmSummon()"
                >

                    <i class="bi bi-lightning-charge"></i>

                    Sim, solicitar

                </button>


            </div>


        </div>


    </div>


</div>



<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"
></script>


<script>

let deviceChart = null;
let uplinkChart = null;

let dashboardFetchInProgress = false;
let uplinkFetchInProgress = false;
let recentDevicesFetchInProgress = false;


/* ==========================================================
   ESTATÍSTICAS
   ========================================================== */

async function loadDashboardData() {
    // Dashboard principal passa a usar /Discovery como fonte oficial.
    // Mantemos esta função para compatibilidade com chamadas existentes.
    return loadFinalDiscoveryData();
}


/* ==========================================================
   GRÁFICO DOS EQUIPAMENTOS
   ========================================================== */

function updateChart(stats) {


    const canvas =
        document.getElementById(
            'deviceChart'
        );


    if (!canvas) {
        return;
    }


    const ctx =
        canvas.getContext('2d');


    if (deviceChart) {

        deviceChart.destroy();

    }


    deviceChart =
        new Chart(
            ctx,
            {

                type:
                    'doughnut',

                data: {

                    labels: [
                        'Online',
                        'Offline'
                    ],

                    datasets: [{

                        data: [
                            stats.online,
                            stats.offline
                        ],

                        backgroundColor: [
                            'rgba(32, 213, 155, 0.88)',
                            'rgba(240, 100, 114, 0.88)'
                        ],

                        borderColor: [
                            'rgba(32, 213, 155, 1)',
                            'rgba(240, 100, 114, 1)'
                        ],

                        borderWidth:
                            1

                    }]

                },


                options: {

                    responsive:
                        true,

                    maintainAspectRatio:
                        true,

                    cutout:
                        '72%',

                    plugins: {


                        legend: {

                            position:
                                'bottom',

                            labels: {

                                boxWidth:
                                    8,

                                padding:
                                    14,

                                color:
                                    '#7994a6',

                                font: {

                                    size:
                                        9

                                }

                            }

                        },


                        title: {

                            display:
                                false

                        }


                    }

                }

            }
        );

}


/* ==========================================================
   SINAL ÓPTICO
   ========================================================== */

async function loadUplinkData() {

    if (!document.getElementById('uplinkChart')) {
        return;
    }


    if (uplinkFetchInProgress) {

        console.debug(
            '[DASHBOARD] Consulta de sinal já em andamento...'
        );

        return;

    }


    uplinkFetchInProgress =
        true;


    try {


        const result =
            await fetchAPI(
                '/api/uplink-stats.php',
                {
                    timeout:
                        25000
                }
            );


        if (
            result &&
            result.success
        ) {


            updateUplinkChart(
                result.data
            );


        } else {


            if (
                result &&
                result.error !== 'timeout'
            ) {

                showToast(
                    'Falha ao carregar os dados de sinal',
                    'danger'
                );

            }


        }


    } catch (error) {


        if (
            window.location.hostname === 'localhost' ||
            window.location.hostname === '127.0.0.1'
        ) {


            console.error(
                'Erro ao carregar dados de sinal:',
                error
            );


        }


    } finally {


        uplinkFetchInProgress =
            false;


    }

}


/* ==========================================================
   GRÁFICO DO SINAL
   ========================================================== */

function updateUplinkChart(data) {


    const canvas =
        document.getElementById(
            'uplinkChart'
        );


    if (!canvas) {
        return;
    }


    const ctx =
        canvas.getContext('2d');


    if (uplinkChart) {

        uplinkChart.destroy();

    }


    uplinkChart =
        new Chart(
            ctx,
            {

                type:
                    'doughnut',

                data: {

                    labels: [
                        'Excelente',
                        'Bom',
                        'Regular',
                        'Ruim',
                        'Sem sinal'
                    ],


                    datasets: [{

                        data: [
                            data.excellent,
                            data.good,
                            data.fair,
                            data.poor,
                            data.no_signal
                        ],


                        backgroundColor: [
                            'rgba(32, 213, 155, .88)',
                            'rgba(0, 174, 220, .88)',
                            'rgba(246, 185, 74, .88)',
                            'rgba(240, 100, 114, .88)',
                            'rgba(117, 139, 152, .75)'
                        ],


                        borderColor: [
                            'rgba(32, 213, 155, 1)',
                            'rgba(0, 174, 220, 1)',
                            'rgba(246, 185, 74, 1)',
                            'rgba(240, 100, 114, 1)',
                            'rgba(117, 139, 152, 1)'
                        ],


                        borderWidth:
                            1

                    }]

                },


                options: {

                    responsive:
                        true,

                    maintainAspectRatio:
                        true,

                    cutout:
                        '72%',


                    plugins: {


                        legend: {


                            position:
                                'bottom',


                            labels: {


                                boxWidth:
                                    7,

                                padding:
                                    8,

                                color:
                                    '#7894a5',


                                font: {

                                    size:
                                        8

                                }


                            }


                        },


                        title: {

                            display:
                                false

                        }


                    }


                }


            }
        );

}


/* ==========================================================
   EXTRAIR IP
   ========================================================== */

function extractIP(ipString) {


    if (
        !ipString ||
        ipString === 'N/A'
    ) {

        return 'N/A';

    }


    const match =
        ipString.match(
            /(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/
        );


    return match
        ? match[1]
        : 'N/A';

}


/* ==========================================================
   EQUIPAMENTOS RECENTES
   ========================================================== */

let recentDashboardRequest = 0;
let recentDashboardDevices = [];

function uniqueDashboardOnus(devices) {
    const seen = new Set();
    return devices.filter(device => {
        const key = device.device_id || String(device.serial_number || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!key) return true;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}


function overviewUptime(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds) || seconds <= 0) return '—';
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    if (days > 0) return days + 'd ' + hours + 'h';
    const minutes = Math.floor((seconds % 3600) / 60);
    if (hours > 0) return hours + 'h ' + minutes + 'min';
    return Math.max(1, minutes) + ' min';
}

function overviewSeen(timestamp) {
    const value = Number(timestamp);
    if (!value) return '—';
    const diff = Math.max(0, Date.now() / 1000 - value);
    const days = Math.floor(diff / 86400);
    if (days >= 1) return days + ' d';
    const hours = Math.floor(diff / 3600);
    if (hours >= 1) return hours + ' h';
    return Math.max(1, Math.floor(diff / 60)) + ' min';
}

async function loadRecentDevices() {
    if (recentDevicesFetchInProgress) return;
    const container = document.getElementById('recent-devices');
    if (!container) return;
    const request = ++recentDashboardRequest;
    recentDevicesFetchInProgress = true;
    let devices = recentDashboardDevices;

    function draw() {
        if (request !== recentDashboardRequest) return;
        const signature = devices.map(device => [
            device.device_id,
            device.serial_number,
            device.pppoe_username,
            device.rx_power,
            device.uptime,
            device.last_inform_timestamp
        ].join('|')).join('\n');
        if (signature && signature === container.dataset.signature && container.querySelector('tbody')) return;
        container.dataset.signature = signature;
            let html =
                '<div class="table-responsive">' +
                '<table class="table table-hover">' +
                '<thead><tr>';


            html += '<th>Equipamento</th>';
            html += '<th>Cliente (PPPoE)</th>';
            html += '<th>IP WAN</th>';
            html += '<th>Sinal RX</th>';
            html += '<th>Uptime</th>';
            html += '<th>Visto</th>';


            html +=
                '</tr></thead><tbody>';



            devices.forEach(device => {
                    const ipAddress = extractIP(device.ip_tr069);
                    const ipDisplay = (ipAddress && ipAddress !== 'N/A')
                        ? `<a href="http://${escapeDashboardHtml(ipAddress)}" target="_blank" rel="noopener noreferrer">${escapeDashboardHtml(ipAddress)}</a>`
                        : '—';
                    const titleParts = [device.manufacturer, device.product_class].filter(part => part && part !== 'N/A' && part !== 'N/D');
                    const title = escapeDashboardHtml(titleParts.join(' ') || 'Equipamento');
                    const serial = escapeDashboardHtml(device.serial_number || '—');
                    const clientRaw = String(device.pppoe_username || '').trim();
                    const client = (!clientRaw || clientRaw === 'N/A' || clientRaw === 'N/D') ? '—' : escapeDashboardHtml(clientRaw);
                    const rxPower = parseFloat(device.rx_power);
                    let signal = '<span class="fb-signal is-muted"><i class="bi bi-circle-fill"></i>Sem dado</span>';
                    if (!isNaN(rxPower) && rxPower !== -999) {
                        const band = rxPower > -25 ? 'is-good' : (rxPower > -27 ? 'is-warn' : 'is-bad');
                        signal = `<span class="fb-signal ${band}"><i class="bi bi-circle-fill"></i>${escapeDashboardHtml(Number(rxPower).toFixed(2))} dBm</span>`;
                    }
                    const href = '/device-detail.php?id=' + encodeURIComponent(device.device_id || '');
                    html += '<tr>'
                        + `<td><a class="fb-equip" href="${href}"><span class="fb-equip-icon"><i class="bi bi-router"></i></span><span><strong>${title}</strong><small>${serial}</small></span></a></td>`
                        + `<td class="fb-muted">${client}</td>`
                        + `<td>${ipDisplay}</td>`
                        + `<td>${signal}</td>`
                        + `<td>${escapeDashboardHtml(overviewUptime(device.uptime))}</td>`
                        + `<td class="fb-muted">${escapeDashboardHtml(overviewSeen(device.last_inform_timestamp))}</td>`
                        + '</tr>';
                });

            html +=
                '</tbody></table></div>';


            container.innerHTML =
                html;

    }

    if (devices.length) draw();
    else if (!container.querySelector('tbody')) container.innerHTML = '<div class="spinner"></div>';

    try {
        const result = await fetchAPI('/api/recent-devices.php', { timeout: 25000 });
        if (!result?.success) throw new Error(result?.message || 'Consulta indisponível');
        devices = uniqueDashboardOnus(Array.isArray(result.devices) ? result.devices : []);
        if (!devices.length) {
            if (!container.querySelector('tbody')) {
                container.innerHTML = '<p class="text-center text-muted">Nenhuma atividade recente encontrada</p>';
            }
            return;
        }
        const serialNumbers = devices.map(device => device.serial_number);
        await Promise.allSettled([
            (async () => {
                const result = await fetchOnuBatch('/api/get-devices-ixc-batch.php', serialNumbers);
                if (!result?.success || !result.devices) return;
                devices = devices.map(device => {
                    const key = String(device.serial_number || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
                    const ixc = result.devices[key] || result.devices[device.serial_number];
                    if (!ixc?.found) return device;
                    return {
                        ...device,
                        ip_tr069: ixc.ip || device.ip_tr069,
                        pppoe_username: ixc.pppoe_username || device.pppoe_username,
                        rx_power: ixc.rx_power ?? device.rx_power,
                        temperature: ixc.temperature ?? device.temperature,
                        reading_source: ixc.rx_power != null ? 'IXC' : 'TR-069',
                        reading_queried_at: ixc.rx_power != null ? ixc._queried_at : null,
                        reading_measured_at: ixc.rx_power != null ? ixc.last_signal_update : null
                    };
                });
            })()
        ]);
        recentDashboardDevices = devices;
        draw();
    } catch (error) {
        console.warn('Consulta da dashboard indisponível:', error);
        if (devices.length) draw();
        else if (!container.querySelector('tbody')) container.innerHTML = '<p class="text-center text-danger">Falha ao carregar os equipamentos. Atualize a página.</p>';
    } finally {
        recentDevicesFetchInProgress = false;
    }
}

/* ==========================================================
   SUMMON
   ========================================================== */

let currentSummonDeviceId =
    null;


function summonDeviceQuick(
    deviceId
) {


    currentSummonDeviceId =
        deviceId;


    document
        .getElementById(
            'summon-device-id'
        )
        .textContent =
        deviceId;


    const modal =
        new bootstrap.Modal(

            document.getElementById(
                'summonModal'
            ),

            {
                backdrop:
                    false
            }

        );


    modal.show();

}


/* ==========================================================
   CONFIRMAR SUMMON
   ========================================================== */

async function confirmSummon() {


    if (
        !currentSummonDeviceId
    ) {

        return;

    }


    const modal =
        bootstrap.Modal.getInstance(

            document.getElementById(
                'summonModal'
            )

        );


    modal.hide();


    showLoading();


    const result =
        await fetchAPI(

            '/api/summon-device.php',

            {

                method:
                    'POST',

                body:
                    JSON.stringify({

                        device_id:
                            currentSummonDeviceId

                    })

            }

        );


    hideLoading();


    if (
        result &&
        result.success
    ) {


        showToast(
            'Solicitação enviada com sucesso!',
            'success'
        );


    } else {


        showToast(

            result.message ||

            'Falha ao solicitar comunicação com o equipamento',

            'danger'

        );


    }


    currentSummonDeviceId =
        null;

}


/* ==========================================================
   INICIALIZAÇÃO
   ========================================================== */

const overviewDeviceLoader = loadRecentDevices;

document.addEventListener(

    'DOMContentLoaded',

    function() {

        window.loadRecentDevices = overviewDeviceLoader;

        <?php if ($genieacsConfigured): ?>


            loadDashboardData();

            loadUplinkData();

            overviewDeviceLoader();


            /*
             * Atualização automática a cada 30 segundos.
             */

            setInterval(

                () => {


                    loadDashboardData();

                    loadUplinkData();

                    overviewDeviceLoader();


                },

                30000

            );


        <?php endif; ?>


    }

);



async function loadFinalDiscoveryData() {
    try {
        const response = await fetch('/api/discovery-ixc-tr069.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Discovery indisponível');

        const devices = Array.isArray(data.devices) ? data.devices : [];
        const summary = data.summary || {};
        let critical = 0;
        let noSignal = 0;

        const getRx = (value) => {
            if (value === null || value === undefined || value === '') return null;
            const n = Number(String(value).replace(',', '.'));
            if (!Number.isFinite(n) || Math.abs(n) < 0.001) return null;
            return n;
        };

        devices.forEach(d => {
            const rx = getRx(d?.ixc?.rx_power);
            if (rx === null) noSignal++;
            else if (rx <= -27) critical++;
        });

        const ixcDashboardStats = deriveIxcDashboardStats(devices, {
            ...summary,
            critical,
            noSignal
        });
        updateIxcDashboardVisuals(ixcDashboardStats);

        const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
        const fmt = (n) => Number(n || 0).toLocaleString('pt-BR');

        set('kpi-total', fmt(summary.total_ixc_fiber || devices.length));
        set('kpi-tr069', fmt(summary.tr069_active));
        set('kpi-critical', fmt(critical));
        set('kpi-nosignal', fmt(noSignal));
        set('status-stale', fmt(summary.tr069_stale));
        set('status-nosignal', fmt(noSignal));
        set('status-critical', fmt(critical));

        renderManufacturerBars(devices);
        renderReferenceDashboard(devices, summary);

        const d = document.getElementById('svc-discovery');
        const dd = document.getElementById('svc-discovery-dot');
        if (d) d.textContent = 'Online';
        if (dd) dd.style.background = '#27d39f';
        const t = document.getElementById('svc-tr069');
        const td = document.getElementById('svc-tr069-dot');
        if (t) t.textContent = Number(summary.tr069_active || 0) > 0 ? 'Ativo' : 'Sem ativos';
        if (td) td.style.background = Number(summary.tr069_active || 0) > 0 ? '#27d39f' : '#d6ad38';
        updateServicesSummary();
    } catch (e) {
        const d = document.getElementById('svc-discovery');
        const dd = document.getElementById('svc-discovery-dot');
        if (d) d.textContent = 'Indisponível';
        if (dd) dd.style.background = '#ef6876';
        const alerts = document.getElementById('dashboard-alerts');
        if (alerts) alerts.innerHTML = '<div class="acs-final-empty">Discovery indisponível no momento.</div>';
        updateServicesSummary();
    }
}

function renderManufacturerBars(devices) {
    const holder = document.getElementById('manufacturer-bars');
    if (!holder) return;
    const counts = {};
    devices.forEach(d => {
        const name = (d?.acs?.manufacturer || d?.ixc?.onu_tipo || 'Não identificado').trim() || 'Não identificado';
        counts[name] = (counts[name] || 0) + 1;
    });
    const rows = Object.entries(counts).sort((a,b) => b[1]-a[1]).slice(0,7);
    const total = rows.reduce((sum, [,v]) => sum + v, 0) || 1;
    if (!rows.length) {
        holder.innerHTML = '<div class="acs-final-empty">Nenhum fabricante identificado.</div>';
        return;
    }
    holder.innerHTML = rows.map(([name,count]) => {
        const pct = Math.round((count/total)*1000)/10;
        return '<div class="acs-mfr-row"><span>'+escapeDashboardHtml(name)+'</span><div class="acs-mfr-track"><div class="acs-mfr-fill" style="width:'+pct+'%"></div></div><strong>'+Number(count).toLocaleString('pt-BR')+'</strong><b>'+pct.toLocaleString('pt-BR')+'%</b></div>';
    }).join('');
}

function renderFinalAlerts(devices, generatedAt) {
    const holder = document.getElementById('dashboard-alerts');
    if (!holder) return;
    const alerts = [];
    const rxNum = (v) => {
        const n = Number(String(v ?? '').replace(',', '.'));
        return Number.isFinite(n) && Math.abs(n) >= .001 ? n : null;
    };
    devices.forEach(d => {
        const serial = d?.serial || 'ONU';
        const rx = rxNum(d?.ixc?.rx_power);
        if (rx !== null && rx <= -27) alerts.push({cls:'critical', text:'Sinal crítico detectado - '+serial});
        if (d?.status === 'TR069_STALE') alerts.push({cls:'offline', text:'Sem comunicação recente - '+serial});
        if (rx === null) alerts.push({cls:'', text:'Sem leitura óptica - '+serial});
    });
    const display = alerts.slice(0,5);
    if (!display.length) display.push({cls:'good', text:'Nenhum alerta crítico detectado'});
    const time = generatedAt ? String(generatedAt).slice(11,16) : '';
    holder.innerHTML = display.map(a => '<div class="acs-final-alert-item '+a.cls+'"><i></i><span>'+escapeDashboardHtml(a.text)+'</span><time>'+escapeDashboardHtml(time)+'</time></div>').join('');
}

function escapeDashboardHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
}

function updateDashboardClock() {
    const now = new Date();
    const date = document.getElementById('dashboard-date');
    const time = document.getElementById('dashboard-time');
    if (date) date.textContent = now.toLocaleDateString('pt-BR', { weekday:'long', day:'2-digit', month:'long', year:'numeric' });
    if (time) time.textContent = now.toLocaleTimeString('pt-BR');
}

function updateServicesSummary() {
    const genie = document.getElementById('svc-genie')?.textContent || '';
    const discovery = document.getElementById('svc-discovery')?.textContent || '';
    const out = document.getElementById('services-summary');
    if (out) out.textContent = (genie === 'Online' && discovery === 'Online') ? 'Principais ativos' : 'Verificar';
}

function refreshFinalDashboard() {
    clearOnuReadCache();
    loadFinalDiscoveryData();
    overviewDeviceLoader();
}

setInterval(updateDashboardClock, 1000);


function deriveIxcDashboardStats(devices, summary) {
    const now = Date.now();
    let online = 0;
    let offline = 0;
    let stale = 0;
    let critical = 0;
    let noSignal = 0;

    const getRx = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const n = Number(String(value).replace(',', '.'));
        if (!Number.isFinite(n) || Math.abs(n) < 0.001) return null;
        return n;
    };

    devices.forEach(d => {
        const rx = getRx(d?.ixc?.rx_power);
        if (rx === null) noSignal++;
        else if (rx <= -27) critical++;

        const lastSignal = d?.ixc?.signal_updated_at ? new Date(d.ixc.signal_updated_at).getTime() : null;
        const hasRecentSignal = Number.isFinite(lastSignal) && (now - lastSignal) <= 24 * 60 * 60 * 1000;

        const status = String(d?.status || '').toUpperCase();
        if (status === 'TR069_ACTIVE' || hasRecentSignal) {
            online++;
        } else {
            offline++;
            if (status === 'TR069_STALE') stale++;
        }
    });

    return {
        total: Number(summary?.total_ixc_fiber || devices.length || 0),
        online,
        offline,
        stale: Number(summary?.tr069_stale || stale || 0),
        tr069_active: Number(summary?.tr069_active || 0),
        critical,
        noSignal
    };
}

function updateIxcDashboardVisuals(stats) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    const fmt = (n) => Number(n || 0).toLocaleString('pt-BR');
    const total = Number(stats.total || 0);
    const online = Number(stats.online || 0);
    const offline = Number(stats.offline || 0);
    const pctOnline = total > 0 ? Math.round((online / total) * 100) : 0;
    const pctOffline = total > 0 ? Math.round((offline / total) * 100) : 0;

    set('kpi-total', fmt(total));
    set('kpi-online', fmt(online));
    set('kpi-offline', fmt(offline));
    set('kpi-tr069', fmt(stats.tr069_active));
    set('kpi-critical', fmt(stats.critical));
    set('kpi-nosignal', fmt(stats.noSignal));

    set('donut-total', fmt(total));
    set('status-online', fmt(online));
    set('status-offline', fmt(offline));
    set('status-stale', fmt(stats.stale));
    set('status-nosignal', fmt(stats.noSignal));
    set('status-critical', fmt(stats.critical));
    set('status-online-pct', pctOnline + '%');
    set('status-offline-pct', pctOffline + '%');

    updateChart({ total, online, offline });
}


function renderReferenceDashboard(devices, summary) {
    const now = Date.now();
    const getRx = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const n = Number(String(value).replace(',', '.'));
        return Number.isFinite(n) && Math.abs(n) >= .001 ? n : null;
    };
    const parseTime = (value) => {
        if (!value) return null;
        const t = new Date(String(value).replace(' ', 'T')).getTime();
        return Number.isFinite(t) ? t : null;
    };

    let online = 0, critical = 0, weak = 0, noSignal = 0, active24 = 0;
    const hourly = Array(24).fill(0);
    const models = {};
    const manufacturers = {};
    const alerts = [];

    devices.forEach(d => {
        const signalTime = parseTime(d?.ixc?.signal_updated_at);
        const recent24 = signalTime && (now - signalTime <= 24*60*60*1000);
        if (recent24) {
            online++;
            active24++;
            const hour = new Date(signalTime).getHours();
            hourly[hour]++;
        }

        const rx = getRx(d?.ixc?.rx_power);
        if (rx === null) {
            noSignal++;
            if (alerts.length < 5) alerts.push({cls:'', text:'Sem leitura óptica - ' + (d?.serial || 'ONU')});
        } else if (rx <= -27) {
            critical++;
            if (alerts.length < 5) alerts.push({cls:'critical', text:'Sinal crítico - ' + (d?.serial || 'ONU')});
        } else if (rx <= -25) {
            weak++;
        }

        const model = (d?.acs?.product_class || d?.ixc?.onu_tipo || 'Não identificado').trim() || 'Não identificado';
        models[model] = (models[model] || 0) + 1;

        const manufacturer = (d?.acs?.manufacturer || '').trim() || deriveManufacturerFromModel(model);
        manufacturers[manufacturer] = (manufacturers[manufacturer] || 0) + 1;
    });

    const total = Number(summary?.total_ixc_fiber || devices.length || 0);
    const offline = Math.max(0, total - online);
    const availability = total ? Math.round((online/total)*100) : 0;
    const set = (id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v};
    const fmt=n=>Number(n||0).toLocaleString('pt-BR');

    set('ref-online', fmt(online));
    set('ref-total', fmt(total));
    set('ref-online-pct', availability+'%');
    set('ref-availability', availability+'%');
    set('ref-tr069', fmt(summary?.tr069_active || 0));
    set('ref-critical', fmt(critical));
    set('fb-critical-side', fmt(critical));
    set('fb-weak', fmt(weak));
    set('fb-weak-side', fmt(weak));
    set('fb-nosignal', fmt(noSignal));
    set('fb-alerts', fmt(critical + weak + noSignal));
    set('ref-nosignal', fmt(noSignal));
    set('ref-active-24h', fmt(active24));

    const bar=document.getElementById('ref-online-bar'); if(bar) bar.style.width=availability+'%';

    updateChart({total, online, offline});
    renderReferenceHourBars(hourly);
    renderReferenceModelBars(models);

    const topModel = Object.entries(models).sort((a,b)=>b[1]-a[1])[0];
    const topManufacturer = Object.entries(manufacturers).sort((a,b)=>b[1]-a[1])[0];
    set('ref-top-model', topModel ? topModel[0] : '-');
    set('ref-top-manufacturer', topManufacturer ? topManufacturer[0] : '-');

    window.__jrDashboardState = {total,online,offline,availability,critical,noSignal,active24,topModel:topModel?.[0]||'-',topManufacturer:topManufacturer?.[0]||'-'};
    const ai = document.getElementById('ref-ai-summary');
    if (ai) ai.textContent = buildDashboardAISummary();
}

function deriveManufacturerFromModel(model) {
    const x=String(model||'').toUpperCase();
    if (x.includes('AN5506') || x.includes('HG6') || x.includes('HG2')) return 'FiberHome';
    if (x.includes('G-140') || x.includes('G-240') || x.includes('NOKIA')) return 'Nokia';
    if (x.includes('EG8') || x.includes('HUAWEI')) return 'Huawei';
    if (x.includes('ZYXEL') || x.includes('PX')) return 'Zyxel';
    if (x.includes('INTELBRAS')) return 'Intelbras';
    return 'Outros';
}

function renderReferenceHourBars(hourly) {
    const holder=document.getElementById('ref-hour-bars');
    const holder2=document.getElementById('ref-activity-bars');
    if(!holder&&!holder2)return;
    const max=Math.max(1,...hourly);
    const html=hourly.map(v=>'<i style="height:'+Math.max(8,Math.round((v/max)*100))+'%"></i>').join('');
    if(holder) holder.innerHTML=html;
    if(holder2) holder2.innerHTML=html;
}

function renderReferenceModelBars(models) {
    const holder=document.getElementById('ref-model-bars');
    if(!holder)return;
    const rows=Object.entries(models).sort((a,b)=>b[1]-a[1]).slice(0,5);
    const total=rows.reduce((a,[,v])=>a+v,0)||1;
    holder.innerHTML=rows.map(([name,count])=>{
        const pct=Math.round((count/total)*1000)/10;
        return '<div class="jr-ref-model-row"><span>'+escapeDashboardHtml(name)+'</span><div class="jr-ref-model-track"><div class="jr-ref-model-fill" style="width:'+pct+'%"></div></div><b>'+pct.toLocaleString('pt-BR')+'%</b></div>';
    }).join('');
}

function buildDashboardAISummary() {
    const s=window.__jrDashboardState;
    if(!s) return 'Aguardando dados do  para gerar o resumo operacional.';
    return 'Foram analisados '+s.total.toLocaleString('pt-BR')+' equipamentos. '+s.online.toLocaleString('pt-BR')+' apresentaram atividade recente nas últimas 24 horas ('+s.availability+'%). Foram identificados '+s.critical.toLocaleString('pt-BR')+' equipamentos com sinal crítico e '+s.noSignal.toLocaleString('pt-BR')+' sem leitura óptica. O modelo mais encontrado é '+s.topModel+' e o fabricante predominante é '+s.topManufacturer+'.';
}
</script>


<?php

/* JRC_INVENTORY_SAFE_V3_START */
echo '<link rel="stylesheet" href="/assets/css/jrc-inventory-safe-v3.css?v=20261022-3">';
echo '<script src="/assets/js/jrc-inventory-safe-v3.js?v=20261022-3"></script>';
/* JRC_INVENTORY_SAFE_V3_END */
include __DIR__ . '/views/layouts/footer.php';

?>

<link rel="stylesheet" href="/assets/css/jrc-inventory.css?v=20261022">
