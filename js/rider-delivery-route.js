(() => {
    let version=0,routeMap=null,routeBounds=null,controller=null,startMarker=null;
    const origins=new Map();
    function clear(){
        version++;controller?.abort();controller=null;
        startMarker?.remove();startMarker=null;
        if(routeMap){
            for(const id of ['delivery-route','delivery-route-outline'])if(routeMap.getLayer(id))routeMap.removeLayer(id);
            if(routeMap.getSource('delivery-route'))routeMap.removeSource('delivery-route');
        }
        routeMap=null;routeBounds=null;
    }
    async function json(url,signal){
        const response=await fetch(url,{signal});
        if(!response.ok)throw new Error(`Mapbox request failed (${response.status})`);
        return response.json();
    }
    async function resolveOrigin(origin,token,signal){
        const coordinates=String(origin).split(',').map(value=>Number(value.trim()));
        if(coordinates.length===2&&coordinates.every(Number.isFinite)&&Math.abs(coordinates[0])<=90&&Math.abs(coordinates[1])<=180)return [coordinates[1],coordinates[0]];
        if(origins.has(origin))return origins.get(origin);
        const url=new URL('https://api.mapbox.com/search/geocode/v6/forward');
        url.search=new URLSearchParams({q:origin,country:'ph',limit:'1',access_token:token});
        const data=await json(url,signal);
        const point=data.features?.[0]?.geometry?.coordinates;
        if(!point)throw new Error('Rider location not found');
        origins.set(origin,point);return point;
    }
    function ready(map,signal){
        if(map.isStyleLoaded())return Promise.resolve();
        return new Promise((resolve,reject)=>{
            const done=()=>{cleanup();resolve();};
            const stop=()=>{cleanup();reject(new Error('Route cancelled'));};
            const fail=()=>{cleanup();reject(new Error('Map style unavailable'));};
            const timer=setTimeout(fail,15000);
            function cleanup(){clearTimeout(timer);map.off('load',done);signal.removeEventListener('abort',stop);}
            map.on('load',done);signal.addEventListener('abort',stop,{once:true});
        });
    }
    window.HydroMISDeliveryRoute={
        clear,
        fit(){if(routeMap&&routeBounds)routeMap.fitBounds(routeBounds,{padding:40,duration:0});},
        async show(map,origin,destination,exact){
            clear();const request=version;
            const abort=new AbortController();controller=abort;
            const timer=setTimeout(()=>abort.abort(),25000);
            const status=document.getElementById('delivery-route-status');
            const navigate=document.getElementById('delivery-navigate');
            const token=document.getElementById('trackMap').dataset.mapboxToken;
            navigate.hidden=false;navigate.disabled=true;
            navigate.onclick=async event=>{
                event?.preventDefault();window.setMapVisible?.(true);
                document.getElementById('trackMap').scrollIntoView({block:'nearest'});
                map.resize();
                if(routeBounds){this.fit();return;}
                await this.show(map,origin,destination,exact);
            };
            status.textContent='Finding a road route to the customer...';
            try{
                const start=await resolveOrigin(origin,token,abort.signal);
                const url=new URL(`https://api.mapbox.com/directions/v5/mapbox/driving/${start.join(',')};${destination.lng},${destination.lat}`);
                url.search=new URLSearchParams({access_token:token,geometries:'geojson',overview:'full',steps:'true'});
                const data=await json(url,abort.signal);
                const route=data.routes?.[0];
                if(data.code!=='Ok'||!route?.geometry?.coordinates?.length)throw new Error('No drivable road route found');
                await ready(map,abort.signal);
                if(request!==version)return;
                routeMap=map;
                map.addSource('delivery-route',{type:'geojson',data:{type:'Feature',properties:{},geometry:route.geometry}});
                map.addLayer({id:'delivery-route-outline',type:'line',source:'delivery-route',layout:{'line-join':'round','line-cap':'round'},paint:{'line-color':'#fff','line-width':10,'line-opacity':0.8}});
                map.addLayer({id:'delivery-route',type:'line',source:'delivery-route',layout:{'line-join':'round','line-cap':'round'},paint:{'line-color':'#6326ff','line-width':6}});
                routeBounds=new mapboxgl.LngLatBounds();
                route.geometry.coordinates.forEach(point=>routeBounds.extend(point));
                this.fit();
                const distance=(route.distance/1000).toFixed(1),minutes=Math.max(1,Math.round(route.duration/60));
                status.textContent=`${distance} km · About ${minutes} min from rider to customer.`;
            }catch(error){
                if(request!==version)return;
                status.textContent='Could not load the road route. Check your connection and select Show route to retry.';
                console.warn('HydroMIS Mapbox route:',String(error.message).replace(/pk\.[\w.-]+/g,'[redacted]'));
            }finally{clearTimeout(timer);if(request===version)navigate.disabled=false;}
        },
    };
})();
