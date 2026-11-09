<?php
// Dependency-free tests: php tests/run.php   (never calls DzWallet)
// autoload below (works without composer install)
spl_autoload_register(function ($c) {
    $p = 'DzWallet\\Sdk\\';
    if (strpos($c, $p) === 0) { $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; }
});

use DzWallet\Sdk\Client;
use DzWallet\Sdk\Http\Transport;
use DzWallet\Sdk\Exceptions\{ApiException, ConnectionException, ConfigurationException, InvalidSignatureException};

class FakeTransport implements Transport {
    public $calls = [];
    public $queue = [];
    public function send($method, $url, array $headers, $form, $timeout) {
        $this->calls[] = compact('method', 'url', 'headers', 'form');
        $r = array_shift($this->queue);
        if ($r instanceof Exception) throw $r;
        return $r;
    }
}

$pass = 0; $fail = 0;
function ok($cond, $name) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $name\n"; } }
function throws($class, callable $fn, $name) { try { $fn(); } catch (Throwable $e) { ok($e instanceof $class, "$name (got " . get_class($e) . ': ' . $e->getMessage() . ')'); return; } ok(false, "$name (nothing thrown)"); }

$cfg = ['mode' => 'test', 'public_key' => 'test_pub', 'secret_key' => 'test_sec', 'status_retries' => 2];
$good = ['identifier' => 'ORD1', 'amount' => 1000, 'details' => 'x', 'ipn_url' => 'https://a.dz/i', 'success_url' => 'https://a.dz/s', 'cancel_url' => 'https://a.dz/c', 'site_name' => 'S',
    'customer' => ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.dz', 'mobile' => '0555']];

// initiate (test mode -> test endpoint)
$t = new FakeTransport(); $c = new Client($cfg, $t);
$t->queue[] = [200, json_encode(['status' => 'success', 'redirect_url' => 'https://dzwallet.app/test/payment/checkout?x=1'])];
$p = $c->initiatePayment($good);
ok($p->redirectUrl === 'https://dzwallet.app/test/payment/checkout?x=1', 'redirect url');
ok(substr($t->calls[0]['url'], -22) === '/test/payment/initiate', 'test endpoint');
ok($t->calls[0]['form']['public_key'] === 'test_pub' && $t->calls[0]['form']['currency'] === 'DZD' && $t->calls[0]['form']['gateway_methods'] === ['Satim'], 'defaults');

// live mode -> live endpoint
$t = new FakeTransport(); $c = new Client(['mode' => 'live'] + $cfg, $t);
$t->queue[] = [200, json_encode(['status' => 'success', 'redirect_url' => 'https://x'])];
$c->initiatePayment($good);
ok(substr($t->calls[0]['url'], -17) === '/payment/initiate' && strpos($t->calls[0]['url'], '/test/') === false, 'live endpoint');

// validation
throws(InvalidArgumentException::class, function () use ($c, $good) { $b = $good; unset($b['customer']['email']); $c->initiatePayment($b); }, 'missing customer email');
throws(InvalidArgumentException::class, function () use ($c, $good) { $c->initiatePayment(['amount' => 0] + $good); }, 'zero amount');
throws(InvalidArgumentException::class, function () use ($c, $good) { $c->initiatePayment(['identifier' => str_repeat('A', 21)] + $good); }, 'long identifier');
throws(ConfigurationException::class, function () use ($good) { (new Client([], new FakeTransport()))->initiatePayment($good); }, 'missing public key');
throws(InvalidArgumentException::class, function () { new Client([], new stdClass()); }, 'bad transport');

// API error -> ApiException with message and status
$t = new FakeTransport(); $c = new Client($cfg, $t);
$t->queue[] = [401, json_encode(['status' => 'error', 'message' => ['Invalid api key']])];
try { $c->initiatePayment($good); ok(false, 'api error thrown'); } catch (ApiException $e) { ok($e->getMessage() === 'Invalid api key' && $e->httpStatus === 401 && $e->errors === ['Invalid api key'], 'api error content'); }
$t->queue[] = [429, '{}'];
try { $c->paymentStatus('X'); } catch (ApiException $e) { ok($e->isRateLimited(), 'rate limited'); }

// initiate is NOT retried on connection error
$t = new FakeTransport(); $c = new Client($cfg, $t);
$t->queue[] = new ConnectionException('timeout'); $t->queue[] = [200, json_encode(['redirect_url' => 'https://x'])];
throws(ConnectionException::class, function () use ($c, $good) { $c->initiatePayment($good); }, 'initiate connection error');
ok(count($t->calls) === 1, 'initiate not retried');

// status IS retried
$t = new FakeTransport(); $c = new Client($cfg, $t);
$t->queue[] = new ConnectionException('t1'); $t->queue[] = new ConnectionException('t2');
$t->queue[] = [200, json_encode(['status' => 'success', 'payment_status' => 'success', 'identifier' => 'ORD1', 'amount' => '1000.00000000', 'trx' => 'T1'])];
$s = $c->paymentStatus('ORD1');
ok($s->isPaid() && $s->isFinal() && $s->trx === 'T1' && count($t->calls) === 3, 'status retried 2x then ok');
$t->queue[] = [200, json_encode(['payment_status' => 'pending'])];
ok(!$c->paymentStatus('ORD2')->isPaid(), 'pending is not paid');

// payment links
$link = ['status' => 'success', 'message' => 'Payment link created', 'data' => ['trx' => 'H3K9', 'amount' => 2500, 'currency' => 'DZD', 'status' => 'pending', 'url' => 'https://dzwallet.app/qr/H3K9/abc', 'print_url' => 'https://dzwallet.app/qr/H3K9/abc/code', 'reference' => 'ORD1042', 'identifier' => 'QRA03ORD1042']];
$t = new FakeTransport(); $c = new Client($cfg, $t);
$t->queue[] = [200, json_encode($link)];
$l = $c->createPaymentLink(2500, ['reference' => 'ORD1042', 'days' => 3]);
ok($l->isOpen() && $l->url === 'https://dzwallet.app/qr/H3K9/abc' && $l->identifier === 'QRA03ORD1042', 'link created');
ok($t->calls[0]['headers']['X-Secret-Key'] === 'test_sec' && $t->calls[0]['form']['amount'] === '2500', 'link headers+body');
$t->queue[] = [200, json_encode($link)]; $c->paymentLink('ORD 1'); ok(substr($t->calls[1]['url'], -22) === '/api/payment-links/ORD%201' || strpos($t->calls[1]['url'], 'ORD%201') !== false, 'reference url-encoded');
$t->queue[] = [200, json_encode($link)]; $c->reactivatePaymentLink('ORD1042'); ok(substr($t->calls[2]['url'], -11) === '/reactivate', 'reactivate url');
throws(InvalidArgumentException::class, function () use ($c) { $c->createPaymentLink(100, ['reference' => 'bad ref!']); }, 'bad reference');
throws(InvalidArgumentException::class, function () use ($c) { $c->createPaymentLink(100, ['days' => 31]); }, 'days > 30');

// IPN
$c = new Client($cfg, new FakeTransport());
$sig = strtoupper(hash_hmac('sha256', '1000.00000000' . 'ORD1', 'test_sec'));
$ipn = ['status' => 'success', 'signature' => $sig, 'identifier' => 'ORD1', 'data' => ['payment_trx' => 'T1', 'amount' => '1000.00000000', 'currency' => 'DZD']];
$r = $c->verifyIpn($ipn, 'ORD1', 1000);
ok($r->isSuccess() && $r->trx() === 'T1' && $r->amount() === '1000.00000000', 'ipn ok');
ok($c->verifyIpn(['signature' => strtolower($sig)] + $ipn)->identifier === 'ORD1', 'lowercase signature accepted');
throws(InvalidSignatureException::class, function () use ($c, $ipn) { $c->verifyIpn(['signature' => 'AAAA'] + $ipn); }, 'bad signature');
throws(InvalidSignatureException::class, function () use ($c, $ipn) { $c->verifyIpn($ipn, 'OTHER', 1000); }, 'other order');
throws(InvalidSignatureException::class, function () use ($c, $ipn) { $c->verifyIpn($ipn, 'ORD1', 999); }, 'other amount');
throws(InvalidSignatureException::class, function () use ($c) { $c->verifyIpn(['status' => 'success']); }, 'incomplete');
$bad = $ipn; $bad['data']['amount'] = '1.00000000';
throws(InvalidSignatureException::class, function () use ($c, $bad) { $c->verifyIpn($bad); }, 'tampered amount');
$rej = ['status' => 'rejected'] + $ipn; ok(!$c->verifyIpn($rej)->isSuccess(), 'signed but rejected is not success');

// v2 webhook: raw body + timestamp
$raw = 'event=payment.succeeded&event_id=evt_abc&status=success&identifier=ORD1&timestamp=1700000000';
$mk = function ($ts, $body) { return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, 'test_sec'); };
$w = $c->verifyWebhook($raw, $mk(1700000000, $raw), 300, 1700000100);
ok($w['event_id'] === 'evt_abc' && $w['timestamp'] === 1700000000, 'webhook ok');
throws(InvalidSignatureException::class, function () use ($c, $raw, $mk) { $c->verifyWebhook($raw . 'x', $mk(1700000000, $raw), 300, 1700000100); }, 'webhook body tampered');
throws(InvalidSignatureException::class, function () use ($c, $raw, $mk) { $c->verifyWebhook($raw, $mk(1700000000, $raw), 300, 1700001000); }, 'webhook too old');
throws(InvalidSignatureException::class, function () use ($c, $raw, $mk) { $c->verifyWebhook($raw, $mk(1700000000, $raw), 300, 1699999000); }, 'webhook from the future');
throws(InvalidSignatureException::class, function () use ($c, $raw) { $c->verifyWebhook($raw, 'garbage', 300, 1700000100); }, 'webhook bad header');
throws(InvalidSignatureException::class, function () use ($c, $raw, $mk) { $c->verifyWebhook($raw, $mk(1700000000, $raw), 300, 1700000100, 'evt_other'); }, 'event id header swapped');
throws(InvalidSignatureException::class, function () use ($c, $raw) { $c->verifyWebhook($raw, 't=1700000000,v1=' . str_repeat('0', 64), 300, 1700000100); }, 'webhook wrong sig');

// identifier
$id = Client::newIdentifier('ORD'); ok(strlen($id) <= 20 && strpos($id, 'ORD') === 0, 'identifier');
ok(strlen(Client::newIdentifier('A-B_C!!!!!!')) <= 20, 'identifier sanitized');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
