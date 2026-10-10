document.querySelectorAll('[data-access-pass]').forEach(pass => {
    const qr = pass.querySelector('[data-pass-qr]');
    const error = pass.querySelector('[data-pass-error]');
    const button = pass.querySelector('[data-pass-download]');
    function showLoadError() { error.hidden = false; button.disabled = true; }
    qr.addEventListener('error', showLoadError);
    qr.addEventListener('load', () => { error.hidden = true; button.disabled = false; });
    if (qr.complete && !qr.naturalWidth) showLoadError();
    pass.querySelector('[data-pass-retry]').addEventListener('click', () => {
        qr.src = qr.src.split('&retry=')[0] + '&retry=' + Date.now();
    });
    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            const logo = pass.querySelector('[data-pass-logo]');
            await Promise.all([qr.decode(), logo.decode()]);
            const canvas = document.createElement('canvas');
            canvas.width = 860; canvas.height = 980;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#f5f9fc'; ctx.fillRect(0, 0, 860, 980);
            ctx.fillStyle = '#d4f1f6'; ctx.beginPath(); ctx.arc(858, -8, 188, 0, Math.PI * 2); ctx.fill();
            ctx.drawImage(logo, 26, 36, 92, 92);
            ctx.fillStyle = '#15344f'; ctx.font = '700 31px Arial'; ctx.fillText('HydroMIS', 128, 94);
            ctx.textAlign = 'right'; ctx.fillStyle = '#7890a3'; ctx.font = '700 19px Arial'; ctx.fillText('CUSTOMER ACCESS PASS', 812, 90);
            ctx.textAlign = 'center'; ctx.fillStyle = '#142b40'; ctx.font = '700 43px Arial'; ctx.fillText(button.dataset.customerName, 430, 178, 740);
            ctx.fillStyle = '#6b8294'; ctx.font = '20px Arial'; ctx.fillText('Use this mobile number to log in', 430, 218);
            ctx.fillStyle = '#0879a8'; ctx.font = '700 28px monospace'; ctx.fillText(button.dataset.contactNumber, 430, 254);
            ctx.fillStyle = '#fff'; ctx.fillRect(132, 305, 596, 596);
            ctx.imageSmoothingEnabled = false; ctx.drawImage(qr, 164, 337, 532, 532);
            ctx.fillStyle = '#6b8294'; ctx.font = '19px Arial'; ctx.fillText('Keep the full code visible when scanning', 430, 940);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
            if (!blob) throw new Error('Download unavailable');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url; link.download = 'HydroMIS-' + button.dataset.userId + '-access-pass.png';
            document.body.appendChild(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            error.hidden = true;
        } catch {
            error.hidden = false;
        } finally { button.disabled = false; }
    });
});
