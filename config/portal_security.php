<?php

return [
    // Shared hosting prefixes database names; explicitly authorize only that database.
    'hosted_database' => env('CTE_HOSTED_DATABASE'),
    'failed_login_limit' => 5,
    'lock_minutes' => 15,
];
