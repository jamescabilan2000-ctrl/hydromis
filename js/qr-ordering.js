(() => {
    'use strict';
    const nameInput = document.getElementById('fullName') || document.getElementById('guestName');
    const purokInput = document.getElementById('purok') || document.getElementById('guestPurok');
    if (nameInput) {
        const validate = () => {
            const value = nameInput.value.trim().replace(/\s+/gu, ' ');
            nameInput.setCustomValidity(/^[\p{L}][\p{L} .’'\-]{1,99}$/u.test(value) ? '' : 'Enter a name using letters, spaces, apostrophes, periods or hyphens (2–100 characters).');
        };
        nameInput.addEventListener('input', validate); validate();
    }
    if (purokInput) {
        const validate = () => {
            const value = purokInput.value.trim();
            purokInput.setCustomValidity(value === '' || /^[\p{L}\p{N} .,#/’'\-]{1,100}$/u.test(value) ? '' : 'Enter a valid purok/street using letters, numbers and address punctuation.');
        };
        purokInput.addEventListener('input', validate); validate();
    }
    const form = document.getElementById('guestOrderForm');
    if (form) {
        const mode = document.getElementById('fulfillment');
        const address = document.getElementById('guestAddress');
        const toggle = document.getElementById('optionalAddress');
        let optionalShown = !!(form.barangay.value || form.purok.value);
        const update = () => {
            const delivery = mode.value === 'delivery';
            address.hidden = !delivery && !optionalShown;
            toggle.hidden = delivery;
            form.barangay.required = delivery;
            form.purok.required = delivery;
            document.querySelectorAll('[data-address-required]').forEach(el => { el.textContent = delivery ? '*' : '(optional)'; });
        };
        mode.addEventListener('change', update);
        toggle.addEventListener('click', () => { optionalShown = !optionalShown; update(); });
        update();
    }
    const video = document.getElementById('qrVideo');
    if (!video) return;
    const canvas = document.getElementById('qrCanvas');
    const context = canvas.getContext('2d', {willReadFrequently:true});
    const status = document.getElementById('qrScanStatus');
    const stopButton = document.getElementById('stopQrCamera');
    let stream = null, frame = null, submitted = false, cameraGeneration = 0;
    const stop = () => {
        cameraGeneration++;
        if (frame !== null) cancelAnimationFrame(frame);
        stream?.getTracks().forEach(track => track.stop());
        stream = null; video.srcObject = null; video.hidden = true; stopButton.hidden = true;
    };
    const decode = (width, height) => {
        const pixels = context.getImageData(0,0,width,height);
        return window.jsQR(pixels.data,width,height);
    };
    const submit = code => {
        if (!code || submitted) return false;
        try {
            const data = JSON.parse(code.data);
            if (!data || typeof data.user_id !== 'string') throw new Error();
        } catch (_) { status.textContent = 'Use a HydroMIS customer account QR code.'; return false; }
        submitted = true; stop();
        document.getElementById('accountQrData').value = code.data;
        document.getElementById('accountQrForm').requestSubmit();
        return true;
    };
    const scan = () => {
        if (!stream) return;
        if (video.readyState >= 2) {
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            context.drawImage(video,0,0,canvas.width,canvas.height);
            if (submit(decode(canvas.width,canvas.height))) return;
        }
        frame = requestAnimationFrame(scan);
    };
    document.getElementById('startQrCamera').addEventListener('click', async () => {
        stop();
        if (!window.jsQR) { status.textContent = 'QR scanner could not load. Refresh and try again.'; return; }
        if (!navigator.mediaDevices?.getUserMedia) { status.textContent = 'Camera scanning requires HTTPS or localhost. You can upload a QR image instead.'; return; }
        const generation = cameraGeneration;
        try {
            const nextStream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'},audio:false});
            if (generation !== cameraGeneration) { nextStream.getTracks().forEach(t => t.stop()); return; }
            stream = nextStream; video.srcObject = stream; video.hidden = false; stopButton.hidden = false;
            await video.play(); status.textContent = 'Hold the full customer QR code inside the camera view.'; scan();
        } catch (_) { stop(); status.textContent = 'Unable to open the camera. Check camera permission or upload your QR image.'; }
    });
    stopButton.addEventListener('click', stop);
    document.getElementById('qrImage').addEventListener('change', async event => {
        stop();
        const file = event.target.files[0];
        if (!file) return;
        if (!window.jsQR) { status.textContent = 'QR scanner could not load. Refresh and try again.'; return; }
        if (file.size > 5*1024*1024) { status.textContent = 'Choose a QR image smaller than 5 MB.'; return; }
        const url = URL.createObjectURL(file);
        try {
            const img = new Image(); img.src = url; await img.decode();
            const ratio = Math.min(1,1600/Math.max(img.width,img.height));
            canvas.width = Math.round(img.width*ratio); canvas.height = Math.round(img.height*ratio);
            context.drawImage(img,0,0,canvas.width,canvas.height);
            if (!submit(decode(canvas.width,canvas.height))) status.textContent = 'No valid customer QR found. Upload a clear image of the full code.';
        } catch (_) { status.textContent = 'Unable to read this image. Choose a PNG or JPG QR image.'; }
        finally { URL.revokeObjectURL(url); }
    });
    window.addEventListener('pagehide', stop);
})();
