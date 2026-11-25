<?php

namespace edrard\Turbosms;

use edrard\Turbosms\Exception\ApiException;
use edrard\Turbosms\Exception\DuplicateRequestException;
use edrard\Turbosms\Exception\InvalidResponseException;
use edrard\Turbosms\Exception\TransportException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Api implements LoggerAwareInterface
{
    use SafeLoggingTrait;
    protected $client;
    protected $config = array();
    protected $url;
    protected $error = array();

    public function __construct(array $config, ?ClientInterface $client = null, ?LoggerInterface $logger = null)
    {
        $this->setLogger($logger ?: new NullLogger());
        $this->changeConfig($config);
        $this->client = $client ?: new Client();
    }

    public function changeConfig(array $config)
    {
        if (!isset($config['token']) || !is_string($config['token']) ||
            $config['token'] === '' || preg_match('/\s|[\x00-\x1F\x7F]/', $config['token'])) {
            throw new \InvalidArgumentException('A non-empty API token without whitespace or control characters is required.');
        }
        $url = isset($config['url']) && is_string($config['url']) ? $config['url'] : '';
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || $parts === false ||
            !isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https' ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('API url must be an HTTPS URL without credentials, query or fragment.');
        }
        $this->config = $config;
    }

    public function sendMessage(Message $message, $url = 'message/send.json')
    {
        return $this->withLogging('message.send', function () use ($message, $url) {
            return $this->doSendMessage($message, $url);
        });
    }

    private function doSendMessage(Message $message, $url)
    {
        $this->error = array();
        $message->validate();
        if (!is_string($url) || preg_match('~^(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+(?:\.json)?$~D', trim($url, '/')) !== 1) {
            throw new \InvalidArgumentException('API method must be a relative API path.');
        }
        $this->url = rtrim($this->config['url'], '/') . '/' . trim($url, '/');
        $response = $this->getResponse($this->prepeQuery($message));
        return array('resp' => $response, 'error' => $this->error);
    }

    /** Read delivery states for message IDs returned by sendMessage(). */
    public function getMessageStatus(array $messageIds)
    {
        return $this->withLogging('message.status', function () use ($messageIds) {
            return $this->doGetMessageStatus($messageIds);
        });
    }

    private function doGetMessageStatus(array $messageIds)
    {
        $this->error = array();
        if (count($messageIds) === 0) {
            throw new \InvalidArgumentException('Provide at least one message ID.');
        }
        foreach ($messageIds as $id) {
            if (!is_string($id) || preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id) !== 1) {
                throw new \InvalidArgumentException('Message IDs must be UUID-shaped strings returned by TurboSMS.');
            }
        }
        $ans = $this->requestEnvelope(
            rtrim($this->config['url'], '/') . '/message/status.json',
            array('messages' => array_values($messageIds)), array(0), false
        );
        if (!$this->validResultList($ans['response_result'])) {
            throw new InvalidResponseException('TurboSMS returned an invalid message status list.');
        }
        foreach ($ans['response_result'] as $res) {
            if (!is_array($res) || !$this->validCodeAndStatus($res) ||
                !isset($res['message_id']) || !is_string($res['message_id']) || $res['message_id'] === '') {
                throw new InvalidResponseException('TurboSMS returned an invalid message status row.');
            }
            if ($res['response_code'] === 0 && !$this->validDeliveryStatus($res)) {
                throw new InvalidResponseException('TurboSMS returned invalid delivery status data.');
            }
        }
        $this->checkResp($ans);
        return array('resp' => $ans, 'error' => $this->error);
    }

    /** Read the account balance; the API number is retained without rounding. */
    public function getBalance()
    {
        return $this->withLogging('user.balance', function () {
            return $this->doGetBalance();
        });
    }

    private function doGetBalance()
    {
        $this->error = array();
        $ans = $this->requestEnvelope(
            rtrim($this->config['url'], '/') . '/user/balance.json', null, array(0), false
        );
        $result = $ans['response_result'];
        if (!is_array($result) || !isset($result['balance']) ||
            (!is_int($result['balance']) && !is_float($result['balance'])) || !is_finite((float) $result['balance'])) {
            throw new InvalidResponseException('TurboSMS returned an invalid account balance.');
        }
        return array('resp' => $ans, 'error' => array());
    }

    private function prepeQuery($message)
    {
        $config = $message->getConfig();
        $body = array_merge(
            array('recipients' => array_values(array_map('strval', $config['recip']))),
            $message->getChannels()
        );
        if (isset($config['sequence_id'])) {
            $body['sequence_id'] = $config['sequence_id'];
        }
        return $body;
    }

    private function getResponse(array $body)
    {
        $ans = $this->requestEnvelope($this->url, $body, array(800, 801, 802, 803), true);
        if (!$this->validResultList($ans['response_result'])) {
            throw new InvalidResponseException('TurboSMS returned an invalid recipient result list; delivery is unknown.');
        }
        foreach ($ans['response_result'] as $res) {
            if (!is_array($res) || !$this->validCodeAndStatus($res) ||
                !isset($res['phone']) || !is_string($res['phone']) ||
                !array_key_exists('message_id', $res) ||
                ($res['message_id'] !== null && !is_string($res['message_id'])) ||
                ($res['response_code'] === 0 && ($res['message_id'] === null || $res['message_id'] === ''))) {
                throw new InvalidResponseException('TurboSMS returned an invalid recipient result; delivery is unknown.');
            }
        }
        $this->checkResp($ans);
        return $ans;
    }

    private function requestEnvelope($url, ?array $body, array $successCodes, $sending)
    {
        $context = $sending ? '; delivery is unknown. Retry only with the original sequence_id.'
            : '; read-only request failed, no message was sent by this request.';
        $options = array(
            'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->config['token']
            ),
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $sending ? 0 : 30
        );
        if ($body !== null) {
            $options['json'] = $body;
        }
        try {
            $response = $this->client->request('POST', $url, $options);
        } catch (GuzzleException $e) {
            // Do not expose the underlying request, headers, token or message body.
            throw new TransportException('TurboSMS transport failed' . $context);
        }
        $this->logHttpStatus = $response->getStatusCode();
        if ($response->getStatusCode() !== 200) {
            throw new TransportException('TurboSMS returned HTTP ' . $response->getStatusCode() . $context, $response->getStatusCode());
        }
        try {
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            // Reading explicitly also allows stream errors to be caught on PHP 7.2.
            $raw = $stream->getContents();
        } catch (\RuntimeException $e) {
            throw new TransportException('Could not read the TurboSMS response' . $context);
        }
        $ans = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($ans) ||
            !$this->validCodeAndStatus($ans) || !array_key_exists('response_result', $ans)) {
            throw new InvalidResponseException('TurboSMS returned invalid JSON or an invalid response envelope' . $context);
        }
        if ($ans['response_code'] === 507) {
            throw new DuplicateRequestException($ans);
        }
        if (!in_array($ans['response_code'], $successCodes, true)) {
            throw new ApiException($ans);
        }
        return $ans;
    }

    private function validResultList($result)
    {
        return is_array($result) && count($result) > 0 && array_keys($result) === range(0, count($result) - 1);
    }

    private function validDeliveryStatus(array $row)
    {
        foreach (array('recipient', 'status', 'channel', 'updated') as $field) {
            if (!isset($row[$field]) || !is_string($row[$field]) || $row[$field] === '') {
                return false;
            }
        }
        if (!array_key_exists('sent', $row) || ($row['sent'] !== null && !is_string($row['sent']))) {
            return false;
        }
        if (isset($row['rejected_status']) && !is_string($row['rejected_status'])) {
            return false;
        }
        if (isset($row['click_time']) && !is_string($row['click_time'])) {
            return false;
        }
        return true;
    }

    private function validCodeAndStatus(array $response)
    {
        return isset($response['response_code'], $response['response_status']) &&
            is_int($response['response_code']) && $response['response_code'] >= 0 && $response['response_code'] <= 9999 &&
            is_string($response['response_status']) && $response['response_status'] !== '' &&
            preg_match('//u', $response['response_status']) === 1;
    }

    private function checkResp($ans)
    {
        foreach ($ans['response_result'] as $key => $res) {
            if ($res['response_code'] !== 0) {
                $this->error[$key] = $res;
            }
        }
    }
}
