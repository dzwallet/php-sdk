<?php

namespace DzWallet\Sdk\Data;

class IpnPayload
{
    public $status;
    public $identifier;
    /** @var array data[] fields: payment_trx, amount, payment_type, payment_timestamp, currency */
    public $data;
    public $raw;

    public function __construct($status, $identifier, array $data, array $raw = [])
    {
        $this->status = $status;
        $this->identifier = $identifier;
        $this->data = $data;
        $this->raw = $raw;
    }

    /** Signed AND successful. A signed notification can still say rejected or cancelled. */
    public function isSuccess()
    {
        return $this->status === 'success';
    }

    public function amount()
    {
        return isset($this->data['amount']) ? (string) $this->data['amount'] : '';
    }

    public function trx()
    {
        return isset($this->data['payment_trx']) ? (string) $this->data['payment_trx'] : null;
    }
}
