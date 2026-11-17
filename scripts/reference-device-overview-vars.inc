        // Painel de referencia: somente valores informados pelo dispositivo.
        const jrcEnabled = value => {
            if (value === true || value === 1) return true;
            if (value === false || value === 0) return false;
            const norm = String(value ?? '').trim().toLowerCase();
            if (['true','1','enabled','on','active'].includes(norm)) return true;
            if (['false','0','disabled','off','inactive'].includes(norm)) return false;
            return null;
        };
        const jrcWifiStates = [jrcEnabled(device.wifi_enabled_24ghz),jrcEnabled(device.wifi_enabled_5ghz)];
        const jrcWifiKnown = jrcWifiStates.some(state => state !== null);
        const jrcWifiActive = jrcWifiKnown ? jrcWifiStates.filter(state => state === true).length : null;
        const jrcWifiText = jrcWifiActive === null ? 'Não informado' : jrcWifiActive + ' ativa(s)';
        const jrcPercent = (...values) => {
            for (const value of values) {
                if (value === undefined || value === null || value === '') continue;
                const n = Number(String(value).replace('%','').trim());
                if (Number.isFinite(n) && n >= 0 && n <= 100) return n;
            }
            return null;
        };
        const jrcCpu = jrcPercent(device.cpu_usage_percent,device.cpu_usage_percentage,device.cpu_percent);
        const jrcMem = jrcPercent(device.memory_usage_percent,device.memory_usage_percentage,device.memory_percent);
        const jrcHealth = getDeviceHealth(device);
        const jrcHealthText = jrcHealth.level === 'healthy' ? 'Saudável' : (jrcHealth.level === 'critical' ? 'Crítico' : 'Atenção');
        const jrcResourceGauge = (label, percent, color) =>
            '<div class="jrc-ref-system-gauge"><div class="jrc-ref-system-circle" style="--pct:' +
            (percent === null ? 0 : percent) + ';--meter-color:' + color +
            '"><strong>' + (percent === null ? '—' : percent + '%') + '</strong></div><small>' +
            label + '</small><em>' + (percent === null ? 'Não informado pelo CPE' : 'Utilização informada pelo CPE') +
            '</em></div>';
