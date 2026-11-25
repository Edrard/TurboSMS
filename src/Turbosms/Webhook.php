<?php

namespace edrard\Turbosms;

use edrard\Turbosms\Exception\InvalidWebhookException;
use edrard\Turbosms\Exception\UnsupportedWebhookException;
use edrard\Turbosms\Exception\WebhookAuthenticationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Framework-independent parser for HTTP API delivery reports. Does not send messages. */
class Webhook implements LoggerAwareInterface
{
    use SafeLoggingTrait;
    private $secret;
    private $maxBodyBytes;

    public function __construct($secret, $maxBodyBytes = 1048576, ?LoggerInterface $logger = null)
    {
        $this->setLogger($logger ?: new NullLogger());
        if (!is_string($secret) || $secret === '') {
            throw new \InvalidArgumentException('A non-empty webhook secret is required.');
        }
        if (!is_int($maxBodyBytes) || $maxBodyBytes < 1) {
            throw new \InvalidArgumentException('Webhook body limit must be a positive integer.');
        }
        $this->secret = $secret;
        $this->maxBodyBytes = $maxBodyBytes;
    }

    /** Parse raw JSON or URL-encoded POST data. For multipart forms use parseForm($_POST). */
    public function parse($body, $contentType = 'application/json')
    {
        return $this->withLogging('webhook.parse', function () use ($body, $contentType) {
            return $this->doParse($body, $contentType);
        });
    }

    private function doParse($body, $contentType)
    {
        if (!is_string($body) || $body === '' || strlen($body) > $this->maxBodyBytes) {
            throw new InvalidWebhookException('Webhook body is empty, invalid or exceeds its size limit.');
        }
        if (!is_string($contentType)) {
            throw new InvalidWebhookException('Webhook content type must be a string.');
        }
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($contentType === 'application/json') {
            $payload = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
                throw new InvalidWebhookException('Webhook JSON is invalid.');
            }
            return $this->decode($payload, false);
        }
        if ($contentType === 'application/x-www-form-urlencoded') {
            $limit = (int) ini_get('max_input_vars');
            if ($limit > 0 && substr_count($body, '&') + 1 > $limit) {
                throw new InvalidWebhookException('Webhook form exceeds the PHP input variable limit.');
            }
            // Supported form data needs at most envelope/data/row/field nesting.
            foreach (explode('&', $body) as $pair) {
                $key = urldecode(explode('=', $pair, 2)[0]);
                $depth = substr_count($key, '[');
                $nestingLimit = (int) ini_get('max_input_nesting_level');
                if ($depth > 3 || ($nestingLimit > 0 && $depth > $nestingLimit)) {
                    throw new InvalidWebhookException('Webhook form nesting is invalid.');
                }
            }
            parse_str($body, $payload);
            return $this->doParseForm($payload);
        }
        throw new InvalidWebhookException('Unsupported webhook content type.');
    }

    /** Accept PHP-parsed FORM DATA, with nested data fields or a JSON data field. */
    public function parseForm(array $payload)
    {
        return $this->withLogging('webhook.parse_form', function () use ($payload) {
            return $this->doParseForm($payload);
        });
    }

    private function doParseForm(array $payload)
    {
        $encoded = json_encode($payload);
        if ($encoded === false || strlen($encoded) > $this->maxBodyBytes) {
            throw new InvalidWebhookException('Webhook form is invalid or exceeds its size limit.');
        }
        $inputLimit = (int) ini_get('max_input_vars');
        if ($inputLimit > 0 && $this->inputFields($payload) >= $inputLimit) {
            // PHP may already have truncated multipart $_POST at exactly this boundary.
            throw new InvalidWebhookException('Webhook form may exceed the PHP input variable limit; use JSON.');
        }
        return $this->decode($payload, true);
    }

    /** Return the acknowledgment body only after the consumer succeeds. Exceptions propagate. */
    public function handle($body, callable $consumer, $contentType = 'application/json')
    {
        return $this->withLogging('webhook.handle', function () use ($body, $consumer, $contentType) {
            $consumer($this->doParse($body, $contentType));
            return 'OK';
        });
    }

    private function decode(array $payload, $form)
    {
        if (!isset($payload['id']) || (!is_int($payload['id']) && !is_string($payload['id'])) ||
            (string) $payload['id'] === '' || preg_match('/[\x00-\x20\x7F]/', (string) $payload['id'])) {
            throw new InvalidWebhookException('Webhook event ID is invalid.');
        }
        $id = (string) $payload['id'];
        if (!isset($payload['signature']) || !is_string($payload['signature']) ||
            preg_match('/^[a-f0-9]{40}$/iD', $payload['signature']) !== 1 ||
            !hash_equals(sha1($this->secret . $id), strtolower($payload['signature']))) {
            throw new WebhookAuthenticationException('Webhook signature verification failed.');
        }
        if (!isset($payload['type']) || !is_string($payload['type'])) {
            throw new InvalidWebhookException('Webhook event type is invalid.');
        }
        if (!in_array($payload['type'], array('DLR_SMS_API', 'DLR_VIBER_API'), true)) {
            throw new UnsupportedWebhookException('Unsupported webhook event type.');
        }
        if (!isset($payload['date']) || !$this->validDate($payload['date']) || !isset($payload['try'])) {
            throw new InvalidWebhookException('Webhook event date or attempt is invalid.');
        }
        $attempt = $payload['try'];
        if ($form && is_string($attempt) && preg_match('/^[1-9][0-9]*$/D', $attempt) === 1 &&
            (string) (int) $attempt === $attempt) {
            $attempt = (int) $attempt;
        }
        if (!is_int($attempt) || $attempt < 1) {
            throw new InvalidWebhookException('Webhook attempt must be a positive integer.');
        }
        if (!isset($payload['data'])) {
            throw new InvalidWebhookException('Webhook delivery data is missing.');
        }
        $data = $payload['data'];
        if ($form && is_string($data)) {
            $data = json_decode($data, true, 64, JSON_BIGINT_AS_STRING);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidWebhookException('Webhook form delivery JSON is invalid.');
            }
        }
        if (!is_array($data) || count($data) === 0) {
            throw new InvalidWebhookException('Webhook delivery data must be an object or non-empty list.');
        }
        $rows = array_key_exists('message_id', $data) ? array($data) : $data;
        if (array_keys($rows) !== range(0, count($rows) - 1)) {
            throw new InvalidWebhookException('Webhook delivery list is invalid.');
        }
        $deliveries = array();
        foreach ($rows as $row) {
            $deliveries[] = $this->delivery($row, $payload['type']);
        }
        // Deliberately do not return the signature or the secret to the consumer.
        return array('id' => $id, 'type' => $payload['type'], 'date' => $payload['date'],
            'try' => $attempt, 'deliveries' => $deliveries);
    }

    private function delivery($row, $type)
    {
        if (!is_array($row) || !isset($row['message_id']) || !is_string($row['message_id']) ||
            preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $row['message_id']) !== 1 ||
            !isset($row['status']) || !is_string($row['status']) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $row['status']) !== 1 ||
            !array_key_exists('sent_date', $row) || ($row['sent_date'] !== null && !$this->validDate($row['sent_date'])) ||
            !isset($row['dlr_date']) || !$this->validDate($row['dlr_date']) || !array_key_exists('error_code', $row) ||
            ($row['error_code'] !== null && !is_int($row['error_code']) && !is_string($row['error_code']))) {
            throw new InvalidWebhookException('Webhook delivery row is invalid.');
        }
        $channel = $type === 'DLR_SMS_API' ? 'sms' : (isset($row['channel']) ? $row['channel'] : null);
        if (!in_array($channel, array('sms', 'viber'), true) ||
            ($type === 'DLR_SMS_API' && isset($row['channel']) && $row['channel'] !== 'sms')) {
            throw new InvalidWebhookException('Webhook delivery channel is invalid.');
        }
        $states = array('DELIVRD' => 'Delivered', 'READ' => 'Read', 'EXPIRED' => 'Expired',
            'UNDELIV' => 'Undelivered', 'REJECTD' => 'Rejected');
        return array('message_id' => $row['message_id'], 'channel' => $channel,
            'status' => isset($states[$row['status']]) ? $states[$row['status']] : 'Unknown',
            'raw_status' => $row['status'], 'sent' => $row['sent_date'], 'updated' => $row['dlr_date'],
            'error_code' => $row['error_code'], 'data' => $row);
    }

    private function validDate($value)
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            return false;
        }
        // The historical alias also works with PHP 7.2's bundled timezone database.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('Europe/Kiev'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    private function inputFields(array $payload)
    {
        $count = 0;
        foreach ($payload as $value) {
            $count += is_array($value) ? $this->inputFields($value) : 1;
        }
        return $count;
    }
}
