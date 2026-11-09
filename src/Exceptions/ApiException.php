<?php

namespace DzWallet\Sdk\Exceptions;

class ApiException extends DzWalletException
{
    /** @var string[] */
    public $errors;
    /** @var int|null */
    public $httpStatus;

    public function __construct($message, array $errors = [], $httpStatus = null)
    {
        parent::__construct($message, (int) $httpStatus);
        $this->errors = $errors;
        $this->httpStatus = $httpStatus;
    }

    public function isRateLimited()
    {
        return $this->httpStatus === 429;
    }
}
