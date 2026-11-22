(() => {
    'use strict';

    const card = document.getElementById('bmkgFloat');
    const toggle = document.getElementById('bmkgToggle');
    if (!card || !toggle) return;

    const $ = id => document.getElementById(id);

    function hideCard() {
        card.classList.add('is-hidden');
        toggle.classList.add('is-visible');
    }

    function showCard() {
        card.classList.remove('is-hidden');
        toggle.classList.remove('is-visible');
    }

    $('bmkgClose').addEventListener('click', hideCard);
    toggle.addEventListener('click', showCard);

    async function loadEarthquake() {
        try {
            const response = await fetch('/api/bmkg-earthquake.php', {
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });

            if (!response.ok) throw new Error('BMKG unavailable');

            const data = await response.json();
            if (!data.success || !data.earthquake) throw new Error('Invalid data');

            const q = data.earthquake;

            $('bmkgMagnitude').textContent = 'M ' + (q.magnitude || '-');
            $('bmkgRegion').textContent = q.region || 'Lokasi tidak tersedia';
            $('bmkgDepth').textContent = 'Kedalaman ' + (q.depth || '-');
            $('bmkgTime').textContent = (q.date || '-') + ' · ' + (q.time || '-');
            $('bmkgPotential').textContent = q.potential || 'Informasi potensi tidak tersedia';

            const updated = data.fetched_at
                ? new Date(data.fetched_at).toLocaleString('id-ID')
                : 'Tidak diketahui';

            $('bmkgUpdated').textContent = data.stale
                ? 'Data tersimpan: ' + updated + ' · Perlu pembaruan'
                : 'Pembaruan data: ' + updated;

            $('bmkgUpdated').classList.toggle('stale', !!data.stale);
        } catch (error) {
            $('bmkgUpdated').textContent = 'Data BMKG sementara tidak dapat diperbarui.';
            $('bmkgUpdated').classList.add('stale');
        }
    }

    loadEarthquake();
    setInterval(loadEarthquake, 300000);
})();
