<?php

class RecordingLogger extends \Psr\Log\AbstractLogger
{
    public $records = array();

    // Untyped message is compatible with psr/log 1.x, 2.x and 3.x on their supported PHP versions.
    public function log($level, $message, array $context = array()): void
    {
        $this->records[] = array('level' => $level, 'message' => $message, 'context' => $context);
    }
}
class FailingTestLogger extends RecordingLogger
{
    public function log($level, $message, array $context = array()): void
    {
        throw new RuntimeException('test logger failed');
    }
}
function loggedApi(array $queue, &$history, \Psr\Log\LoggerInterface $logger) {
    $history = array();
    $handler = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($queue));
    $handler->push(\GuzzleHttp\Middleware::history($history));
    return new \edrard\Turbosms\Api(config(), new \GuzzleHttp\Client(array('handler' => $handler)), $logger);
}
function logSummary(RecordingLogger $logger, $operation, $level) {
    same(2, count($logger->records));
    same('debug', $logger->records[0]['level']);
    same($level, $logger->records[1]['level']);
    same($operation, $logger->records[0]['context']['operation']);
    same($operation, $logger->records[1]['context']['operation']);
    same($logger->records[0]['context']['request_id'], $logger->records[1]['context']['request_id']);
    same(true, $logger->records[1]['context']['duration_ms'] >= 0);
    foreach ($logger->records as $record) {
        same(array(), array_values(array_diff(array_keys($record['context']), array('operation', 'request_id',
            'duration_ms', 'http_status', 'response_code', 'error_count', 'delivery_count', 'event_type', 'error_type'))));
        foreach ($record['context'] as $value) { same(true, is_scalar($value)); }
    }
}

test('Api and Webhook support PSR-3 logger awareness and existing constructors', function () {
    same(true, (new \edrard\Turbosms\Api(config())) instanceof \Psr\Log\LoggerAwareInterface);
    same(true, webhook() instanceof \Psr\Log\LoggerAwareInterface);
    $logger = new RecordingLogger();
    $a = api(array(response(envelope())), $history);
    $a->setLogger($logger);
    $a->sendMessage(message());
    logSummary($logger, 'message.send', 'info');
});
foreach (array('sms', 'viber', 'hybrid') as $channel) {
    test($channel . ' send logs safe result metadata and preserves return value', function () use ($channel) {
        $logger = new RecordingLogger();
        $a = loggedApi(array(response(envelope())), $history, $logger);
        same(array('resp' => envelope(), 'error' => array()), $a->sendMessage(message()->setChannel($channel)));
        logSummary($logger, 'message.send', 'info');
        same(200, $logger->records[1]['context']['http_status']);
        same(800, $logger->records[1]['context']['response_code']);
        same(0, $logger->records[1]['context']['error_count']);
        same(1, count($history));
    });
}
test('partial acceptance logs a warning with error count', function () {
    $logger = new RecordingLogger();
    $a = loggedApi(array(response(envelope(802, array(row(), row(406))))), $history, $logger);
    same(array(1 => row(406)), $a->sendMessage(message())['error']);
    logSummary($logger, 'message.send', 'warning');
    same(1, $logger->records[1]['context']['error_count']);
});
test('status query logs counts without message IDs or delivery states', function () {
    $logger = new RecordingLogger();
    $a = loggedApi(array(response(queryEnvelope(array(deliveryRow('Failed'))))), $history, $logger);
    same(array(), $a->getMessageStatus(array(deliveryRow()['message_id']))['error']);
    logSummary($logger, 'message.status', 'info');
    same(false, strpos(json_encode($logger->records), deliveryRow()['message_id']) !== false);
    same(false, strpos(json_encode($logger->records), 'Failed') !== false);
});
test('balance query does not log account balance', function () {
    $logger = new RecordingLogger();
    $a = loggedApi(array(response(queryEnvelope(array('balance' => 54321.123)))), $history, $logger);
    same(54321.123, $a->getBalance()['resp']['response_result']['balance']);
    logSummary($logger, 'user.balance', 'info');
    same(false, strpos(json_encode($logger->records), '54321.123') !== false);
});
test('send logs do not contain configuration, image URL, recipient, body or provider data', function () {
    $logger = new RecordingLogger();
    $body = envelope();
    $body['response_result'][0]['private_extra'] = 'sensitive-provider-value';
    $a = loggedApi(array(response($body)), $history, $logger);
    $a->changeConfig(array('url' => 'https://example.org/sensitive-base/', 'token' => 'sensitive-test-token'));
    $a->sendMessage(message()->setChannel('hybrid')->setFrom('Sensitive Sender')->setText('sensitive-message-text')
        ->setSequenceId('sensitive-sequence-ID')->setViberImageUrl('https://example.org/sensitive-image?key=private-query'));
    $logs = json_encode($logger->records);
    foreach (array('sensitive-provider-value', 'sensitive-base', 'sensitive-test-token', 'Sensitive Sender',
        'sensitive-message-text', 'sensitive-sequence-ID', 'sensitive-image', 'private-query', '380000000000',
        row()['message_id'], 'Bearer', 'Authorization') as $sensitive) {
        same(false, strpos($logs, $sensitive) !== false);
    }
});
test('API error logs code without reflecting provider status or response object', function () {
    $logger = new RecordingLogger();
    $body = array('response_code' => 411, 'response_status' => 'SENSITIVE_PROVIDER_STATUS', 'response_result' => array('private' => 'sensitive-result'));
    $a = loggedApi(array(response($body)), $history, $logger);
    $e = raises(\edrard\Turbosms\Exception\ApiException::class, function () use ($a) { $a->sendMessage(message()); });
    same($body, $e->getResponse());
    logSummary($logger, 'message.send', 'error');
    same(411, $logger->records[1]['context']['response_code']);
    same('api_error', $logger->records[1]['context']['error_type']);
    same(false, strpos(json_encode($logger->records), 'SENSITIVE_PROVIDER_STATUS') !== false);
    same(false, strpos(json_encode($logger->records), 'sensitive-result') !== false);
});
test('duplicate result logs notice and still throws original exception type', function () {
    $logger = new RecordingLogger();
    $body = array('response_code' => 507, 'response_status' => 'FAILED_DUPLICATE_REQUEST', 'response_result' => array(row()));
    $a = loggedApi(array(response($body)), $history, $logger);
    raises(\edrard\Turbosms\Exception\DuplicateRequestException::class, function () use ($a) { $a->sendMessage(message()); });
    logSummary($logger, 'message.send', 'notice');
    same(507, $logger->records[1]['context']['response_code']);
    same('duplicate_request', $logger->records[1]['context']['error_type']);
    same(1, count($history));
});
test('transport failure logs category without request, exception or original diagnostic', function () {
    $logger = new RecordingLogger();
    $error = new \GuzzleHttp\Exception\ConnectException('sensitive transport diagnostic',
        new \GuzzleHttp\Psr7\Request('POST', 'https://example.org/private', array('Authorization' => 'sensitive header')));
    $a = loggedApi(array($error), $history, $logger);
    raises(\edrard\Turbosms\Exception\TransportException::class, function () use ($a) { $a->sendMessage(message()); });
    logSummary($logger, 'message.send', 'error');
    same('transport_error', $logger->records[1]['context']['error_type']);
    same(false, array_key_exists('http_status', $logger->records[1]['context']));
    same(false, strpos(json_encode($logger->records), 'sensitive') !== false);
});
test('HTTP failure logs only status number', function () {
    $logger = new RecordingLogger();
    $a = loggedApi(array(new \GuzzleHttp\Psr7\Response(500, array(), 'sensitive HTTP body')), $history, $logger);
    raises(\edrard\Turbosms\Exception\TransportException::class, function () use ($a) { $a->getBalance(); });
    logSummary($logger, 'user.balance', 'error');
    same(500, $logger->records[1]['context']['http_status']);
    same(false, strpos(json_encode($logger->records), 'sensitive') !== false);
});
test('malformed response logs category without raw body', function () {
    $logger = new RecordingLogger();
    $a = loggedApi(array(new \GuzzleHttp\Psr7\Response(200, array(), 'sensitive non-JSON body')), $history, $logger);
    raises(\edrard\Turbosms\Exception\InvalidResponseException::class, function () use ($a) { $a->getBalance(); });
    logSummary($logger, 'user.balance', 'error');
    same('invalid_response', $logger->records[1]['context']['error_type']);
    same(false, strpos(json_encode($logger->records), 'sensitive') !== false);
});
test('local validation logs warning without sending or retaining previous HTTP status', function () {
    $logger = new RecordingLogger();
    $a = loggedApi(array(response(envelope())), $history, $logger);
    $a->sendMessage(message());
    $previousId = $logger->records[0]['context']['request_id'];
    $logger->records = array();
    raises(InvalidArgumentException::class, function () use ($a) { $a->sendMessage(new \edrard\Turbosms\Message()); });
    logSummary($logger, 'message.send', 'warning');
    same('invalid_input', $logger->records[1]['context']['error_type']);
    same(false, array_key_exists('http_status', $logger->records[1]['context']));
    same(false, $previousId === $logger->records[0]['context']['request_id']);
    same(1, count($history));
});
test('replacing logger routes subsequent operations only to new logger', function () {
    $first = new RecordingLogger();
    $second = new RecordingLogger();
    $a = loggedApi(array(response(envelope()), response(envelope())), $history, $first);
    $a->sendMessage(message());
    $a->setLogger($second);
    $a->sendMessage(message());
    same(2, count($first->records));
    same(2, count($second->records));
});
test('throwing logger cannot change successful send or cause retries', function () {
    $a = loggedApi(array(response(envelope())), $history, new FailingTestLogger());
    same(array('resp' => envelope(), 'error' => array()), $a->sendMessage(message()));
    same(1, count($history));
});
test('throwing logger cannot mask original API failure', function () {
    $a = loggedApi(array(response(array('response_code' => 411, 'response_status' => 'ERROR', 'response_result' => null))), $history, new FailingTestLogger());
    $e = raises(\edrard\Turbosms\Exception\ApiException::class, function () use ($a) { $a->sendMessage(message()); });
    same(411, $e->getCode());
    same(1, count($history));
});
test('logger PHP Error cannot change send success or webhook acknowledgment', function () {
    $logger = new class extends RecordingLogger {
        public function log($level, $message, array $context = array()): void { throw new Error('test backend error'); }
    };
    $a = loggedApi(array(response(envelope())), $history, $logger);
    same(array('resp' => envelope(), 'error' => array()), $a->sendMessage(message()));
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, $logger);
    same('OK', $parser->handle(json_encode(webhookPayload()), function () {}));
    same(1, count($history));
});

foreach (array('json', 'urlencoded', 'parsed_form') as $format) {
    test('webhook ' . $format . ' logs one operation with safe counts only', function () use ($format) {
        $logger = new RecordingLogger();
        $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, $logger);
        $payload = webhookPayload();
        $payload['data']['private_extra'] = 'sensitive webhook field';
        if ($format === 'json') { $parser->parse(json_encode($payload)); }
        elseif ($format === 'urlencoded') { $parser->parse(http_build_query($payload), 'application/x-www-form-urlencoded'); }
        else { $parser->parseForm($payload); }
        logSummary($logger, $format === 'parsed_form' ? 'webhook.parse_form' : 'webhook.parse', 'info');
        same(1, $logger->records[1]['context']['delivery_count']);
        same('DLR_SMS_API', $logger->records[1]['context']['event_type']);
        foreach (array('sensitive webhook field', $payload['signature'], $payload['id'], 'test-webhook-secret', webhookRow()['message_id']) as $sensitive) {
            same(false, strpos(json_encode($logger->records), $sensitive) !== false);
        }
    });
}
test('webhook setLogger works with existing constructor', function () {
    $logger = new RecordingLogger();
    $parser = webhook();
    $parser->setLogger($logger);
    $parser->parse(json_encode(webhookPayload()));
    logSummary($logger, 'webhook.parse', 'info');
});
test('webhook handle logs completion after consumer, without nested parser logs', function () {
    $logger = new RecordingLogger();
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, $logger);
    $called = false;
    same('OK', $parser->handle(json_encode(webhookPayload()), function () use ($logger, &$called) {
        same(1, count($logger->records));
        $called = true;
    }));
    same(true, $called);
    logSummary($logger, 'webhook.handle', 'info');
});
test('webhook authentication failure logs warning without raw input', function () {
    $logger = new RecordingLogger();
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, $logger);
    $payload = webhookPayload();
    $payload['signature'] = str_repeat('0', 40);
    raises(\edrard\Turbosms\Exception\WebhookAuthenticationException::class, function () use ($parser, $payload) { $parser->parse(json_encode($payload)); });
    logSummary($logger, 'webhook.parse', 'warning');
    same('authentication_failed', $logger->records[1]['context']['error_type']);
    same(false, strpos(json_encode($logger->records), $payload['id']) !== false);
});
test('unsupported webhook type is categorized without logging untrusted type', function () {
    $logger = new RecordingLogger();
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, $logger);
    raises(\edrard\Turbosms\Exception\UnsupportedWebhookException::class, function () use ($parser) { $parser->parse(json_encode(webhookPayload('PRIVATE_UNSUPPORTED_TYPE'))); });
    logSummary($logger, 'webhook.parse', 'warning');
    same('unsupported_event', $logger->records[1]['context']['error_type']);
    same(false, strpos(json_encode($logger->records), 'PRIVATE_UNSUPPORTED_TYPE') !== false);
});
test('consumer failure logs sanitized error and propagates the same exception without ACK', function () {
    $logger = new RecordingLogger();
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, $logger);
    $failure = new RuntimeException('sensitive DSN and storage diagnostic');
    $e = raises(RuntimeException::class, function () use ($parser, $failure) {
        $parser->handle(json_encode(webhookPayload()), function () use ($failure) { throw $failure; });
    });
    same($failure, $e);
    logSummary($logger, 'webhook.handle', 'error');
    same('processing_error', $logger->records[1]['context']['error_type']);
    same(false, strpos(json_encode($logger->records), 'sensitive') !== false);
});
test('throwing logger does not interfere with webhook consumer success or ACK', function () {
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, new FailingTestLogger());
    $calls = 0;
    same('OK', $parser->handle(json_encode(webhookPayload()), function () use (&$calls) { ++$calls; }));
    same(1, $calls);
});
test('throwing logger cannot mask webhook consumer failure', function () {
    $parser = new \edrard\Turbosms\Webhook('test-webhook-secret', 1048576, new FailingTestLogger());
    $failure = new RuntimeException('test consumer failure');
    same($failure, raises(RuntimeException::class, function () use ($parser, $failure) {
        $parser->handle(json_encode(webhookPayload()), function () use ($failure) { throw $failure; });
    }));
});
