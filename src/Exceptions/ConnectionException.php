<?php

namespace DzWallet\Sdk\Exceptions;

/** Timeout or network error. For initiatePayment() the outcome is UNKNOWN: check paymentStatus() first. */
class ConnectionException extends DzWalletException
{
}
