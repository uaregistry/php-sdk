<?php

declare(strict_types=1);

/**
 * Connect, log in, check availability, read a domain, log out.
 *
 *   UAREG_HOST=uaregistry.com UAREG_CLID=UAR0001 UAREG_PW=secret \
 *   php examples/quickstart.php
 *
 * This is a COMMAND-LINE example — run it with `php`, not via a web request. For a web
 * app (php-fpm), use the SDK classes directly and echo your own output: see web.php.
 * For a self-signed / private-CA server set UAREG_CA to the CA certificate path.
 */

$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'   // Composer
    : __DIR__ . '/../autoload.php';          // no-Composer fallback
require $autoload;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Config;
use UARegistry\Sdk\Exception\EppException;

$config = Config::fromArray([
    'host'     => getenv('UAREG_HOST') ?: 'uaregistry.com',
    'clid'     => getenv('UAREG_CLID') ?: 'UAR0001',
    'password' => getenv('UAREG_PW') ?: '',
    'port'     => (int) (getenv('UAREG_PORT') ?: 700),  // default 700; override if needed
    'caFile'   => getenv('UAREG_CA') ?: null,           // CA bundle for a private-CA endpoint
    // Only if your endpoint requires mutual TLS (the public :700 endpoint does not):
    // 'clientCert'          => '/path/registrar.crt.pem',
    // 'clientKey'           => '/path/registrar.key.pem',
    // 'clientKeyPassphrase' => 'key-passphrase-if-encrypted',
]);

// Optional: pass any PSR-3 logger (e.g. Monolog) — passwords/authInfo are masked.
//   $log = new Monolog\Logger('epp'); $log->pushHandler(new Monolog\Handler\StreamHandler('php://stderr'));
//   $client = new Client($config, null, $log);

$client = new Client($config);

try {
    $client->connect();          // opens TLS and reads the <greeting>
    $client->login();            // advertises exactly the services the server offers

    $availability = $client->domain()->check(['example.com.ua', 'your-brand.com.ua'])->availability();
    foreach ($availability as $name => $free) {
        echo sprintf("%-24s %s\n", $name, $free ? 'available' : 'taken');
    }

    $info = $client->domain()->info('example.com.ua');
    echo "\nexample.com.ua\n";
    echo '  status:  ' . implode(', ', $info->values('status') ?: ['-']) . "\n";
    echo '  expires: ' . ($info->value('exDate') ?? '-') . "\n";

    $balance = $client->balance();
    echo "\nbalance: " . ($balance->value('balance') ?? '-') . "\n";

    $client->logout();
} catch (EppException $e) {
    // STDERR exists only on the CLI; under php-fpm write to the response instead.
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb'), 'EPP error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $client->disconnect();
}
