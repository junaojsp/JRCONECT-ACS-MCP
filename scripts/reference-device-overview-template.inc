
            <div class="jrc-ref-device">
                <section class="jrc-ref-device-top" aria-label="Indicadores do equipamento">
                    <article class="jrc-ref-device-kpi">
                        <div class="jrc-ref-kpi-label"><span>Uptime</span><i class="bi bi-clock-history"></i></div>
                        <strong>${escOverview(uptimeLabel)}</strong><small>Tempo ligado informado pelo ACS</small>
                    </article>
                    <article class="jrc-ref-device-kpi head-heat">
                        <div class="jrc-ref-kpi-label"><span>Temperatura</span><i class="bi bi-thermometer-half"></i></div>
                        <strong data-optical="temperature">Consultando...</strong><small>Leitura quando disponível</small>
                    </article>
                    <article class="jrc-ref-device-kpi head-wifi">
                        <div class="jrc-ref-kpi-label"><span>Redes Wi-Fi ativas</span><i class="bi bi-wifi"></i></div>
                        <strong>${jrcWifiActive === null ? '—' : jrcWifiActive}</strong><small>${jrcWifiText}</small>
                    </article>
                    <article class="jrc-ref-device-kpi">
                        <div class="jrc-ref-kpi-label"><span>Hosts conectados</span><i class="bi bi-diagram-3"></i></div>
                        <strong>${escOverview(hostCount)}</strong><small>Dispositivos identificados pelo CPE</small>
                    </article>
                </section>
                <section class="jrc-ref-device-main" aria-label="Saúde e recursos">
                    <article class="jrc-ref-panel">
                        <header class="jrc-ref-panel-heading">
                            <i class="bi bi-heart-pulse jrc-ref-panel-icon"></i>
                            <div><strong>Saúde do dispositivo</strong><small>Estado operacional, sem score fictício</small></div>
                        </header>
                        <div class="jrc-ref-health-body">
                            <div class="jrc-ref-health-ring ${jrcHealth.level}">
                                <strong>${jrcHealthText}</strong><small>Estado atual</small>
                            </div>
                            <div class="jrc-ref-health-lines">
                                <div><span>Equipamento</span><strong>${onuOnline ? 'Online' : 'Offline'}</strong></div>
                                <div><span>WAN</span><strong>${escOverview(primaryWan.status || 'Sem estado')}</strong></div>
                                <div><span>Wi-Fi</span><strong>${jrcWifiText}</strong></div>
                                <div><span>Hosts</span><strong>${escOverview(hostCount)}</strong></div>
                            </div>
                        </div>
                    </article>
                    <article class="jrc-ref-panel">
                        <header class="jrc-ref-panel-heading">
                            <i class="bi bi-hdd-stack jrc-ref-panel-icon system"></i>
                            <div><strong>Recursos do sistema</strong><small>Valores reportados pelo CPE</small></div>
                        </header>
                        <div class="jrc-ref-system-gauges">
                            ${jrcResourceGauge('CPU', jrcCpu, '#20cab9')}
                            ${jrcResourceGauge('Memória', jrcMem, '#ff9f35')}
                        </div>
                    </article>
                </section>
