# DzWallet PHP SDK

Plain PHP client for the [DzWallet](https://dzwallet.app) payment API: accept **CIB** and **Edahabia** card payments (through SATIM), create **payment links and QR codes**, check payment status and verify the signed payment notification (IPN). **No framework needed** (for Laravel use `dzwallet/laravel-sdk`).

- PHP 7.4+ with `curl` and `json`
- Test mode never contacts a real gateway
- Payments are never retried automatically, so a slow answer cannot create two payments

> Money is only received when the status is `success`. Never mark an order as paid because the customer reached your `success_url`: use the signed IPN, or call `paymentStatus()` from your server.

## Install

```bash
composer require dzwallet/php-sdk
```

No Composer? Copy the `src/` folder and `require` the files yourself, or use any PSR-4 autoloader for `DzWallet\Sdk\` => `src/`.

## Configure

```php
use DzWallet\Sdk\Client;

$dz = new Client([
    'mode'       => 'test',          // test | live
    'public_key' => '...',           // TEST public key in test mode, LIVE one in live mode
    'secret_key' => '...',           // matching secret key. Server side only, never in JavaScript, an app or a repository
]);
```

Keys: merchant dashboard, **Setting > API Key**. Live keys exist only after your identity (KYC) is verified. Register your website domain in the merchant profile (**Your website domains**): `ipn_url`, `success_url` and `cancel_url` must belong to it.

## Take a card payment

```php
$identifier = Client::newIdentifier('ORD');      // max 20 characters. Save it with your order first

$payment = $dz->initiatePayment([
    'identifier'  => $identifier,
    'amount'      => 2500,                       // DZD
    'details'     => 'Order #1042',              // max 100 characters
    'ipn_url'     => 'https://yourshop.dz/dzwallet-ipn.php',
    'success_url' => 'https://yourshop.dz/thanks.php',
    'cancel_url'  => 'https://yourshop.dz/cart.php',
    'site_name'   => 'My shop',
    'customer'    => ['first_name' => 'Amine', 'last_name' => 'B.', 'email' => 'amine@example.dz', 'mobile' => '0555000000'],
]);

header('Location: ' . $payment->redirectUrl);    // test mode: simulated page. Live mode: SATIM card page
exit;
```

## Receive the notification (IPN)

Use the [signed webhook example](examples/ipn.php). It verifies the v2 signature
against the raw request body, checks that the payment succeeded, and matches the
order, amount, and currency before recording payment. Implement
`record_payment_once` in your database so the event ID and order update commit
together. Return HTTP 200 after the transaction commits or when the event was
already processed.

The SDK also supports the legacy body signature through `verifyIpn()`. New
integrations should verify the v2 header with `verifyWebhook()` before using the
payment data. DzWallet makes up to 15 delivery attempts.

## Check a payment from your server

```php
$status = $dz->paymentStatus($identifier);
$status->isPaid();   // true only for "success"
$status->status;     // initiated | pending | success | rejected | cancelled
```

## Payment links and QR codes (live keys)

```php
$link = $dz->createPaymentLink(2500, ['reference' => 'ORD1042', 'days' => 7, 'note' => 'Room 12', 'ipn_url' => 'https://yourshop.dz/dzwallet-ipn.php']);
$link->url; $link->printUrl; $link->status; $link->isOpen();

$dz->paymentLink('ORD1042');              // status
$dz->reactivatePaymentLink('ORD1042');    // same link and QR code, validity restarts
```

## Errors

| Exception | When |
|---|---|
| `InvalidArgumentException` | a parameter is wrong; nothing was sent |
| `ConfigurationException` | a key is missing |
| `ApiException` | DzWallet answered with an error (`->errors`, `->httpStatus`, `->isRateLimited()`) |
| `ConnectionException` | timeout / network. After `initiatePayment` the result is **unknown**: call `paymentStatus()` with the same identifier before creating another payment |
| `InvalidSignatureException` | a notification is not authentic, or is for another order or amount |

All extend `DzWallet\Sdk\Exceptions\DzWalletException`. Limit: 60 requests per minute per IP (HTTP 429).

## Tests

```bash
php tests/run.php
```

The tests use a fake transport: they never call DzWallet and never create a payment.

MIT licence.
