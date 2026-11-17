(() => {
    let loading;
    window.HydroMISRiderGoogleMap = {
        load(element) {
            if (loading) return loading;
            loading = new Promise((resolve, reject) => {
                const key = element.dataset.googleKey;
                if (!key) { reject(new Error('Google Maps key is not configured')); return; }
                let settled = false;
                const finish = error => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timeout);
                    error ? reject(error) : resolve();
                };
                const timeout = setTimeout(() => finish(new Error('Map loading timed out')), 12000);
                window.hydromisRiderMapReady = () => finish();
                window.gm_authFailure = () => {
                    element.replaceChildren();
                    element.textContent = 'Google Maps is unavailable. Use Open customer delivery pin. GPS sharing remains available.';
                    finish(new Error('Google Maps authorization failed'));
                };
                const url = new URL('https://maps.googleapis.com/maps/api/js');
                url.search = new URLSearchParams({key, libraries: 'marker', loading: 'async', callback: 'hydromisRiderMapReady', v: 'weekly'});
                const script = document.createElement('script');
                script.src = url.href;
                script.async = true;
                script.onerror = () => finish(new Error('Google Maps could not load'));
                document.head.append(script);
            });
            return loading;
        },
    };
})();
