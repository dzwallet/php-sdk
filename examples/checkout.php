<?php
// Start a payment and redirect the customer. Run from a page of YOUR site.
require __DIR__ . '/../vendor/autoload.php';

use DzWallet\Sdk\Client;

$dz = new Client([
    'mode'       => 'test',                       // test | live
    'public_key' => getenv('DZWALLET_PUBLIC_KEY'),
    'secret_key' => getenv('DZWALLET_SECRET_KEY'), // server side only
]);

$identifier = Client::newIdentifier('ORD');       // save it with your order BEFORE redirecting

$payment = $dz->initiatePayment([
    'identifier'  => $identifier,
    'amount'      => 1000,
    'details'     => 'Order #1042',
    'ipn_url'     => 'https://yourshop.dz/dzwallet-ipn.php',
    'success_url' => 'https://yourshop.dz/thanks.php',
    'cancel_url'  => 'https://yourshop.dz/cart.php',
    'site_name'   => 'My shop',
    'customer'    => ['first_name' => 'Amine', 'last_name' => 'B.', 'email' => 'amine@example.dz', 'mobile' => '0555000000'],
]);

header('Location: ' . $payment->redirectUrl);
exit;
