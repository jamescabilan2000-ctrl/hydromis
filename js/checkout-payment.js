(() => {
    const select=document.getElementById('payment');
    const panel=document.getElementById('wallet-payment');
    if(!select||!panel)return;
    const number=document.getElementById('wallet-number'),proof=document.getElementById('wallet-proof');
    function update(){
        const wallet=select.value!=='cash';panel.hidden=!wallet;
        panel.querySelectorAll('[data-wallet]').forEach(section=>section.hidden=section.dataset.wallet!==select.value);
        for(const input of [number,proof]){input.disabled=!wallet;input.required=wallet;}
    }
    select.addEventListener('change',update);update();
})();
