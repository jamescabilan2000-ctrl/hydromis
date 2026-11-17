(() => {
    const script=document.currentScript;
    const api=new URL('../api/cart.php',script.src);
    const cartUrl=new URL('../user/cart.php',script.src);
    let token='',busy=false;
    const status=document.createElement('p');
    status.setAttribute('role','status');status.className='cart-feedback';status.hidden=true;
    let feedbackTimer;
    function feedback(message,error=false){
        clearTimeout(feedbackTimer);status.textContent=message;status.hidden=false;status.classList.toggle('is-error',error);
        if(!error)feedbackTimer=setTimeout(()=>{status.hidden=true;},4000);
    }
    const panel=document.querySelector('.quick-order,.review');
    if(panel)panel.append(status);
    function render(data){
        token=data.csrf_token;
        const count=document.getElementById('cart-count'), summary=document.getElementById('cart-summary');
        if(count)count.textContent=data.count;
        document.querySelectorAll('.checkout-link').forEach(link=>{
            const empty=Number(data.count)<1;
            link.setAttribute('aria-disabled',String(empty));
            link.tabIndex=empty?-1:0;
            link.title=empty?'Add at least one product before checkout.':'';
        });
        if(summary)summary.textContent=data.count?`${data.gallons??data.count} ${(data.gallons??data.count)===1?'gallon':'gallons'} \u00b7 ${data.items.length} ${data.items.length===1?'product':'products'}`:'Your cart is empty';
        const reset=document.getElementById('reset-quick-cart');
        if(reset)reset.hidden=!data.count;
        const list=document.getElementById('quick-cart-items');
        if(list){
            list.replaceChildren();list.hidden=!data.items.length;
            for(const item of data.items){
                const row=document.createElement('li'),name=document.createElement('span'),quantity=document.createElement('span');
                name.className='item-name';name.textContent=item.name;
                quantity.className='item-quantity';quantity.textContent=`\u00d7${item.quantity}`;quantity.setAttribute('aria-label',`Quantity ${item.quantity}`);
                const controls=document.createElement('span');controls.className='item-controls';
                const minus=document.createElement('button'),plus=document.createElement('button');
                for(const button of [minus,plus]){button.type='button';button.className='cart-step';}
                minus.textContent='\u2212';minus.setAttribute('aria-label',`Remove one ${item.name}`);
                plus.textContent='+';plus.setAttribute('aria-label',`Add one ${item.name}`);
                minus.addEventListener('click',()=>updateCart(minus,{action:'decrement',key:item.key},'Cart quantity updated.'));
                plus.addEventListener('click',()=>add(plus,item.key,1));
                controls.append(minus,quantity,plus);row.append(name,controls);list.append(row);
            }
        }
        document.querySelectorAll('[data-view-cart]').forEach(link=>{
            link.href=cartUrl.href;
            link.classList.add('view-cart-button');
            link.setAttribute('aria-label',`View cart, ${data.count} ${data.count===1?'item':'items'}`);
            const label=document.createElement('span'),badge=document.createElement('span'),arrow=document.createElement('span');
            label.textContent='View Cart';badge.className='view-cart-count';badge.textContent=data.count;
            arrow.className='view-cart-arrow';arrow.textContent='→';arrow.setAttribute('aria-hidden','true');
            link.replaceChildren(label,badge,arrow);
        });
    }
    const ready=fetch(api,{cache:'no-store'}).then(r=>{if(!r.ok)throw Error('Unable to load cart');return r.json()}).then(data=>{render(data);return data}).catch(error=>{feedback(error.message,true);return null});
    async function updateCart(button,payload,message){
        if(busy)return;
        busy=true;button.setAttribute('aria-disabled','true');
        try{
            await ready;
            if(!token)throw Error('Refresh this page to load your cart.');
            const response=await fetch(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf_token:token})});
            const data=await response.json();if(!response.ok||!data.success)throw Error(data.error||'Unable to update cart');
            render(data);feedback(message);
            return true;
        }catch(error){feedback(error.message,true)}
        finally{busy=false;button.removeAttribute('aria-disabled')}
    }
    function add(button,key,quantity){return updateCart(button,{action:'add',key,quantity},'Added to your cart.');}
    document.querySelectorAll('.gallon-choice-card[data-setup-href]').forEach(choice=>choice.addEventListener('click',async event=>{
        if(choice.dataset.reviewCart!=='true')return;
        event.preventDefault();
        const status=choice.dataset.setupHref==='new_gallon_order.php'?'new':'existing';
        const updated=await updateCart(choice,{action:'container_status',container_status:status},'Container choice updated.');
        if(updated)window.location.assign(cartUrl.href);
        else {
            const dialog=document.getElementById('gallon-choice');
            dialog?.classList.remove('is-open');
            dialog?.setAttribute('aria-hidden','true');
            document.querySelector('.checkout-link')?.focus();
        }
    }));
    const resetButton=document.getElementById('reset-quick-cart');
    if(resetButton)resetButton.addEventListener('click',()=>updateCart(resetButton,{action:'clear'},'Your cart has been reset.'));
    const productKeys={'19L Round':'5gal-round:existing','19L Slim':'5gal-slim:existing','9.5L Slim':'2.5gal-slim:existing'};
    document.querySelectorAll('.product-add').forEach(button=>button.addEventListener('click',()=>add(button,productKeys[button.dataset.product],1)));
    const form=document.querySelector('#refill-form,#new-gallon-form');
    const addButton=document.querySelector('[data-add-setup]');
    if(form&&addButton)addButton.addEventListener('click',event=>{
        event.preventDefault();
        const quantity=Number(document.getElementById('quantity-input').value);
        const selected=form.querySelector('[name="container_size"]:checked');
        if(!selected||selected.disabled){feedback('This gallon is currently unavailable. Please choose an available size.',true);return;}
        const size=selected.value;
        add(addButton,`${size}:${form.id==='refill-form'?'existing':'new'}`,quantity);
    });
})();
