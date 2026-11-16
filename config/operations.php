<?php

return ['measure_requests' => env('PORTAL_MEASURE_REQUESTS', env('APP_ENV') !== 'testing'), 'local_test' => env('CTE_LOCAL_TEST', false)];
