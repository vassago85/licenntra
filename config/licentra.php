<?php

return [

    'clamav_enabled' => (bool) env('CLAMAV_ENABLED', false),

    'clamav_host' => env('CLAMAV_HOST', '127.0.0.1'),

    'clamav_port' => (int) env('CLAMAV_PORT', 3310),

];
