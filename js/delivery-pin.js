(() => {
    const el = document.getElementById('delivery-pin-map');
    if (!el) return;
    const latInput = document.getElementById('deliveryLatitude');
    const lngInput = document.getElementById('deliveryLongitude');
    const status = document.getElementById('delivery-pin-status');
    const locate = document.getElementById('delivery-pin-locate');
    if (!window.L) {
        status.textContent = 'The map could not load. Please reload to choose your delivery pin.';
        locate.disabled = true;
        return;
    }
    // This is only a starting view; no destination exists until the customer chooses one.
    const map = L.map(el).setView([9.9509, 123.9622], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
    let marker;
    function selectPin(point) {
        latInput.value = point.lat.toFixed(8);
        lngInput.value = point.lng.toFixed(8);
        if (!marker) {
            marker = L.marker(point, {draggable: true, title: 'Your delivery location'}).addTo(map);
            marker.on('dragend', () => selectPin(marker.getLatLng()));
        } else marker.setLatLng(point);
        status.textContent = 'Delivery pin selected. Check that it matches your delivery address. Tap the map or drag the pin to adjust.';
    }
    if (latInput.value !== '' && lngInput.value !== '') {
        selectPin(L.latLng(Number(latInput.value), Number(lngInput.value)));
        map.setView(marker.getLatLng(), 17);
    }
    map.on('click', event => selectPin(event.latlng));
    locate.addEventListener('click', () => {
        if (!navigator.geolocation) {
            status.textContent = 'Location is unavailable. Tap your delivery address on the map instead.';
            return;
        }
        locate.disabled = true;
        status.textContent = 'Finding your location…';
        navigator.geolocation.getCurrentPosition(position => {
            const point = L.latLng(position.coords.latitude, position.coords.longitude);
            selectPin(point);
            map.setView(point, 17);
            locate.disabled = false;
        }, () => {
            status.textContent = 'Could not get your location. Tap your delivery address on the map instead.';
            locate.disabled = false;
        }, {enableHighAccuracy: true, timeout: 12000, maximumAge: 0});
    });
})();
