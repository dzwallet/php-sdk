<?php

namespace DzWallet\Sdk\Data;

class PaymentInitiated
{
    /** @var string address to send the customer to */
    public $redirectUrl;
    /** @var string */
    public $identifier;
    /** @var array */
    public $raw;

    public function __construct($redirectUrl, $identifier, array $raw = [])
    {
        $this->redirectUrl = $redirectUrl;
        $this->identifier = $identifier;
        $this->raw = $raw;
    }
}
