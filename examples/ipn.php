<?php
// Notification receiver (the ipn_url). Answer HTTP 200 quickly.
require __DIR__ . '/../vendor/autoload.php';

use DzWallet\Sdk\Client;
use DzWallet\Sdk\Exceptions\InvalidSignatureException;

$dz = new Client(['mode' => 'test', 'public_key' => getenv('DZWALLET_PUBLIC_KEY'), 'secret_key' => getenv('DZWALLET_SECRET_KEY')]);

// Load YOUR order by the identifier first (replace with your own database code).
$order = find_order_by_identifier($_POST['identifier'] ?? '');
if (!$order) { http_response_code(404); exit; }

try {
    $ipn = $dz->verifyIpn($_POST, $order['identifier'], $order['total']);   // signature + identifier + amount
} catch (InvalidSignatureException $e) {
    http_response_code(403);
    exit;
}

if ($ipn->isSuccess() && !$order['paid']) {          // idempotent: the same IPN can arrive twice
    mark_order_paid($order['id'], $ipn->trx());
}
echo 'OK';
