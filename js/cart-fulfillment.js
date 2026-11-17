(() => {
    const form = document.querySelector('.fulfillment-card form');
    if (!form) return;
    let updating = false;
    form.addEventListener('change', event => {
        if (!event.target.matches('input[name="fulfillment"]') || updating) return;
        updating = true;
        form.setAttribute('aria-busy', 'true');
        form.requestSubmit();
    });
})();
