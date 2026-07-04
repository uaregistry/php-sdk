<?php

declare(strict_types=1);

/**
 * Offline self-test: exercises frame building and response parsing with a fake
 * in-memory transport — no server, no network. Run it with:
 *
 *     php sdk/tests/offline_test.php
 *
 * Written to the SDK's PHP 7.4 floor (no named args, no nullsafe operator, etc.).
 */

require __DIR__ . '/../autoload.php';

use UARegistry\Sdk\Client;
use UARegistry\Sdk\Config;
use UARegistry\Sdk\Exception\CommandException;
use UARegistry\Sdk\Namespaces;
use UARegistry\Sdk\ResultCode;
use UARegistry\Sdk\Transport;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok): void
{
    global $pass, $fail;
    echo ($ok ? "  ok  " : " FAIL ") . $label . "\n";
    $ok ? $pass++ : $fail++;
}

/** A transport that records what was written and replays queued responses. */
final class FakeTransport implements Transport
{
    /** @var string[] */
    public array $written = [];
    /** @var string[] */
    public array $queue = [];
    private bool $open = false;

    public function open(): void
    {
        $this->open = true;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function writeFrame(string $xml): void
    {
        $this->written[] = $xml;
    }

    public function readFrame(): string
    {
        if ($this->queue === []) {
            throw new RuntimeException('FakeTransport: no queued response');
        }

        return (string) array_shift($this->queue);
    }

    public function close(): void
    {
        $this->open = false;
    }
}

/**
 * @param string[] $responses
 * @return array{0: Client, 1: FakeTransport}
 */
function makeClient(array $responses): array
{
    $fake = new FakeTransport();
    $fake->queue = $responses;
    $config = Config::fromArray(['host' => 'epp.example', 'clid' => 'UAR0001', 'password' => 'secret']);
    $client = new Client($config, $fake);

    return [$client, $fake];
}

/** Load a frame for inspection with the SDK prefixes registered. */
function xp(string $xml): DOMXPath
{
    $dom = new DOMDocument();
    if ($dom->loadXML($xml) === false) {
        throw new RuntimeException('written frame is not well-formed XML');
    }
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('e', Namespaces::EPP);
    $xpath->registerNamespace('domain', Namespaces::DOMAIN);
    $xpath->registerNamespace('contact', Namespaces::CONTACT);
    $xpath->registerNamespace('host', Namespaces::HOST);
    $xpath->registerNamespace('secDNS', Namespaces::SECDNS);
    $xpath->registerNamespace('rgp', Namespaces::RGP);
    $xpath->registerNamespace('uareg', Namespaces::UAREG_EXT);

    return $xpath;
}

/** First matching node's text, or null. */
function firstText(DOMXPath $xpath, string $query): ?string
{
    $node = $xpath->query($query)->item(0);

    return $node !== null ? $node->textContent : null;
}

$GREETING = '<?xml version="1.0" encoding="UTF-8"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><greeting>'
    . '<svID>UARegistry EPP</svID><svDate>2026-06-15T00:00:00Z</svDate><svcMenu><version>1.0</version><lang>en</lang>'
    . '<objURI>urn:ietf:params:xml:ns:epp-1.0</objURI><objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>'
    . '<objURI>urn:ietf:params:xml:ns:domain-1.0</objURI><objURI>urn:ietf:params:xml:ns:host-1.0</objURI>'
    . '<svcExtension><extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI><extURI>urn:ietf:params:xml:ns:rgp-1.0</extURI>'
    . '<extURI>http://uaregistry.com/epp/uaregistry-1.0</extURI><extURI>http://uaregistry.com/epp/balance-1.0</extURI>'
    . '</svcExtension></svcMenu></greeting></epp>';

$OK = static function (int $code = 1000): string {
    return '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
        . '<result code="' . $code . '"><msg>ok</msg></result><trID><svTRID>UA-1</svTRID></trID></response></epp>';
};

echo "greeting + login\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$greeting = $client->connect();
check('greeting parsed', $greeting->isGreeting());
check('serviceObjUris has domain', in_array(Namespaces::DOMAIN, $greeting->serviceObjUris(), true));
check('serviceExtUris has uareg ext', in_array(Namespaces::UAREG_EXT, $greeting->serviceExtUris(), true));
$client->login();
$loginXp = xp($fake->written[0]);
check('login carries clID', firstText($loginXp, '//e:login/e:clID') === 'UAR0001');
check('login version 1.0', firstText($loginXp, '//e:options/e:version') === '1.0');
$objUris = [];
foreach ($loginXp->query('//e:svcs/e:objURI') as $n) {
    $objUris[] = $n->textContent;
}
check('login objURIs exclude epp base', !in_array(Namespaces::EPP, $objUris, true));
check('login objURIs include domain/contact/host', $objUris === [Namespaces::CONTACT, Namespaces::DOMAIN, Namespaces::HOST]);
check('login extURIs include uareg', $loginXp->query('//e:svcExtension/e:extURI[text()="' . Namespaces::UAREG_EXT . '"]')->length === 1);

echo "login password rotation (newPW)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->login('BrandNewPass1');
$lx = xp($fake->written[0]);
check('login carries newPW', firstText($lx, '//e:login/e:newPW') === 'BrandNewPass1');

echo "domain:check + availability\n";
$checkResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:chkData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:cd><domain:name avail="1">free.com.ua</domain:name></domain:cd>'
    . '<domain:cd><domain:name avail="0">taken.com.ua</domain:name><domain:reason>in use</domain:reason></domain:cd>'
    . '</domain:chkData></resData><trID><svTRID>UA-2</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $checkResp]);
$client->connect();
$resp = $client->domain()->check(['free.com.ua', 'taken.com.ua']);
$avail = $resp->availability();
check('check avail: free=true', ($avail['free.com.ua'] ?? null) === true);
check('check avail: taken=false', ($avail['taken.com.ua'] ?? null) === false);
$checkXp = xp($fake->written[0]);
check('check frame has 2 domain:name', $checkXp->query('//domain:check/domain:name')->length === 2);

echo "domain:create with licence + secDNS\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('brand.ua', [
    'years' => 2,
    'registrant' => 'C1',
    'contacts' => ['admin' => 'C1', 'tech' => 'C2'],
    'nameservers' => ['ns1.brand.ua', 'ns2.brand.ua'],
    'authInfo' => 'Sup3r&Secret<>',
    'license' => 'TM-12345',
    'secDNS' => ['maxSigLife' => 604800, 'dsData' => [['keyTag' => 12345, 'alg' => 8, 'digestType' => 2, 'digest' => 'ABCDEF']]],
]);
$cx = xp($fake->written[0]); // also proves the frame is well-formed despite & < > in authInfo
check('create name', firstText($cx, '//domain:create/domain:name') === 'brand.ua');
check('create period unit=y', firstText($cx, '//domain:period[@unit="y"]') === '2');
check('create hostObj x2', $cx->query('//domain:ns/domain:hostObj')->length === 2);
check('create contact type=admin', firstText($cx, '//domain:contact[@type="admin"]') === 'C1');
check('create authInfo escaped round-trip', firstText($cx, '//domain:authInfo/domain:pw') === 'Sup3r&Secret<>');
check('create licence wrapper uareg:create>license', firstText($cx, '//e:extension/uareg:create/uareg:license') === 'TM-12345');
check('create secDNS dsData keyTag', firstText($cx, '//secDNS:create/secDNS:dsData/secDNS:keyTag') === '12345');
check('create secDNS maxSigLife', firstText($cx, '//secDNS:create/secDNS:maxSigLife') === '604800');

echo "domain create WITHOUT authInfo still emits the RFC-mandatory <authInfo><pw/>\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('noauth.ua', ['years' => 1, 'registrant' => 'C1', 'contacts' => ['admin' => 'C1', 'tech' => 'C2'], 'nameservers' => ['ns1.noauth.ua']]);
$nx = xp($fake->written[0]);
check('create without authInfo still emits <domain:authInfo>', $nx->query('//domain:create/domain:authInfo')->length === 1);
$noauthPw = firstText($nx, '//domain:authInfo/domain:pw');
check('create without authInfo emits an empty <domain:pw>', $noauthPw === null || $noauthPw === '');

echo "contact create WITHOUT email throws a clear client-side error (not an opaque server 2005)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$emailThrew = false;
try {
    $client->contact()->create('C9', ['name' => 'Jane', 'city' => 'Lviv', 'cc' => 'UA']); // email omitted
} catch (\InvalidArgumentException $e) {
    $emailThrew = true;
}
check('contact create without email throws InvalidArgumentException', $emailThrew);

echo "domain:update restore (rgp, no add/rem/chg)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->restore('redeem.com.ua');
$ux = xp($fake->written[0]);
check('restore rgp op=request', $ux->query('//e:extension/rgp:update/rgp:restore[@op="request"]')->length === 1);
check('restore has no domain:chg', $ux->query('//domain:chg')->length === 0);
check('restore has no domain:add', $ux->query('//domain:add')->length === 0);

echo "error handling\n";
[$client, $fake] = makeClient([$GREETING, $OK(2303)]);
$client->connect();
$threw = false;
try {
    $client->domain()->info('nope.com.ua');
} catch (CommandException $e) {
    $threw = ($e->eppCode === 2303);
}
check('2303 throws CommandException with eppCode', $threw);

[$client, $fake] = makeClient([$GREETING, $OK(2303)]);
$client->connect();
$client->throwOnFailure(false);
$resp = $client->domain()->info('nope.com.ua');
check('throwOnFailure(false): returns Response', $resp->code() === 2303 && !$resp->isSuccess());

echo "domain:update secDNS (add / rem-all / maxSigLife)\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->update('dnssec.ua', [
    'secDNS' => [
        'add' => ['dsData' => [['keyTag' => 1, 'alg' => 8, 'digestType' => 2, 'digest' => 'AA']]],
        'remAll' => true,
        'maxSigLife' => 1209600,
    ],
]);
$sx = xp($fake->written[0]);
check('secDNS:update rem all=true', firstText($sx, '//secDNS:update/secDNS:rem/secDNS:all') === 'true');
check('secDNS:update add dsData keyTag', firstText($sx, '//secDNS:update/secDNS:add/secDNS:dsData/secDNS:keyTag') === '1');
check('secDNS:update chg maxSigLife', firstText($sx, '//secDNS:update/secDNS:chg/secDNS:maxSigLife') === '1209600');

echo "poll messageId/count + ack\n";
$pollResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1301"><msg>ack to dequeue</msg></result>'
    . '<msgQ count="3" id="12"><qDate>2026-06-15T00:00:00Z</qDate><msg>Domain transferred</msg></msgQ>'
    . '<trID><svTRID>UA-9</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $pollResp, $OK()]);
$client->connect();
$poll = $client->poll()->request();
check('poll messageId', $poll->messageId() === '12');
check('poll messageCount', $poll->messageCount() === 3);
$client->poll()->ack($poll->messageId());
$ax = xp($fake->written[1]);
check('pollAck carries msgID', $ax->query('//e:poll[@op="ack"][@msgID="12"]')->length === 1);

echo "info statuses\n";
$infoResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>x.com.ua</domain:name><domain:status s="ok"/><domain:status s="clientHold"/>'
    . '<domain:exDate>2027-01-15T00:00:00+02:00</domain:exDate></domain:infData></resData>'
    . '<trID><svTRID>UA-7</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $infoResp]);
$client->connect();
$info = $client->domain()->info('x.com.ua');
check('statuses from @s', $info->statuses() === ['ok', 'clientHold']);
check('value exDate', $info->value('exDate') === '2027-01-15T00:00:00+02:00');

echo "error reasons + ResultCode\n";
$errResp = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="2306"><msg>Policy error</msg><extValue><value>x.closed.ua</value>'
    . '<reason>Zone is closed for new registrations</reason></extValue></result>'
    . '<trID><svTRID>UA-8</svTRID></trID></response></epp>';
[$client, $fake] = makeClient([$GREETING, $errResp]);
$client->connect();
$client->throwOnFailure(false);
$r = $client->domain()->create('x.closed.ua', ['years' => 1]);
check('ResultCode constant matches', $r->code() === ResultCode::PARAMETER_VALUE_POLICY_ERROR);
check('errorReasons parsed', $r->errorReasons() === ['Zone is closed for new registrations']);

echo "contact create: postalInfo (int+loc) + disclose\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->create('c1', [
    'postalInfos' => [
        ['type' => 'int', 'name' => 'ACME', 'street' => ['1 St'], 'city' => 'Kyiv', 'cc' => 'UA'],
        ['type' => 'loc', 'name' => "\u{0410}\u{041A}\u{041C}\u{0415}", 'city' => "\u{041A}\u{0438}\u{0457}\u{0432}", 'cc' => 'UA'],
    ],
    'email' => 'a@b.ua',
    'authInfo' => 'pw',
    'disclose' => ['flag' => false, 'addr' => ['int'], 'voice' => true, 'email' => true],
]);
$kx = xp($fake->written[0]);
check('contact 2 postalInfo blocks', $kx->query('//contact:create/contact:postalInfo')->length === 2);
check('contact postalInfo loc name', firstText($kx, '//contact:postalInfo[@type="loc"]/contact:name') === "\u{0410}\u{041A}\u{041C}\u{0415}");
check('contact disclose flag=0', $kx->query('//contact:disclose[@flag="0"]')->length === 1);
check('contact disclose addr type=int', $kx->query('//contact:disclose/contact:addr[@type="int"]')->length === 1);
check('contact disclose voice flag present', $kx->query('//contact:disclose/contact:voice')->length === 1);

echo "contact update: chg postalInfo + disclose\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->update('c1', [
    'chg' => [
        'postalInfo' => ['type' => 'int', 'name' => 'New Name', 'city' => 'Lviv', 'cc' => 'UA'],
        'email' => 'new@b.ua',
        'disclose' => ['flag' => true, 'email' => true],
    ],
]);
$ucx = xp($fake->written[0]);
check('contact chg postalInfo name', firstText($ucx, '//contact:chg/contact:postalInfo/contact:name') === 'New Name');
check('contact chg disclose flag=1', $ucx->query('//contact:chg/contact:disclose[@flag="1"]')->length === 1);

echo "contact update: multiple statuses collapse into a single add/rem block\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->contact()->update('c1', [
    'addStatuses' => ['clientUpdateProhibited', 'clientDeleteProhibited'],
    'remStatuses' => ['clientTransferProhibited', 'clientUpdateProhibited'],
]);
$mcx = xp($fake->written[0]);
check('contact update: single add wrapper', $mcx->query('//contact:add')->length === 1);
check('contact update: both statuses inside add', $mcx->query('//contact:add/contact:status')->length === 2);
check('contact update: single rem wrapper', $mcx->query('//contact:rem')->length === 1);
check('contact update: both statuses inside rem', $mcx->query('//contact:rem/contact:status')->length === 2);

echo "domain create: empty secDNS array emits no childless secDNS:create\n";
[$client, $fake] = makeClient([$GREETING, $OK()]);
$client->connect();
$client->domain()->create('nosec.ua', [
    'years' => 1, 'registrant' => 'REG1', 'contacts' => ['admin' => 'A1', 'tech' => 'T1'],
    'nameservers' => ['ns1.x.ua'], 'secDNS' => [],
]);
$nsx = xp($fake->written[0]);
check('empty secDNS -> no secDNS:create element', $nsx->query('//secDNS:create')->length === 0);

echo "config guards (fail fast, no network)\n";
$badPw = new Client(Config::fromArray(['host' => 'h', 'clid' => 'UAR1', 'password' => '']), $fakePw = new FakeTransport());
$fakePw->queue = [$GREETING];
$badPw->connect();
$threwPw = false;
try {
    $badPw->login();
} catch (\UARegistry\Sdk\Exception\ConfigException $e) {
    $threwPw = true;
}
check('empty password -> ConfigException', $threwPw);
check('no login frame sent', $fakePw->written === []);

$badHost = new Client(Config::fromArray(['host' => '', 'clid' => 'x', 'password' => 'y']), new FakeTransport());
$threwHost = false;
try {
    $badHost->connect();
} catch (\UARegistry\Sdk\Exception\ConfigException $e) {
    $threwHost = true;
}
check('empty host -> ConfigException', $threwHost);

echo "log redaction\n";
[$client] = makeClient([]);
$redact = new ReflectionMethod(Client::class, 'redact');
if (\PHP_VERSION_ID < 80100) {
    $redact->setAccessible(true); // required before 8.1; a no-op (and deprecated on 8.5) after
}
$masked = $redact->invoke($client, '<pw>topsecret</pw><domain:pw>auth123</domain:pw><domain:name>keep.ua</domain:name>');
check('pw masked', strpos($masked, 'topsecret') === false && strpos($masked, 'auth123') === false);
check('non-secret kept', strpos($masked, 'keep.ua') !== false);

echo "response accessors: balance / price / licence / rgp / lang\n";

$balanceXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg lang="uk">Команду виконано успішно</msg></result>'
    . '<resData><balance:infData xmlns:balance="http://uaregistry.com/epp/balance-1.0">'
    . '<balance:creditLimit>0.00</balance:creditLimit><balance:balance>1234.56</balance:balance>'
    . '<balance:availableCredit>1234.56</balance:availableCredit></balance:infData></resData>'
    . '<trID><svTRID>UA-2</svTRID></trID></response></epp>';
$bal = \UARegistry\Sdk\Response::fromXml($balanceXml);
$b = $bal->balance();
check('balance() creditLimit', $b !== null && $b['creditLimit'] === '0.00');
check('balance() balance', $b !== null && $b['balance'] === '1234.56');
check('balance() availableCredit', $b !== null && $b['availableCredit'] === '1234.56');
check('messageLang() reads uk', $bal->messageLang() === 'uk');

$infoXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg lang="en">Command completed successfully</msg></result>'
    . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example.com.ua</domain:name><domain:status s="ok"/></domain:infData></resData>'
    . '<extension>'
    . '<uareg:infData xmlns:uareg="http://uaregistry.com/epp/uaregistry-1.0"><uareg:license>TM-123</uareg:license></uareg:infData>'
    . '<uaregistry:priceData xmlns:uaregistry="http://uaregistry.com/epp/uaregistry-1.0" channel="7">'
    . '<uaregistry:price operation="renewal" currency="UAH">180.00</uaregistry:price>'
    . '<uaregistry:price operation="restore" currency="UAH">1200.00</uaregistry:price></uaregistry:priceData>'
    . '<rgp:infData xmlns:rgp="urn:ietf:params:xml:ns:rgp-1.0"><rgp:rgpStatus s="redemptionPeriod"/></rgp:infData>'
    . '</extension><trID><svTRID>UA-3</svTRID></trID></response></epp>';
$info = \UARegistry\Sdk\Response::fromXml($infoXml);
check('license() reads the .ua licence', $info->license() === 'TM-123');
$prices = $info->prices();
check('prices() renewal value', isset($prices['renewal']) && $prices['renewal']['value'] === '180.00');
check('prices() renewal currency', isset($prices['renewal']) && $prices['renewal']['currency'] === 'UAH');
check('prices() restore value', isset($prices['restore']) && $prices['restore']['value'] === '1200.00');
check('rgpStatus() reads redemptionPeriod', $info->rgpStatus() === ['redemptionPeriod']);
check('balance() null on a non-balance response', $info->balance() === null);

$trnXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1001"><msg>ok</msg></result>'
    . '<resData><domain:trnData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
    . '<domain:name>example.com.ua</domain:name><domain:trStatus>pending</domain:trStatus></domain:trnData></resData>'
    . '<trID><svTRID>UA-4</svTRID></trID></response></epp>';
check('transferStatus() reads pending', \UARegistry\Sdk\Response::fromXml($trnXml)->transferStatus() === 'pending');

echo "response accessors: secDNS read-back\n";
$secXml = '<?xml version="1.0"?><epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response>'
    . '<result code="1000"><msg>ok</msg></result>'
    . '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"><domain:name>signed.com.ua</domain:name></domain:infData></resData>'
    . '<extension><secDNS:infData xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">'
    . '<secDNS:dsData><secDNS:keyTag>12345</secDNS:keyTag><secDNS:alg>13</secDNS:alg>'
    . '<secDNS:digestType>2</secDNS:digestType><secDNS:digest>ABCDEF0123</secDNS:digest></secDNS:dsData>'
    . '<secDNS:keyData><secDNS:flags>257</secDNS:flags><secDNS:protocol>3</secDNS:protocol>'
    . '<secDNS:alg>13</secDNS:alg><secDNS:pubKey>AwEAAb</secDNS:pubKey></secDNS:keyData>'
    . '</secDNS:infData></extension><trID><svTRID>UA-5</svTRID></trID></response></epp>';
$sec = \UARegistry\Sdk\Response::fromXml($secXml);
$dsr = $sec->dsRecords();
check('dsRecords() count', count($dsr) === 1);
check('dsRecords() keyTag (int)', isset($dsr[0]) && $dsr[0]['keyTag'] === 12345);
check('dsRecords() digestType (int)', isset($dsr[0]) && $dsr[0]['digestType'] === 2);
check('dsRecords() digest', isset($dsr[0]) && $dsr[0]['digest'] === 'ABCDEF0123');
$kr = $sec->keyRecords();
check('keyRecords() flags (int)', isset($kr[0]) && $kr[0]['flags'] === 257);
check('keyRecords() pubKey', isset($kr[0]) && $kr[0]['pubKey'] === 'AwEAAb');
check('isSigned() true when signed', $sec->isSigned() === true);
check('isSigned() false on a non-DNSSEC info', $info->isSigned() === false);

echo "domain info hosts=sub\n";
[$clientHS, $fakeHS] = makeClient([$GREETING, $OK(), $OK()]);
$clientHS->connect();
$clientHS->login();
$clientHS->domain()->info('example.com.ua', null, 'sub');
$hsFrame = xp(end($fakeHS->written));
$hostsAttr = $hsFrame->query('//domain:info/domain:name/@hosts')->item(0);
check('info hosts=sub attribute', $hostsAttr !== null && $hostsAttr->nodeValue === 'sub');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
