<?php
function hydromis_maps_config(): array {
    $localFile=__DIR__.'/maps.local.php';
    $local=is_file($localFile)?require $localFile:[];
    if (!is_array($local)) $local=[];
    return [
        'mapbox_token'=>(string)(getenv('MAPBOX_ACCESS_TOKEN')?:($local['mapbox_token']??'pk.eyJ1IjoiamFtZXNjYWJpbGFuIiwiYSI6ImNtdnN5MnJhcDA1bjkyeXFicGJ5MHc5YjYifQ.1oFN4raZNBELSVQSxswEXg')),
        'api_key'=>(string)(getenv('GOOGLE_MAPS_API_KEY')?:($local['api_key']??'')),
        'map_id'=>(string)(getenv('GOOGLE_MAPS_MAP_ID')?:($local['map_id']??'DEMO_MAP_ID')),
        'station_origin'=>(string)(getenv('HYDROMIS_STATION_ORIGIN')?:($local['station_origin']??'Guiwanon, Tubigon, Bohol, Philippines')),
        'station_exact'=>!empty(getenv('HYDROMIS_STATION_ORIGIN')) || !empty($local['station_origin']),
    ];
}
