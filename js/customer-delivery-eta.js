(() => {
    let request=null,version=0,lastRequest=0;
    const put=(id,value)=>{document.getElementById(id).textContent=value;};
    function reset(message){
        version++;request?.abort();request=null;lastRequest=0;
        put('eta-distance','—');put('eta-duration','—');put('eta-arrival','—');put('eta-note',message);
    }
    window.HydroMISCustomerETA={
        reset,
        async update(point,destination,status,onRoute){
            if(!destination){reset('An estimate requires a saved delivery pin.');return;}
            if(!['on_way','on_the_way'].includes(status)){reset('Estimated arrival will appear when your rider is on the way.');return;}
            if(request || Date.now()-lastRequest<60000)return;
            const token=document.getElementById('map').dataset.mapboxToken;
            if(!token){reset('Arrival estimate is currently unavailable.');return;}
            const controller=new AbortController();request=controller;
            const current=++version;lastRequest=Date.now();
            const timeout=setTimeout(()=>controller.abort(),10000);
            try{
                const url=new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${point[1]},${point[0]};${destination[1]},${destination[0]}`);
                url.search=new URLSearchParams({access_token:token,geometries:'geojson',overview:'full'});
                const response=await fetch(url,{signal:controller.signal});
                if(!response.ok)throw new Error('Estimate unavailable');
                const data=await response.json(),route=data.routes?.[0];
                if(data.code!=='Ok'||!route||!Number.isFinite(route.distance)||!Number.isFinite(route.duration)||!route.geometry?.coordinates?.length)throw new Error('No road route');
                if(current!==version)return;
                const minutes=Math.max(1,Math.ceil(route.duration/60));
                put('eta-distance',route.distance<1000?`${Math.round(route.distance)} m`:`${(route.distance/1000).toFixed(1)} km`);
                put('eta-duration',`${minutes} min`);
                put('eta-arrival',new Intl.DateTimeFormat('en-PH',{hour:'numeric',minute:'2-digit',timeZone:'Asia/Manila'}).format(new Date(Date.now()+route.duration*1000)));
                put('eta-note','Estimated from the latest rider location. Traffic and delivery stops may affect arrival. Updates about every minute.');
                onRoute(route.geometry);
            }catch(_){if(current===version){put('eta-distance','—');put('eta-duration','—');put('eta-arrival','—');put('eta-note','Arrival estimate is temporarily unavailable. Rider tracking is still active.');}}
            finally{clearTimeout(timeout);if(current===version)request=null;}
        },
    };
})();
