<?php

return [
    // Proveedores intercambiables sin recompilar la web.
    'tiles_url' => env('DELIVERY_MAP_TILES_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'attribution' => env('DELIVERY_MAP_ATTRIBUTION', '© OpenStreetMap contributors'),
    'attribution_url' => env('DELIVERY_MAP_ATTRIBUTION_URL', 'https://www.openstreetmap.org/copyright'),
    'search_url' => env('DELIVERY_MAP_SEARCH_URL', 'https://photon.komoot.io/api/'),
    'latitude' => -17.3935,
    'longitude' => -66.1570,
    'zoom' => 13,
];
