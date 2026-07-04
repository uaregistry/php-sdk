<?php

declare(strict_types=1);

/**
 * Localized session (Ukrainian) + reading money and DNSSEC. Shows the response accessors:
 *   - log in with lang=uk       -> standard result messages come back in Ukrainian
 *   - read the account balance  -> creditLimit / balance / availableCredit
 *   - read a domain's price hint (renewal/restore) and its DNSSEC DS records
 *
 *   UAREG_HOST=uaregistry.com UAREG_CLID=UAR0001 UAREG_PW=secret \
 *   php examples/localized_balance.php example.com.ua
 *
 * A COMMAND-LINE example — run with `php`. For a private-CA endpoint set UAREG_CA.
 */

$autoload = is_file(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'   // Composer
    : __DIR__ . '/../autoload.php';          // no-Composer fallback
require $autoload;

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Config;
use UARegistry\Sdk\Exception\EppException;

$domain = $argv[1] ?? 'example.com.ua';

$config = Config::fromArray([
    'host'     => getenv('UAREG_HOST') ?: 'uaregistry.com',
    'clid'     => getenv('UAREG_CLID') ?: 'UAR0001',
    'password' => getenv('UAREG_PW') ?: '',
    'port'     => (int) (getenv('UAREG_PORT') ?: 700),
    'caFile'   => getenv('UAREG_CA') ?: null,
    'lang'     => 'uk',   // ask the server for Ukrainian result messages ('ua' and 'ru' also work)
]);

$client = new Client($config);

try {
    $client->connect();
    $login = $client->login();
    // Standard result messages now come back in the session language:
    echo 'login: ' . $login->code() . ' [' . ($login->messageLang() ?? '?') . '] ' . ($login->message() ?? '') . "\n";

    $balance = $client->balance()->balance();   // ['creditLimit'=>…,'balance'=>…,'availableCredit'=>…] or null
    if ($balance !== null) {
        echo "\nBalance (UAH):\n";
        echo '  credit limit:     ' . $balance['creditLimit'] . "\n";
        echo '  balance:          ' . $balance['balance'] . "\n";
        echo '  available credit: ' . $balance['availableCredit'] . "\n";
    }

    $info = $client->domain()->info($domain);
    echo "\n" . $domain . "\n";
    echo '  status:  ' . implode(', ', $info->statuses() ?: ['-']) . "\n";
    echo '  expires: ' . ($info->value('exDate') ?? '-') . "\n";

    $prices = $info->prices();   // ['renewal'=>['value'=>…,'currency'=>…], 'restore'=>[…]]
    if ($prices !== []) {
        echo "  prices:\n";
        foreach ($prices as $op => $p) {
            echo '    ' . str_pad($op, 8) . ' ' . $p['value'] . ' ' . $p['currency'] . "\n";
        }
    }

    $ds = $info->dsRecords();    // DNSSEC DS records, if the domain is signed
    if ($ds === []) {
        echo "  DNSSEC:  unsigned\n";
    } else {
        echo '  DNSSEC:  ' . count($ds) . " DS record(s)\n";
        foreach ($ds as $d) {
            echo '    keyTag=' . $d['keyTag'] . ' alg=' . $d['alg'] . ' digestType=' . $d['digestType'] . "\n";
        }
    }

    $client->logout();
} catch (EppException $e) {
    fwrite(PHP_SAPI === 'cli' ? STDERR : fopen('php://output', 'wb'), 'EPP error: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $client->disconnect();
}
