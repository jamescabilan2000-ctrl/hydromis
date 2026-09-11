(() => {
    const script = document.currentScript;
    const home = new URL('../home.php', script.src).href;
    const style = document.createElement('style');
    style.textContent = `.customer-logo-home{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;text-decoration:none}.customer-logo-home img{width:48px!important;height:48px!important;max-width:none!important;object-fit:contain!important}.customer-home-button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 14px;margin-left:12px;border:1px solid #b7dcea;border-radius:12px;background:#eefaff;color:#12618c!important;font:700 12px system-ui,sans-serif;text-decoration:none!important;flex-shrink:0}.customer-home-button:hover{background:#dff4fb}.customer-home-button:focus-visible,.customer-logo-home:focus-visible{outline:3px solid #0891b2;outline-offset:3px}@media(min-width:600px){.customer-logo-home img{width:56px!important;height:56px!important}}`;
    document.head.appendChild(style);
    const logos = [...document.querySelectorAll('img')].filter(img => /hydromis.*logo|logosystem/i.test(img.getAttribute('src') || '') || /HydroMIS.*logo/i.test(img.alt));
    logos.forEach(img => {
        let link = img.closest('a');
        if (!link) {
            link = document.createElement('a');
            img.before(link);
            link.appendChild(img);
        }
        link.href = home;
        link.classList.add('customer-logo-home');
        link.setAttribute('aria-label', 'HydroMIS home');
        link.title = 'Go to Home';
        const holder = link.parentElement;
        if (holder.matches('.nav-brand-ico,.logo-icon,.review-brand-logo,.review-head-icon')) {
            holder.style.width = 'auto'; holder.style.height = 'auto'; holder.style.flexShrink = '0';
        }
    });
    const primary = logos[0]?.closest('a');
    const isHomePage = window.location.pathname === new URL(home).pathname;
    if (primary && !isHomePage && script.dataset.hideHome !== 'true' && !document.querySelector('.home-link')) {
        const button = document.createElement('a');
        button.href = home; button.className = 'customer-home-button'; button.textContent = 'Home';
        const brand = primary.closest('.nav-brand,.navbar-brand,.brand,.logo') || primary;
        brand.after(button);
    }
})();
