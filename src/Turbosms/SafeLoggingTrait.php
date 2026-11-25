<?php

namespace edrard\Turbosms;

use edrard\Turbosms\Exception\ApiException;
use edrard\Turbosms\Exception\DuplicateRequestException;
use edrard\Turbosms\Exception\InvalidResponseException;
use edrard\Turbosms\Exception\TransportException;
use edrard\Turbosms\Exception\UnsupportedWebhookException;
use edrard\Turbosms\Exception\WebhookAuthenticationException;
use Psr\Log\LoggerAwareTrait;

/** Internal allowlisted metadata only. Never pass input, bodies, URLs or exceptions to a logger. */
trait SafeLoggingTrait
{
    use LoggerAwareTrait;

    private $logHttpStatus;

    private function withLogging($operation, callable $action)
    {
        $this->logHttpStatus = null;
        // This correlation ID is generated locally, independent of all user/provider IDs.
        $context = array('operation' => $operation, 'request_id' => uniqid('turbosms-', true));
        $this->safeLog('debug', 'TurboSMS operation started.', $context);
        $started = microtime(true);
        try {
            $result = $action();
        } catch (\Throwable $e) {
            $context['duration_ms'] = max(0, round((microtime(true) - $started) * 1000, 3));
            if ($this->logHttpStatus !== null) {
                $context['http_status'] = $this->logHttpStatus;
            }
            $level = 'error';
            $category = 'processing_error';
            if ($e instanceof ApiException) {
                $category = 'api_error';
                $context['response_code'] = $e->getCode();
                if ($e instanceof DuplicateRequestException) {
                    $category = 'duplicate_request';
                    $level = 'notice';
                }
            } elseif ($e instanceof WebhookAuthenticationException) {
                $category = 'authentication_failed';
                $level = 'warning';
            } elseif ($e instanceof UnsupportedWebhookException) {
                $category = 'unsupported_event';
                $level = 'warning';
            } elseif ($e instanceof \InvalidArgumentException) {
                $category = 'invalid_input';
                $level = 'warning';
            } elseif ($e instanceof TransportException) {
                $category = 'transport_error';
            } elseif ($e instanceof InvalidResponseException) {
                $category = 'invalid_response';
            }
            $context['error_type'] = $category;
            $this->safeLog($level, 'TurboSMS operation failed.', $context);
            throw $e;
        }
        $context['duration_ms'] = max(0, round((microtime(true) - $started) * 1000, 3));
        if ($this->logHttpStatus !== null) {
            $context['http_status'] = $this->logHttpStatus;
        }
        if (is_array($result) && isset($result['resp']['response_code'])) {
            $context['response_code'] = $result['resp']['response_code'];
            $context['error_count'] = count($result['error']);
        }
        if (is_array($result) && isset($result['deliveries'])) {
            $context['delivery_count'] = count($result['deliveries']);
            // type has been checked against a fixed list by Webhook before this point.
            $context['event_type'] = $result['type'];
        }
        $this->safeLog(isset($context['error_count']) && $context['error_count'] > 0 ? 'warning' : 'info',
            'TurboSMS operation completed.', $context);
        return $result;
    }

    private function safeLog($level, $message, array $context)
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable $ignored) {
            // A logging failure must not turn a completed send/store into a retry.
        }
    }
}
