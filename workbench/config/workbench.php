<?php

return [

    // Base URL of the HTTP stub (docker/stub) that soak and fixture jobs call.
    'stub_url' => env('WORKBENCH_STUB_URL', 'http://stub:8080'),

    // Write storage/logs/lifecycle.log (see App\Support\LifecycleTrace).
    'trace' => (bool) env('WORKBENCH_TRACE', true),

];
