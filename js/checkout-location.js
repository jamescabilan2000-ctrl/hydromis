(() => {
    const details=document.getElementById('delivery-pin');
    if(!details)return;
    const lat=document.getElementById('delivery-latitude'),lng=document.getElementById('delivery-longitude'),pinAddress=document.getElementById('delivery-pin-address'),status=document.getElementById('pin-status');
    let map=null,marker=null;
    const mapElement=document.getElementById('checkout-map');
    function centerMap(latitude,longitude,zoom){
        if(map)map.jumpTo({center:[longitude,latitude],zoom});
    }
    let areaTimer=null,areaVersion=0;
    function syncAddressFields(){
        const manual=document.getElementById('manual-delivery-address');
        if(manual)manual.querySelectorAll('input,select').forEach(field=>{field.disabled=false;});
    }
    syncAddressFields();
    function draw(latitude,longitude){
        lat.value=Number(latitude).toFixed(8);lng.value=Number(longitude).toFixed(8);
        syncAddressFields();
        if(map){
            if(marker)marker.setLngLat([longitude,latitude]);
            else marker=new mapboxgl.Marker({draggable:true,color:'#0c88ad'}).setLngLat([longitude,latitude]).addTo(map).on('dragend',()=>{const p=marker.getLngLat();draw(p.lat,p.lng)});
        }
        status.textContent='Finding the area for your delivery pin...';
        clearTimeout(areaTimer);
        const version=++areaVersion;
        areaTimer=setTimeout(async()=>{
            const area=await window.HydroMISPinArea?.lookup(lat.value,lng.value);
            if(version!==areaVersion)return;
            if(pinAddress)pinAddress.value=area||'';
            status.textContent=area?`Selected area: ${area}. Drag the pin or tap the map to adjust.`:'Pin selected. Area name unavailable; your delivery pin will still be used.';
        },600);
    }
    function initializeMap(){
        if(map)return;
        if(typeof mapboxgl==='undefined'){status.textContent='The map could not load. Use GPS location or enter your delivery address.';return;}
        const token=mapElement.dataset.mapboxToken;
        if(!token || !token.startsWith('pk.')){status.textContent='The map is not available yet. Use GPS location or enter your delivery address.';return;}
        try{
            map=new mapboxgl.Map({container:mapElement,accessToken:token,style:'mapbox://styles/mapbox/streets-v12',center:[123.9622,9.9509],zoom:12});
            map.addControl(new mapboxgl.NavigationControl(),'top-right');
            map.addControl(new mapboxgl.FullscreenControl(),'top-right');
            map.on('click',event=>draw(event.lngLat.lat,event.lngLat.lng));
            map.on('error',()=>{status.textContent='Map tiles could not load. Use GPS location or enter your delivery address.';});
            if(lat.value!==''&&lng.value!==''){draw(Number(lat.value),Number(lng.value));centerMap(Number(lat.value),Number(lng.value),16);}
        }catch(_){map=null;status.textContent='The map could not load. Use GPS location or enter your delivery address.';}
    }
    details.addEventListener('toggle',()=>{
        if(!details.open)return;
        initializeMap();
        if(map)map.resize();
    });
    const gpsButton=document.getElementById('use-checkout-location');
    let gpsVersion=0,gpsTimer=null;
    function resetGpsButton(){clearTimeout(gpsTimer);gpsTimer=null;gpsButton.disabled=false;gpsButton.textContent='Use GPS location';gpsButton.removeAttribute('aria-busy');}
    gpsButton.addEventListener('click',()=>{
        if(!window.isSecureContext){status.textContent='Location requires HTTPS or localhost. You can still select a map pin or enter your delivery address.';return;}
        if(!navigator.geolocation){status.textContent='This browser does not support location. Select a map pin or enter your delivery address.';return;}
        const version=++gpsVersion;
        clearTimeout(areaTimer);areaVersion++;
        gpsButton.disabled=true;gpsButton.textContent='Finding your location...';gpsButton.setAttribute('aria-busy','true');
        status.textContent='Allow location access when your browser asks.';
        gpsTimer=setTimeout(()=>{
            if(version!==gpsVersion)return;
            gpsVersion++;resetGpsButton();
            status.textContent='Finding your location took too long. Select a map pin or enter your delivery address.';
        },15000);
        navigator.geolocation.getCurrentPosition(position=>{
            if(version!==gpsVersion)return;
            resetGpsButton();
            const {latitude,longitude,accuracy}=position.coords;
            draw(latitude,longitude);
            centerMap(latitude,longitude,accuracy>500?14:17);
        },error=>{
            if(version!==gpsVersion)return;
            resetGpsButton();
            const messages={1:'Location access was denied. Allow location in your browser settings, then try again.',2:'Your device could not determine its location. Check that location services are enabled and try again.',3:'Finding your location took too long. Move near a window or outdoors and try again.'};
            status.textContent=(messages[error.code]||'Unable to get your location.')+' You can also select a map pin or enter your delivery address.';
        },{enableHighAccuracy:true,timeout:12000,maximumAge:0});
    });
    document.getElementById('clear-checkout-location').addEventListener('click',()=>{gpsVersion++;resetGpsButton();clearTimeout(areaTimer);areaVersion++;lat.value='';lng.value='';if(pinAddress)pinAddress.value='';syncAddressFields();if(marker){marker.remove();marker=null;}status.textContent='Pin cleared. Your delivery address will be used.';});
})();
