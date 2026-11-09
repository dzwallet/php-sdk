<?php

namespace DzWallet\Sdk;

use DzWallet\Sdk\Data\IpnPayload;
use DzWallet\Sdk\Data\PaymentInitiated;
use DzWallet\Sdk\Data\PaymentLink;
use DzWallet\Sdk\Data\PaymentStatus;
use DzWallet\Sdk\Exceptions\ApiException;
use DzWallet\Sdk\Exceptions\ConfigurationException;
use DzWallet\Sdk\Exceptions\ConnectionException;
use DzWallet\Sdk\Exceptions\InvalidSignatureException;
use DzWallet\Sdk\Http\CurlTransport;
use DzWallet\Sdk\Http\Transport;
use InvalidArgumentException;

/**
 * DzWallet API client (no framework needed).
 *
 *   $dz = new \DzWallet\Sdk\Client([
 *       'mode' => 'test', 'public_key' => '...', 'secret_key' => '...',
 *   ]);
 */
class Client
{
    /** @var array */
    private $config;
    /** @var Transport */
    private $transport;

    /**
     * @param array{mode?:string,base_url?:string,public_key?:string,secret_key?:string,timeout?:int,status_retries?:int} $config
     */
    public function __construct(array $config, $transport = null)
    {
        $this->config = $config + [
            'mode' => 'test',
            'base_url' => 'https://dzwallet.app',
            'timeout' => 30,
            'status_retries' => 2,
        ];
        if ($transport !== null && !$transport instanceof Transport) {
            throw new InvalidArgumentException('transport must implement ' . Transport::class);
        }
        $this->transport = $transport ?: new CurlTransport();
    }

    public function mode()
    {
        return $this->config['mode'] === 'live' ? 'live' : 'test';
    }

    public function isTestMode()
    {
        return $this->mode() === 'test';
    }

    private function publicKey()
    {
        $k = isset($this->config['public_key']) ? trim((string) $this->config['public_key']) : '';
        if ($k === '') {
            throw new ConfigurationException('public_key is not set.');
        }
        return $k;
    }

    private function secretKey()
    {
        $k = isset($this->config['secret_key']) ? trim((string) $this->config['secret_key']) : '';
        if ($k === '') {
            throw new ConfigurationException('secret_key is not set.');
        }
        return $k;
    }

    private function url($path)
    {
        return rtrim((string) $this->config['base_url'], '/') . $path;
    }

    private function keyHeaders()
    {
        return ['X-Public-Key' => $this->publicKey(), 'X-Secret-Key' => $this->secretKey()];
    }

    /* ---------------- card payment ---------------- */

    /**
     * Starts a payment and returns the checkout address to send the customer to.
     * Required: identifier (max 20), amount, details (max 100), ipn_url, success_url, cancel_url, site_name,
     * customer[first_name, last_name, email, mobile]. Optional: currency (DZD), gateway_methods (["Satim"]),
     * site_logo, checkout_theme (light|dark), shipping_info, billing_info.
     *
     * NEVER retried automatically. After a ConnectionException call paymentStatus() with the same
     * identifier before creating another payment.
     */
    public function initiatePayment(array $params)
    {
        $params = $params + ['currency' => 'DZD', 'gateway_methods' => ['Satim']];
        $params['currency'] = strtoupper((string) $params['currency']);
        $this->validateInitiate($params);
        $params['public_key'] = $this->publicKey();
        $path = $this->isTestMode() ? '/test/payment/initiate' : '/payment/initiate';

        $json = $this->call('POST', $path, [], $params, false);
        $url = isset($json['redirect_url']) ? $json['redirect_url'] : null;
        if (!is_string($url) || $url === '') {
            throw new ApiException('The API did not return a checkout address.');
        }
        return new PaymentInitiated($url, (string) $params['identifier'], $json);
    }

    private function validateInitiate(array $p)
    {
        $errors = [];
        foreach (['identifier', 'details', 'ipn_url', 'success_url', 'cancel_url', 'site_name'] as $k) {
            if (!isset($p[$k]) || trim((string) $p[$k]) === '') {
                $errors[] = "$k is required";
            }
        }
        if (isset($p['identifier']) && strlen((string) $p['identifier']) > 20) {
            $errors[] = 'identifier must be at most 20 characters';
        }
        if (isset($p['details']) && strlen((string) $p['details']) > 100) {
            $errors[] = 'details must be at most 100 characters';
        }
        if (!isset($p['amount']) || !is_numeric($p['amount']) || (float) $p['amount'] <= 0) {
            $errors[] = 'amount must be a number greater than 0';
        }
        foreach (['ipn_url', 'success_url', 'cancel_url'] as $k) {
            if (isset($p[$k]) && !preg_match('#^https?://#i', (string) $p[$k])) {
                $errors[] = "$k must start with http:// or https://";
            }
        }
        $c = isset($p['customer']) ? $p['customer'] : null;
        if (!is_array($c)) {
            $errors[] = 'customer is required';
        } else {
            foreach (['first_name', 'last_name', 'email', 'mobile'] as $k) {
                if (!isset($c[$k]) || trim((string) $c[$k]) === '') {
                    $errors[] = "customer.$k is required";
                }
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
    }

    /** Current state of a payment. Only isPaid() proves the money was received. Safe to retry. */
    public function paymentStatus($identifier)
    {
        if ((string) $identifier === '') {
            throw new InvalidArgumentException('identifier is required');
        }
        $json = $this->call('POST', '/api/payment/status', [], ['public_key' => $this->publicKey(), 'identifier' => $identifier], true);
        $d = (isset($json['data']) && is_array($json['data'])) ? $json['data'] + $json : $json;
        if (!isset($d['payment_status']) || !is_string($d['payment_status'])) {
            throw new ApiException('The API did not return a payment status.');
        }
        return new PaymentStatus(
            $d['payment_status'],
            isset($d['identifier']) ? (string) $d['identifier'] : (string) $identifier,
            isset($d['amount']) ? (string) $d['amount'] : null,
            isset($d['currency']) ? $d['currency'] : null,
            isset($d['trx']) ? $d['trx'] : null,
            isset($d['created_at']) ? $d['created_at'] : null,
            $json
        );
    }

    /* ---------------- payment links (live keys) ---------------- */

    /**
     * @param float|int|string $amount
     * @param array{note?:string,days?:int,reference?:string,ipn_url?:string,email?:string} $options
     * The same reference with the same amount returns the same link.
     */
    public function createPaymentLink($amount, array $options = [])
    {
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new InvalidArgumentException('amount must be a number greater than 0');
        }
        if (isset($options['days']) && ((int) $options['days'] < 1 || (int) $options['days'] > 30)) {
            throw new InvalidArgumentException('days must be between 1 and 30');
        }
        if (isset($options['note']) && preg_match_all('/./us', (string) $options['note']) > 100) {
            throw new InvalidArgumentException('note must be at most 100 characters');
        }
        if (isset($options['reference']) && !preg_match('/^[A-Za-z0-9_-]{1,15}$/', (string) $options['reference'])) {
            throw new InvalidArgumentException('reference: 1-15 characters, letters, digits, _ and - only');
        }
        if (isset($options['email']) && !filter_var($options['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email is not valid');
        }
        $body = ['amount' => (string) $amount] + array_filter($options, function ($v) {
            return $v !== null && $v !== '';
        });
        return $this->makeLink($this->call('POST', '/api/payment-links', $this->keyHeaders(), $body, false));
    }

    /** Status of a link by reference (or transaction number). */
    public function paymentLink($reference)
    {
        return $this->makeLink($this->call('GET', '/api/payment-links/' . rawurlencode((string) $reference), $this->keyHeaders(), null, true));
    }

    /** Re-opens an expired or cancelled link (same link and QR code, validity restarts). */
    public function reactivatePaymentLink($reference)
    {
        return $this->makeLink($this->call('POST', '/api/payment-links/' . rawurlencode((string) $reference) . '/reactivate', $this->keyHeaders(), [], false));
    }

    private function makeLink(array $json)
    {
        $d = isset($json['data']) ? $json['data'] : null;
        if (!is_array($d) || empty($d['url'])) {
            throw new ApiException('The API did not return a payment link.');
        }
        $sent = array_key_exists('email_sent', $json) ? (bool) $json['email_sent'] : (array_key_exists('email_sent', $d) ? (bool) $d['email_sent'] : null);
        return new PaymentLink($d, $json, $sent);
    }

    /* ---------------- IPN ---------------- */

    /** signature = UPPERCASE(HMAC-SHA256(data.amount + identifier, SECRET_KEY)) */
    public function signature($amount, $identifier)
    {
        return strtoupper(hash_hmac('sha256', $amount . $identifier, $this->secretKey()));
    }

    /**
     * Verify the v2 notification signature on the EXACT raw request body.
     * Header: X-DzWallet-Signature: t=<unix>,v1=<hex>. Signed text: "<t>.<raw body>" with your secret key.
     * Rejects bad signatures and timestamps older/newer than $tolerance seconds.
     * Returns ['timestamp' => int, 'event_id' => string|null] (event_id comes from the signed body). Use it to ignore duplicates.
     *
     * @param string      $rawBody         file_get_contents('php://input'), untouched
     * @param string      $signatureHeader value of X-DzWallet-Signature
     * @param int         $tolerance       allowed clock difference in seconds (default 300)
     * @param int|null    $now             unix time (tests)
     * @param string|null $eventIdHeader   value of X-DzWallet-Event-Id
     */
    public function verifyWebhook($rawBody, $signatureHeader, $tolerance = 300, $now = null, $eventIdHeader = null)
    {
        $timestamp = null;
        $sigs = [];
        foreach (explode(',', (string) $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = ctype_digit($kv[1]) ? (int) $kv[1] : null;
            } elseif ($kv[0] === 'v1') {
                $sigs[] = strtolower($kv[1]);
            }
        }
        if ($timestamp === null || !$sigs) {
            throw new InvalidSignatureException('The signature header is missing or malformed.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secretKey());
        $ok = false;
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                $ok = true;
            }
        }
        if (!$ok) {
            throw new InvalidSignatureException('The notification signature is not valid.');
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - $timestamp) > (int) $tolerance) {
            throw new InvalidSignatureException('The notification timestamp is outside the allowed window.');
        }
        // Read the event id from the signed body: a header can be changed by anyone who captured the request.
        parse_str((string) $rawBody, $fields);
        $eventId = isset($fields['event_id']) ? (string) $fields['event_id'] : null;
        if ($eventIdHeader !== null && $eventId !== null && !hash_equals($eventId, (string) $eventIdHeader)) {
            throw new InvalidSignatureException('The event id header does not match the signed body.');
        }
        return ['timestamp' => $timestamp, 'event_id' => $eventId];
    }

    /**
     * Verifies a notification (pass $_POST) and returns it. Throws InvalidSignatureException when not authentic.
     * Pass the identifier and amount of YOUR order: a valid signature proves the message is from DzWallet,
     * comparing with your order proves it is the payment you wait for. Then check isSuccess().
     *
     * @param float|int|string|null $expectedAmount
     */
    public function verifyIpn(array $payload, $expectedIdentifier = null, $expectedAmount = null)
    {
        $status = isset($payload['status']) ? (string) $payload['status'] : '';
        $identifier = isset($payload['identifier']) ? (string) $payload['identifier'] : '';
        $signature = isset($payload['signature']) ? (string) $payload['signature'] : '';
        $data = isset($payload['data']) ? $payload['data'] : [];
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($data) || $identifier === '' || $signature === '' || !isset($data['amount'])) {
            throw new InvalidSignatureException('The notification is incomplete.');
        }
        if (!hash_equals($this->signature((string) $data['amount'], $identifier), strtoupper($signature))) {
            throw new InvalidSignatureException('The notification signature is not valid.');
        }
        if ($expectedIdentifier !== null && !hash_equals((string) $expectedIdentifier, $identifier)) {
            throw new InvalidSignatureException('The notification is for another order.');
        }
        if ($expectedAmount !== null && abs((float) $expectedAmount - (float) $data['amount']) > 0.005) {
            throw new InvalidSignatureException('The notification amount is not the expected amount.');
        }
        return new IpnPayload($status, $identifier, $data, $payload);
    }

    /** A unique order identifier of at most 20 characters. */
    public static function newIdentifier($prefix = 'DZ')
    {
        $prefix = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix), 0, 6);
        return substr($prefix . strtoupper(bin2hex(random_bytes(8))), 0, 20);
    }

    /* ---------------- transport ---------------- */

    private function call($method, $path, array $headers, $form, $retryable)
    {
        $attempts = $retryable ? max(0, (int) $this->config['status_retries']) + 1 : 1;
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                list($status, $body) = $this->transport->send($method, $this->url($path), $headers, $form, (int) $this->config['timeout']);
                return $this->parse($status, $body);
            } catch (ConnectionException $e) {
                $last = $e;
                if ($i + 1 < $attempts) {
                    usleep(300000);
                }
            }
        }
        throw $last;
    }

    private function parse($status, $body)
    {
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];
        $failed = $status < 200 || $status >= 300 || (isset($json['status']) && $json['status'] === 'error');
        if ($failed) {
            $errors = [];
            if (isset($json['message'])) {
                $errors = is_array($json['message']) ? array_map('strval', array_values($json['message'])) : [(string) $json['message']];
            }
            if (!$errors && isset($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $list) {
                    foreach ((array) $list as $line) {
                        $errors[] = (string) $line;
                    }
                }
            }
            throw new ApiException($errors ? implode('; ', $errors) : 'HTTP ' . $status, $errors, $status);
        }
        return $json;
    }
}
