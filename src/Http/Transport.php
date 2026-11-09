<?php

namespace DzWallet\Sdk\Http;

interface Transport
{
    /**
     * @param string               $method  GET or POST
     * @param string               $url     absolute URL
     * @param array<string,string> $headers header name => value
     * @param array|null           $form    form fields (nested arrays allowed), sent as application/x-www-form-urlencoded
     * @param int                  $timeout seconds
     * @return array{0:int,1:string}        [HTTP status, raw body]
     * @throws \DzWallet\Sdk\Exceptions\ConnectionException
     */
    public function send($method, $url, array $headers, $form, $timeout);
}
