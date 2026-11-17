const JRC_AVAILABLE_BRANDS = ["amazon", "apple", "dell", "google", "hp", "huawei", "lenovo", "lg", "motorola", "samsung", "sony", "tplink", "xiaomi"];
/* Reference content, using only fields already supplied by the ACS. */
function jrcHostVisual(client) {
    const vendor = String(client.vendor || client.manufacturer || '').toLowerCase();
    const brands = [
        [/apple/, 'apple'], [/hewlett|\bhp\b/, 'hp'], [/samsung/, 'samsung'],
        [/motorola/, 'motorola'], [/amazon/, 'amazon'], [/google/, 'google'],
        [/xiaomi/, 'xiaomi'], [/huawei/, 'huawei'], [/lenovo/, 'lenovo'],
        [/sony/, 'sony'], [/dell/, 'dell'], [/tp.link/, 'tplink'], [/\blg\b/, 'lg']
    ];
    const match = brands.find(([test]) => test.test(vendor));
    if (match && JRC_AVAILABLE_BRANDS.includes(match[1])) {
        return `<span class="jrc-host-avatar brand-${match[1]}"><img src="/assets/img/device-brands/${match[1]}.svg" alt="${jrcRefValue(client.vendor || client.manufacturer)}" width="24" height="24"></span>`;
    }
    const name = String(client.hostname || '').toLowerCase();
    const icon = /printer|impressora/.test(name) ? 'bi-printer' : /iphone|phone|android|galaxy/.test(name) ? 'bi-phone' : /tv|chromecast/.test(name) ? 'bi-tv' : /ipad|tablet/.test(name) ? 'bi-tablet' : 'bi-pc-display';
    return `<span class="jrc-host-avatar"><i class="bi ${icon}" aria-hidden="true"></i></span>`;
}
