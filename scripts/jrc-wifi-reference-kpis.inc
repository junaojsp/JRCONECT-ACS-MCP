
        <section class="jrc-wifi-ref-kpis" aria-label="Resumo das redes Wi-Fi">
            <article class="jrc-wifi-ref-kpi"><div class="jrc-wifi-ref-label"><span>Redes Wi-Fi ativas</span><i class="bi bi-wifi"></i></div>
                <strong>${[device.wifi_enabled_24ghz, device.wifi_enabled_5ghz].every(v => v === null || v === undefined || v === '') ? '—' : [device.wifi_enabled_24ghz, device.wifi_enabled_5ghz].filter(v => v === true || v === 1 || ['true','enabled','1','on'].includes(String(v).toLowerCase())).length}</strong>
                <small>Estados informados pelo CPE</small></article>
            <article class="jrc-wifi-ref-kpi"><div class="jrc-wifi-ref-label"><span>Estações Wi-Fi</span><i class="bi bi-people"></i></div>
                <strong>${[device.wifi_clients_24ghz, device.wifi_clients_5ghz].every(n => n !== undefined && n !== null && n !== '' && Number.isFinite(Number(n))) ? Number(device.wifi_clients_24ghz)+Number(device.wifi_clients_5ghz) : '—'}</strong>
                <small>Clientes por rádio, quando informados</small></article>
            <article class="jrc-wifi-ref-kpi"><div class="jrc-wifi-ref-label"><span>Mesh</span><i class="bi bi-diagram-3"></i></div>
                <strong>${device.wifi_mesh_enabled === true ? 'Ativo' : device.wifi_mesh_enabled === false ? 'Inativo' : '—'}</strong>
                <small>Condição somente se informada</small></article>
            <article class="jrc-wifi-ref-kpi"><div class="jrc-wifi-ref-label"><span>Hosts conectados</span><i class="bi bi-hdd-network"></i></div>
                <strong>${connectedDevices === null ? '—' : connectedDevices}</strong>
                <small>Inventário de hosts do dispositivo</small></article>
        </section>
