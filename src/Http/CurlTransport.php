<?php

namespace DzWallet\Sdk\Http;

use DzWallet\Sdk\Exceptions\ConnectionException;

class CurlTransport implements Transport
{
    public function send($method, $url, array $headers, $form, $timeout)
    {
        $ch = curl_init($url);
        $h = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?: []));
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new ConnectionException('Could not reach DzWallet: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $body];
    }
}
