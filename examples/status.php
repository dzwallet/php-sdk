<?php
// Thank-you page: never trust success_url alone, ask DzWallet.
require __DIR__ . '/../vendor/autoload.php';

use DzWallet\Sdk\Client;

$dz = new Client(['mode' => 'test', 'public_key' => getenv('DZWALLET_PUBLIC_KEY'), 'secret_key' => getenv('DZWALLET_SECRET_KEY')]);
$status = $dz->paymentStatus($_GET['id']);
echo $status->isPaid() ? 'Paid' : 'Status: ' . $status->status;
