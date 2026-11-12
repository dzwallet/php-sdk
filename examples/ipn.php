<?php
// Notification receiver (the ipn_url). Answer HTTP 200 quickly.
require __DIR__ . '/../vendor/autoload.php';

use DzWallet\Sdk\Client;
use DzWallet\Sdk\Exceptions\InvalidSignatureException;

$dz = new Client(['mode' => 'test', 'public_key' => getenv('DZWALLET_PUBLIC_KEY'), 'secret_key' => getenv('DZWALLET_SECRET_KEY')]);

$raw = file_get_contents('php://input');
try {
    $meta = $dz->verifyWebhook($raw, $_SERVER['HTTP_X_DZWALLET_SIGNATURE'] ?? '');
    parse_str($raw, $payload);
    $ipn = $dz->verifyIpn($payload);
} catch (InvalidSignatureException $e) {
    http_response_code(403);
    exit;
}

$eventId = $meta['event_id'];
if (!$eventId || !$ipn->isSuccess()) {
    http_response_code(400);
    exit;
}

$order = find_order_by_identifier($ipn->identifier); // your database
if (!$order) {
    http_response_code(404);
    exit;
}

if (($ipn->data['currency'] ?? null) !== $order['currency'] ||
    abs((float) $ipn->amount() - (float) $order['total']) > 0.005) {
    http_response_code(400);
    exit;
}

// Implement in your database. Record the unique event ID and mark the
// order paid in one transaction. Treat a duplicate as already processed.
record_payment_once($eventId, $order['id'], $ipn->trx());
http_response_code(200);
echo 'OK';
