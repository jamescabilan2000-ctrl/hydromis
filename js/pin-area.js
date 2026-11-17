(() => {
    // User-selected pins only. Cache repeated lookups; never send customer details.
    const cache = new Map();
    const mapElement = document.getElementById('checkout-map');
    const endpoint = mapElement?.dataset.geocoder || 'https://photon.komoot.io/reverse';
    function areaLabel(properties) {
        const p = properties || {};
        const parts = [p.district, p.locality, p.city, p.county, p.state];
        if (p.osm_key === 'place' || ['district', 'locality', 'city'].includes(p.type)) parts.unshift(p.name);
        return [...new Set(parts.filter(value => typeof value === 'string' && value.trim()).map(value => value.trim()))].join(', ');
    }
    async function lookup(latitude, longitude) {
        const lat = Number(latitude), lon = Number(longitude);
        if (latitude === '' || longitude === '' || !Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) return '';
        const key = `${lat.toFixed(5)},${lon.toFixed(5)}`;
        if (cache.has(key)) return cache.get(key);
        const request = (async () => {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 7000);
            try {
                const url = new URL(endpoint);
                url.search = new URLSearchParams({lat: lat.toFixed(5), lon: lon.toFixed(5), limit: '1', lang: 'en', radius: '1'});
                const response = await fetch(url, {signal: controller.signal, credentials: 'omit', referrerPolicy: 'no-referrer'});
                if (!response.ok) throw new Error('Area lookup unavailable');
                const data = await response.json();
                return areaLabel(data.features?.[0]?.properties);
            } catch (_) {
                cache.delete(key);
                return '';
            } finally { clearTimeout(timeout); }
        })();
        cache.set(key, request);
        return request;
    }
    window.HydroMISPinArea = {lookup};
})();
