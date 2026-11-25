<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use edrard\Turbosms\Api;
use edrard\Turbosms\Message;
use edrard\Turbosms\Exception\ApiException;
use edrard\Turbosms\Exception\DuplicateRequestException;
use edrard\Turbosms\Exception\InvalidResponseException;
use edrard\Turbosms\Exception\TransportException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

// Make notices, warnings and deprecations fail the suite as well.
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});
error_reporting(E_ALL);

$tests = array();
function test($name, callable $test) { global $tests; $tests[$name] = $test; }
function same($expected, $actual) {
    if ($expected !== $actual) { throw new RuntimeException('Values differ: ' . var_export($actual, true)); }
}
function raises($class, callable $action) {
    try { $action(); } catch (Throwable $e) {
        if (!($e instanceof $class)) { throw new RuntimeException('Expected ' . $class . ', got ' . get_class($e) . ': ' . $e->getMessage()); }
        return $e;
    }
    throw new RuntimeException('Expected ' . $class . ' but nothing was thrown.');
}
function config() { return array('url' => 'https://api.turbosms.ua/', 'token' => 'test-only-placeholder'); }
function message() { return new Message(array('380000000000'), 'Company_name', 'Hello'); }
function row($code = 0) {
    return array('phone' => '380000000000', 'response_code' => $code,
        'response_status' => $code === 0 ? 'OK' : 'NOT_ALLOWED_RECIPIENT_COUNTRY',
        'message_id' => $code === 0 ? 'f83f8868-5e46-c6cf-e4fb-615e5a293754' : null);
}
function envelope($code = 800, $rows = null) {
    $statuses = array(800 => 'SUCCESS_MESSAGE_ACCEPTED', 801 => 'SUCCESS_MESSAGE_SENT',
        802 => 'SUCCESS_MESSAGE_PARTIAL_ACCEPTED', 803 => 'SUCCESS_MESSAGE_PARTIAL_SENT');
    return array('response_code' => $code, 'response_status' => isset($statuses[$code]) ? $statuses[$code] : 'ERROR',
        'response_result' => $rows === null ? array(row()) : $rows);
}
function response(array $body) { return new Response(200, array('Content-Type' => 'application/json'), json_encode($body)); }
function api(array $queue, &$history) {
    $history = array();
    $handler = HandlerStack::create(new MockHandler($queue));
    $handler->push(Middleware::history($history));
    return new Api(config(), new Client(array('handler' => $handler, 'timeout' => 1)));
}

test('original constructors, fluent setters and getConfig remain compatible', function () {
    $m = new Message();
    same($m, $m->setRecipients(array('380000000000'))->setFrom('Company_name')->setText('Hello'));
    same(message()->getConfig(), $m->getConfig());
    same(array('from' => 'Company_name', 'text' => 'Hello', 'recip' => array('380000000000')), $m->getConfig());
    new Api(config());
});
foreach (array(800, 801, 802, 803) as $code) {
    test('success code ' . $code . ' preserves return shape', function () use ($code) {
        $expected = envelope($code);
        $a = api(array(response($expected)), $history);
        same(array('resp' => $expected, 'error' => array()), $a->sendMessage(message()));
        same(1, count($history));
    });
}
test('POST payload, authorization, Unicode and safe request options', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setText("Привіт\nСвіт"));
    $request = $history[0]['request'];
    same('POST', $request->getMethod());
    same('https://api.turbosms.ua/message/send.json', (string) $request->getUri());
    same('Bearer test-only-placeholder', $request->getHeaderLine('Authorization'));
    same('application/json', $request->getHeaderLine('Content-Type'));
    same(array('recipients' => array('380000000000'), 'sms' => array('text' => "Привіт\nСвіт", 'sender' => 'Company_name')),
        json_decode((string) $request->getBody(), true));
    same(0, $history[0]['options']['timeout']);
    same(false, $history[0]['options']['http_errors']);
    same(false, $history[0]['options']['allow_redirects']);
});
test('sparse recipient arrays become JSON lists and integer phones become strings', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setRecipients(array(4 => 380000000000)));
    same(array('380000000000'), json_decode((string) $history[0]['request']->getBody(), true)['recipients']);
});
test('same sequence_id is sent on repeated calls and can be removed', function () {
    $a = api(array(response(envelope()), response(envelope()), response(envelope())), $history);
    $m = message();
    same($m, $m->setSequenceId('order-42'));
    $a->sendMessage($m);
    $a->sendMessage($m);
    $a->sendMessage($m->setSequenceId(null));
    foreach (array(0, 1) as $i) {
        same('order-42', json_decode((string) $history[$i]['request']->getBody(), true)['sequence_id']);
    }
    same(false, array_key_exists('sequence_id', json_decode((string) $history[2]['request']->getBody(), true)));
});
foreach (array(802, 803) as $code) {
    test('partial result ' . $code . ' keeps original error indices and resets on next send', function () use ($code) {
        $partial = envelope($code, array(row(), row(406)));
        $a = api(array(response($partial), response(envelope())), $history);
        same(array(1 => row(406)), $a->sendMessage(message())['error']);
        same(array(), $a->sendMessage(message())['error']);
    });
}
test('duplicate request exposes original response and does not retry', function () {
    $body = array('response_code' => 507, 'response_status' => 'FAILED_DUPLICATE_REQUEST', 'response_result' => array(row(), row(406)));
    $a = api(array(response($body)), $history);
    $e = raises(DuplicateRequestException::class, function () use ($a) { $a->sendMessage(message()->setSequenceId('order-42')); });
    same(507, $e->getCode());
    same($body, $e->getResponse());
    same(true, $e instanceof ApiException);
    same(true, $e instanceof Exception);
    same(1, count($history));
});
test('API failure exposes code and structured response', function () {
    $body = array('response_code' => 401, 'response_status' => 'UNAUTHORIZED', 'response_result' => null);
    $a = api(array(response($body)), $history);
    $e = raises(ApiException::class, function () use ($a) { $a->sendMessage(message()); });
    same(401, $e->getCode());
    same($body, $e->getResponse());
    same('TurboSMS API error 401 (UNAUTHORIZED).', $e->getMessage());
});
test('untrusted response status is omitted from exception message', function () {
    $a = api(array(response(array('response_code' => 999, 'response_status' => 'untrusted-secret-like-value', 'response_result' => null))), $history);
    $e = raises(ApiException::class, function () use ($a) { $a->sendMessage(message()); });
    same('TurboSMS API error 999 (UNKNOWN_STATUS).', $e->getMessage());
});
foreach (array(302, 401, 429, 500) as $httpCode) {
    test('HTTP ' . $httpCode . ' is a sanitized transport failure without retries', function () use ($httpCode) {
        $a = api(array(new Response($httpCode, array('Location' => 'https://example.org'), 'private body')), $history);
        $e = raises(TransportException::class, function () use ($a) { $a->sendMessage(message()); });
        same($httpCode, $e->getCode());
        same(false, strpos($e->getMessage(), 'private body') !== false);
        same(1, count($history));
    });
}
test('connection error does not expose original exception or request secrets', function () {
    $a = api(array(new ConnectException('private diagnostic', new Request('POST', 'https://api.turbosms.ua', array('Authorization' => 'private header')))), $history);
    $e = raises(TransportException::class, function () use ($a) { $a->sendMessage(message()); });
    same(null, $e->getPrevious());
    same(false, strpos((string) $e, 'private diagnostic') !== false);
    same(1, count($history));
});
test('unreadable response body is a transport failure', function () {
    $stream = Utils::streamFor('response');
    $stream->close();
    $a = api(array(new Response(200, array(), $stream)), $history);
    raises(TransportException::class, function () use ($a) { $a->sendMessage(message()); });
});
$invalidBodies = array('', '<html>private body</html>', 'null', '[]', '{}', '{',
    '{"response_code":"800","response_status":"OK","response_result":[]}',
    '{"response_code":800,"response_status":null,"response_result":[]}',
    '{"response_code":800,"response_status":"OK"}',
    json_encode(envelope(800, array())),
    '{"response_code":800,"response_status":"OK","response_result":null}',
    '{"response_code":800,"response_status":"OK","response_result":{"phone":"x"}}',
    json_encode(envelope(800, array(null))),
    json_encode(envelope(800, array(array('response_code' => 0, 'response_status' => 'OK', 'phone' => '380000000000', 'message_id' => null)))),
    json_encode(envelope(800, array(array('response_code' => '0', 'response_status' => 'OK', 'phone' => '380000000000', 'message_id' => 'id')))));
foreach ($invalidBodies as $i => $body) {
    test('invalid response ' . $i . ' fails without PHP warnings', function () use ($body) {
        $a = api(array(new Response(200, array(), $body)), $history);
        $e = raises(InvalidResponseException::class, function () use ($a) { $a->sendMessage(message()); });
        same(false, strpos($e->getMessage(), 'private body') !== false);
        same(1, count($history));
    });
}
$invalidMessages = array(
    new Message(), message()->setFrom(''), message()->setFrom(array()), message()->setFrom(str_repeat('я', 26)),
    message()->setFrom("bad\nname"), message()->setFrom("\xff"), message()->setText(''), message()->setText(array()),
    message()->setText("\xff"), message()->setText("a\0b"), message()->setText(str_repeat('a', 1522)),
    message()->setRecipients(array()), message()->setRecipients(array_fill(0, 5001, '380000000000')),
    message()->setRecipients(array('+380000000000')), message()->setRecipients(array('abc')),
    message()->setRecipients(array(array())), message()->setRecipients(array(true)), message()->setRecipients(array(380000000000.0)),
    message()->setSequenceId(''), message()->setSequenceId(str_repeat('я', 41)),
    message()->setSequenceId(array()), message()->setSequenceId("bad\nID"), message()->setSequenceId("\xff")
);
foreach ($invalidMessages as $i => $m) {
    test('invalid message ' . $i . ' never reaches transport', function () use ($m) {
        $a = api(array(), $history);
        raises(InvalidArgumentException::class, function () use ($a, $m) { $a->sendMessage($m); });
        same(array(), $history);
    });
}
test('documented boundaries are accepted', function () {
    message()->setFrom(str_repeat('я', 25))->setText(str_repeat('a', 1521))
        ->setRecipients(array_fill(0, 5000, '380000000000'))->setSequenceId(str_repeat('я', 40))->validate();
});
$invalidConfigs = array(array(), array('url' => 'https://api.turbosms.ua'),
    array('url' => 'https://api.turbosms.ua', 'token' => ''),
    array('url' => 'https://api.turbosms.ua', 'token' => array()),
    array('url' => 'https://api.turbosms.ua', 'token' => "bad\r\nheader"));
foreach (array('http://api.turbosms.ua', 'not a url', 'https://user:pass@example.org', 'https://api.turbosms.ua?token=x', 'https://api.turbosms.ua#fragment') as $url) {
    $invalidConfigs[] = array('url' => $url, 'token' => 'placeholder');
}
foreach ($invalidConfigs as $i => $c) {
    test('invalid config ' . $i . ' fails early', function () use ($c) {
        raises(InvalidArgumentException::class, function () use ($c) { new Api($c); });
    });
}
test('changeConfig applies valid updates atomically', function () {
    $a = api(array(response(envelope())), $history);
    raises(InvalidArgumentException::class, function () use ($a) { $a->changeConfig(array()); });
    $a->changeConfig(array('url' => 'https://example.org/api/', 'token' => 'changed-placeholder'));
    $a->sendMessage(message(), '/message/send.json/');
    same('https://example.org/api/message/send.json', (string) $history[0]['request']->getUri());
    same('Bearer changed-placeholder', $history[0]['request']->getHeaderLine('Authorization'));
});
test('failed config update leaves previous config usable', function () {
    $a = api(array(response(envelope())), $history);
    raises(InvalidArgumentException::class, function () use ($a) { $a->changeConfig(array()); });
    $a->sendMessage(message());
    same('https://api.turbosms.ua/message/send.json', (string) $history[0]['request']->getUri());
});
foreach (array('https://example.org', '../send.json', 'message/send.json?x=1', '', array()) as $i => $url) {
    test('invalid endpoint ' . $i . ' never reaches transport', function () use ($url) {
        $a = api(array(), $history);
        raises(InvalidArgumentException::class, function () use ($a, $url) { $a->sendMessage(message(), $url); });
        same(array(), $history);
    });
}

require __DIR__ . '/channels.php';
require __DIR__ . '/queries.php';
require __DIR__ . '/webhooks.php';
require __DIR__ . '/images.php';
require __DIR__ . '/logging.php';

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "PASS: $name\n"; }
    catch (Throwable $e) { ++$failed; fwrite(STDERR, 'FAIL: ' . $name . ' - ' . $e->getMessage() . "\n"); }
}
echo count($tests) . ' tests, ' . $failed . " failures.\n";
exit($failed === 0 ? 0 : 1);
