<?php

namespace DzWallet\Sdk\Data;

class PaymentStatus
{
    /** @var string initiated | pending | success | rejected | cancelled */
    public $status;
    public $identifier;
    public $amount;
    public $currency;
    public $trx;
    public $createdAt;
    public $raw;

    public function __construct($status, $identifier, $amount = null, $currency = null, $trx = null, $createdAt = null, array $raw = [])
    {
        $this->status = $status;
        $this->identifier = $identifier;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->trx = $trx;
        $this->createdAt = $createdAt;
        $this->raw = $raw;
    }

    /** True only when the money was received. */
    public function isPaid()
    {
        return $this->status === 'success';
    }

    public function isFinal()
    {
        return in_array($this->status, ['success', 'rejected', 'cancelled'], true);
    }
}
