<?php

// This helper is included only after bootstrap has validated the private directory/settings.
if (PHP_SAPI !== 'cli' || ! isset($directory, $settings)) {
    exit(1);
}
$dsn = PHP_OS_FAMILY === 'Windows' ? 'mysql:host=127.0.0.1;port='.$settings['port'] : 'mysql:unix_socket='.$settings['socket'];
$server = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$actual = realpath($server->query('SELECT @@datadir')->fetchColumn());
$expected = realpath($directory.'/data');
$same = PHP_OS_FAMILY === 'Windows' ? strcasecmp((string) $actual, (string) $expected) === 0 : $actual === $expected;
if ($actual === false || $expected === false || ! $same) {
    throw new RuntimeException('The server does not own this private test datadir. No schema mutation allowed.');
}

return $server;
