<?php

namespace edrard\Turbosms\Exception;

/** Delivery may already have occurred. Retry only with the original sequence_id. */
class TransportException extends \RuntimeException
{
}
