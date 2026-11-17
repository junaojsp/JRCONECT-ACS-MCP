/* Presentational WAN reference header. Uses only current device.wan_details. */
function jrcWANReferenceHeader(wans) {
    const records = Array.isArray(wans) ? wans : [];
    const connected = records.filter(w => String(w?.status || '').toLowerCase() === 'connected');
    const internet = connected.find(w => !/tr069|cwmp/i.test(String(w?.service_list || '') + ' ' + String(w?.name || ''))) || connected[0];
    const ppp = records.filter(w => /ppp/i.test(String(w?.type || '') + ' ' + String(w?.connection_type || '')));
    const validIP = String(internet?.external_ip || '').trim();
    const shownIP = validIP && validIP !== 'N/A' && validIP !== '0.0.0.0' ? validIP : '—';
    const escaped = value => String(value).replace(/[&<>"']/g, ch => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    })[ch]);
    const cards = [
        ['bi-hdd-network','Conexões WAN',String(records.length),'Interfaces detectadas'],
        ['bi-globe','IP externo',shownIP,'Conexão informada pelo CPE'],
        ['bi-link-45deg','Conexões ativas',String(connected.length),'Estado informado pelo CPE'],
        ['bi-person-check','PPPoE',String(ppp.length),'Interfaces PPP identificadas']
    ];
    return '<div class="jrc-wan-ref-kpis" aria-label="Resumo da WAN">' +
        cards.map(([icon,title,value,desc]) =>
            '<article class="jrc-wan-ref-kpi"><div class="jrc-wan-ref-top">' +
            '<span>'+escaped(title)+'</span><i class="bi '+icon+'"></i></div>' +
            '<strong>'+escaped(value)+'</strong><small>'+escaped(desc)+'</small></article>'
        ).join('') + '</div>';
}
