<?php

use App\Models\User;

return [
    'defaults' => ['guard' => 'web'],
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
    // Offline portal uses authenticated changes and admin resets, no email broker.
    'passwords' => [],
    'password_timeout' => 10800,
];
