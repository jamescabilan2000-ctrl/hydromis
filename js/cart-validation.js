(() => {
    const checkout=document.getElementById('cart-checkout');
    if(!checkout)return;
    const byName=name=>checkout.elements.namedItem(name);
    let submitted=false;
    const confirmation=document.getElementById('order-confirmation');
    let confirmed=false;
    if(confirmation){
        for(const id of ['confirmation-close','confirmation-edit'])document.getElementById(id).addEventListener('click',()=>confirmation.close());
        document.getElementById('confirmation-submit').addEventListener('click',()=>{confirmed=true;confirmation.close();checkout.requestSubmit();});
    }
    function reviewOrder(){
        const value=name=>byName(name)?.value.trim()||'';
        const put=(id,text)=>{document.getElementById(id).textContent=text;};
        const pickup=confirmation.dataset.fulfillment==='pickup';
        const pin=!pickup&&value('delivery_latitude')!==''&&value('delivery_longitude')!=='';
        put('confirmation-mobile',value('mobile_number'));
        const street=value('delivery_street'),barangay=value('delivery_barangay');
        put('confirmation-destination',pickup?'Station pickup':pin?'Finding delivery address...':street&&barangay?`${street}, ${barangay}`:'Use the address registered with this mobile number');
        if(pin){
            const latitude=value('delivery_latitude'),longitude=value('delivery_longitude');
            Promise.resolve(window.HydroMISPinArea?.lookup(latitude,longitude)).then(area=>{
                if(!confirmation.open || latitude!==value('delivery_latitude') || longitude!==value('delivery_longitude'))return;
                put('confirmation-destination',area||'Delivery to your selected map pin (address unavailable)');
            });
        }
        const method=value('payment_method'),proof=byName('payment_proof')?.files?.[0];
        put('confirmation-payment',method==='cash'?(pickup?'Cash on pickup':'Cash on delivery'):method==='gcash'?'GCash — for staff verification':'Maya — for staff verification');
        document.getElementById('confirmation-proof-row').hidden=method==='cash';
        put('confirmation-proof',proof?proof.name:'');
        document.getElementById('confirmation-notes-row').hidden=!value('notes');put('confirmation-notes',value('notes'));
        confirmation.showModal();document.getElementById('confirmation-edit').focus();
    }
    function cleanPhone(value,allowCountryCode){
        const prefix=allowCountryCode&&value.startsWith('+')?'+':'';
        const digits=value.replace(/\D/g,'');
        const limit=allowCountryCode&&digits.startsWith('63')?12:11;
        return prefix+digits.slice(0,limit);
    }
    for(const name of ['mobile_number','wallet_number']){
        const field=byName(name);if(!field)continue;
        const countryCode=name==='mobile_number';
        field.addEventListener('beforeinput',event=>{
            if(event.inputType!=='insertText'||!event.data||event.isComposing)return;
            const next=field.value.slice(0,field.selectionStart)+event.data+field.value.slice(field.selectionEnd);
            if(!(countryCode?/^\+?\d*$/:/^\d*$/).test(next))event.preventDefault();
        });
        // Covers paste, drag/drop, autofill and keyboards without beforeinput.
        field.addEventListener('input',()=>{
            const caret=field.selectionStart??field.value.length;
            const cleaned=cleanPhone(field.value,countryCode);
            if(cleaned!==field.value){
                const nextCaret=cleanPhone(field.value.slice(0,caret),countryCode).length;
                field.value=cleaned;field.setSelectionRange(nextCaret,nextCaret);
            }
        });
    }
    function message(field){
        if(field.disabled)return '';
        const value=field.value.trim();
        if(field.name==='mobile_number'&&!/^(09\d{9}|\+?639\d{9})$/.test(value))return 'Enter 09 followed by 9 digits, or the equivalent +63 mobile number.';
        if(field.name==='wallet_number'&&!/^09\d{9}$/.test(value))return 'Enter the 11-digit wallet number beginning with 09.';
        if(field.name==='delivery_barangay'){
            if(value&&!Array.from(field.options).some(option=>option.value===value))return 'Select a barangay from the list.';
            if(!value&&byName('delivery_street')?.value.trim())return 'Select the barangay for this street or purok.';
        }
        if(field.name==='delivery_street'){
            if(!value&&byName('delivery_barangay')?.value)return 'Enter the street number or purok for this barangay.';
            if(value&&(Array.from(value).length>100||!/^[\p{L}0-9 ]+$/u.test(value)))return 'Use letters, numbers and spaces only, up to 100 characters.';
        }
        if(['notes','delivery_address'].includes(field.name)){
            if(Array.from(value).length>500)return 'Use no more than 500 characters.';
            if(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(value))return 'Remove invalid control characters.';
        }
        if(['delivery_latitude','delivery_longitude'].includes(field.name)){
            const latitude=byName('delivery_latitude'),longitude=byName('delivery_longitude');
            if(Boolean(latitude.value.trim())!==Boolean(longitude.value.trim()))return 'Choose a complete location pin or clear both coordinates.';
            const limit=field.name==='delivery_latitude'?90:180;
            if(value!==''&&(!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(value)||!Number.isFinite(Number(value))||Math.abs(Number(value))>limit))return `Enter a coordinate between -${limit} and ${limit}.`;
        }
        if(field.name==='payment_method'&&!['cash','gcash','maya'].includes(value))return 'Choose cash, GCash or Maya.';
        if(field.name==='payment_proof'){
            const file=field.files?.[0];
            if(!file)return 'Upload your payment proof before placing the order.';
            if(!file.size||file.size>5242880)return 'Choose a nonempty screenshot, 5MB or smaller.';
            if(!['image/jpeg','image/png','image/webp'].includes(file.type))return 'Choose a JPG, PNG or WEBP image.';
        }
        return '';
    }
    function show(field){
        if(!field.name||field.type==='hidden')return true;
        field.setCustomValidity('');
        field.setCustomValidity(message(field));
        const invalid=!field.disabled&&!field.checkValidity();
        let error=document.getElementById('error-'+field.name);
        if(!error){error=document.createElement('small');error.className='field-error';error.id='error-'+field.name;field.after(error);}
        error.textContent=invalid?field.validationMessage:'';error.hidden=!invalid;
        field.setAttribute('aria-invalid',String(invalid));
        const ids=new Set((field.getAttribute('aria-describedby')||'').split(' ').filter(Boolean));ids.add(error.id);field.setAttribute('aria-describedby',[...ids].join(' '));
        return !invalid;
    }
    const fields=[...checkout.querySelectorAll('input,select,textarea')].filter(field=>field.type!=='hidden');
    checkout.noValidate=true;
    for(const field of fields){
        field.addEventListener('blur',()=>{if(field.type!=='file')field.value=field.value.trim();show(field)});
        field.addEventListener('input',()=>{if(submitted||field.getAttribute('aria-invalid')==='true')show(field)});
        field.addEventListener('change',()=>{
            show(field);
            if(['delivery_barangay','delivery_street'].includes(field.name)){for(const name of ['delivery_barangay','delivery_street']){const input=byName(name);if(input)show(input);}}
            if(field.name==='payment_method')for(const name of ['wallet_number','payment_proof']){const input=byName(name);if(input.disabled){input.setCustomValidity('');input.setAttribute('aria-invalid','false');const error=document.getElementById('error-'+name);if(error)error.hidden=true;}}
        });
    }
    function summary(errors){
        let box=document.getElementById('cart-errors');
        if(!box){box=document.createElement('div');box.id='cart-errors';box.className='notice';box.setAttribute('role','alert');box.tabIndex=-1;document.querySelector('.layout').before(box);}
        box.replaceChildren();const title=document.createElement('strong');title.textContent='Please check your order.';box.append(title);
        const list=document.createElement('ul');for(const {field,text} of errors){const item=document.createElement('li'),link=document.createElement('a');link.href='#'+field.id;link.textContent=text;link.addEventListener('click',event=>{event.preventDefault();const details=field.closest('details');if(details)details.open=true;field.focus()});item.append(link);list.append(item);}box.append(list);box.focus();
    }
    checkout.addEventListener('submit',event=>{
        if(checkout.dataset.submitting==='true'){event.preventDefault();return;}
        submitted=true;
        const errors=[];
        for(const field of fields){if(field.type!=='file')field.value=field.value.trim();if(!show(field))errors.push({field,text:field.validationMessage});}
        for(const quantity of document.querySelectorAll('.cart-items-grid .controls input[name="quantity"]'))if(quantity.value!==quantity.defaultValue)errors.push({field:quantity,text:'Update the changed product quantity before placing your order.'});
        const fulfillment=[...document.querySelectorAll('input[name="fulfillment"]')];const selected=fulfillment.find(input=>input.checked),saved=fulfillment.find(input=>input.defaultChecked);
        if(selected&&saved&&selected.value!==saved.value)errors.push({field:selected,text:'Update the delivery option before placing your order.'});
        if(errors.length){confirmed=false;event.preventDefault();summary(errors);return;}
        if(confirmation&&!confirmed){event.preventDefault();reviewOrder();return;}
        confirmed=false;
        checkout.dataset.submitting='true';const button=checkout.querySelector('button.primary');button.dataset.originalLabel=button.textContent;button.textContent='Placing order…';button.disabled=true;
    });
    window.addEventListener('pageshow',()=>{checkout.dataset.submitting='false';const button=checkout.querySelector('button.primary');if(button){button.disabled=false;if(button.dataset.originalLabel)button.textContent=button.dataset.originalLabel;}});
    // Server validation is also announced and tied to the matching controls.
    for(const field of fields){const error=document.getElementById('error-'+field.name);if(error?.textContent){field.setAttribute('aria-invalid','true');field.setAttribute('aria-describedby',error.id);}}
    const serverErrors=document.getElementById('cart-errors');if(serverErrors)serverErrors.focus();
})();
