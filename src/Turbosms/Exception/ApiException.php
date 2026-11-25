<?php

namespace edrard\Turbosms\Exception;

class ApiException extends \RuntimeException
{
    private $response;

    public function __construct(array $response)
    {
        $this->response = $response;
        $status = preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $response['response_status']) === 1
            ? $response['response_status'] : 'UNKNOWN_STATUS';
        parent::__construct('TurboSMS API error ' . $response['response_code'] . ' (' . $status . ').', $response['response_code']);
    }

    public function getResponse()
    {
        return $this->response;
    }
}
