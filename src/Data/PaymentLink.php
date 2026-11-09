<?php

namespace DzWallet\Sdk\Data;

class PaymentLink
{
    public $trx;
    public $amount;
    public $currency;
    /** @var string pending | paid | expired | cancelled */
    public $status;
    public $url;
    public $printUrl;
    public $expiresAt;
    public $reference;
    public $identifier;
    public $emailSent;
    public $raw;

    public function __construct(array $d, array $raw = [], $emailSent = null)
    {
        $this->trx = isset($d['trx']) ? (string) $d['trx'] : '';
        $this->amount = isset($d['amount']) ? (string) $d['amount'] : '';
        $this->currency = isset($d['currency']) ? (string) $d['currency'] : 'DZD';
        $this->status = isset($d['status']) ? (string) $d['status'] : '';
        $this->url = (string) $d['url'];
        $this->printUrl = isset($d['print_url']) ? $d['print_url'] : null;
        $this->expiresAt = isset($d['expires_at']) ? $d['expires_at'] : null;
        $this->reference = isset($d['reference']) ? $d['reference'] : null;
        $this->identifier = isset($d['identifier']) ? $d['identifier'] : null;
        $this->emailSent = $emailSent;
        $this->raw = $raw;
    }

    public function isOpen()
    {
        return $this->status === 'pending';
    }
}
