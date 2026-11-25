<?php

function deliveryRow($status = 'Delivered', $channel = 'sms') {
    return array('message_id' => 'f83f8868-5e46-c6cf-e4fb-615e5a293754',
        'response_code' => 0, 'response_status' => 'OK', 'recipient' => '380000000000',
        'sent' => '2026-11-25 10:00:00', 'updated' => '2026-11-25 10:00:01',
        'status' => $status, 'channel' => $channel);
}
function queryEnvelope($result) {
    return array('response_code' => 0, 'response_status' => 'OK', 'response_result' => $result);
}

test('status request uses messages array, bearer authentication and read timeout', function () {
    $body = queryEnvelope(array(deliveryRow()));
    $a = api(array(response($body)), $history);
    same(array('resp' => $body, 'error' => array()), $a->getMessageStatus(array(7 => deliveryRow()['message_id'])));
    $request = $history[0]['request'];
    same('POST', $request->getMethod());
    same('https://api.turbosms.ua/message/status.json', (string) $request->getUri());
    same(array('messages' => array(deliveryRow()['message_id'])), json_decode((string) $request->getBody(), true));
    same('Bearer test-only-placeholder', $request->getHeaderLine('Authorization'));
    same(30, $history[0]['options']['timeout']);
    same(false, $history[0]['options']['allow_redirects']);
    same(false, $history[0]['options']['http_errors']);
    same(1, count($history));
});

test('status accepts UUID-shaped IDs without enforcing an RFC version', function () {
    $id = '2D80C1C0-5E3C-C8C9-134B-2FC4FCBFA0BA';
    $row = deliveryRow();
    $row['message_id'] = $id;
    $a = api(array(response(queryEnvelope(array($row)))), $history);
    $a->getMessageStatus(array($id));
    same(array('messages' => array($id)), json_decode((string) $history[0]['request']->getBody(), true));
});

test('status request supports several IDs and preserves nullable Viber data and extra fields', function () {
    $viber = deliveryRow('Read', 'viber');
    $viber['click_time'] = null;
    $viber['rejected_status'] = '';
    $viber['future_field'] = 'kept';
    // No deprecated type field is required.
    $sms = deliveryRow('Queued');
    $sms['message_id'] = 'c51f4301-5e3c-78c9-134b-d1ce1e56a9ff';
    $sms['sent'] = null;
    $body = queryEnvelope(array($viber, $sms));
    $a = api(array(response($body)), $history);
    same(array('resp' => $body, 'error' => array()), $a->getMessageStatus(array($viber['message_id'], $sms['message_id'])));
    same(array('messages' => array($viber['message_id'], $sms['message_id'])),
        json_decode((string) $history[0]['request']->getBody(), true));
});

test('unknown message IDs are returned as row errors with original indices', function () {
    $bad = array('message_id' => '2d80c1c0-5e3c-78c9-134b-2fc4fcbfa0ba',
        'response_code' => 414, 'response_status' => 'NOT_ALLOWED_MESSAGE_ID');
    $body = queryEnvelope(array(deliveryRow(), $bad));
    $a = api(array(response($body)), $history);
    same(array('resp' => $body, 'error' => array(1 => $bad)),
        $a->getMessageStatus(array(deliveryRow()['message_id'], $bad['message_id'])));
});

foreach (array('Queued', 'Accepted', 'Sent', 'Delivered', 'Read', 'Expired', 'Undelivered', 'Rejected', 'Unknown', 'Failed', 'Cancelled', 'FutureState') as $status) {
    test('delivery state ' . $status . ' is retained separately from API errors', function () use ($status) {
        $row = deliveryRow($status, 'viber');
        $row['rejected_status'] = $status === 'Rejected' ? 'SRVC_NOT_VIBER_USER' : '';
        $a = api(array(response(queryEnvelope(array($row)))), $history);
        $result = $a->getMessageStatus(array($row['message_id']));
        same($status, $result['resp']['response_result'][0]['status']);
        same(array(), $result['error']);
        same($row['rejected_status'], $result['resp']['response_result'][0]['rejected_status']);
    });
}

foreach (array(0, 100, 12345.67, -0.25) as $amount) {
    test('balance preserves numeric value ' . $amount . ' without rounding', function () use ($amount) {
        $body = queryEnvelope(array('balance' => $amount));
        $a = api(array(response($body)), $history);
        same(array('resp' => $body, 'error' => array()), $a->getBalance());
        $request = $history[0]['request'];
        same('POST', $request->getMethod());
        same('https://api.turbosms.ua/user/balance.json', (string) $request->getUri());
        same('', (string) $request->getBody());
        same('Bearer test-only-placeholder', $request->getHeaderLine('Authorization'));
        same(30, $history[0]['options']['timeout']);
        same(1, count($history));
    });
}

$invalidIds = array(array(), array(''), array('not-an-id'), array(42), array(true), array(null), array(array()),
    array(deliveryRow()['message_id'], 'bad'), array("f83f8868-5e46-c6cf-e4fb-615e5a293754\n"));
foreach ($invalidIds as $i => $ids) {
    test('invalid status IDs ' . $i . ' never reach transport', function () use ($ids) {
        $a = api(array(), $history);
        raises(InvalidArgumentException::class, function () use ($a, $ids) { $a->getMessageStatus($ids); });
        same(array(), $history);
    });
}

$invalidStatusResults = array(null, array(), 'bad', array('row' => deliveryRow()), array(null), array(array()));
foreach (array('message_id', 'response_code', 'response_status', 'status', 'channel', 'recipient', 'sent', 'updated') as $field) {
    $row = deliveryRow();
    unset($row[$field]);
    $invalidStatusResults[] = array($row);
}
foreach (array('message_id', 'status', 'channel', 'recipient', 'sent', 'updated', 'rejected_status', 'click_time') as $field) {
    $row = deliveryRow();
    $row[$field] = array();
    $invalidStatusResults[] = array($row);
}
foreach ($invalidStatusResults as $i => $rows) {
    test('invalid status response ' . $i . ' produces a structured response error', function () use ($rows) {
        $a = api(array(response(queryEnvelope($rows))), $history);
        raises(\edrard\Turbosms\Exception\InvalidResponseException::class, function () use ($a) {
            $a->getMessageStatus(array(deliveryRow()['message_id']));
        });
        same(1, count($history));
    });
}

$invalidBalances = array(null, array(), 'bad', array('balance' => null), array('balance' => '123.45'),
    array('balance' => true), array('balance' => array()), array('amount' => 123));
foreach ($invalidBalances as $i => $value) {
    test('invalid balance response ' . $i . ' is rejected without coercing to zero', function () use ($value) {
        $a = api(array(response(queryEnvelope($value))), $history);
        raises(\edrard\Turbosms\Exception\InvalidResponseException::class, function () use ($a) { $a->getBalance(); });
    });
}
test('non-finite balance is rejected', function () {
    $a = api(array(new \GuzzleHttp\Psr7\Response(200, array(), '{"response_code":0,"response_status":"OK","response_result":{"balance":1e999}}')), $history);
    raises(\edrard\Turbosms\Exception\InvalidResponseException::class, function () use ($a) { $a->getBalance(); });
});

foreach (array('getBalance', 'getMessageStatus') as $method) {
    $invoke = function ($a) use ($method) {
        return $method === 'getBalance' ? $a->getBalance() : $a->getMessageStatus(array(deliveryRow()['message_id']));
    };
    test($method . ' exposes top-level API failures and does not use send success codes', function () use ($invoke) {
        foreach (array(401, 800) as $code) {
            $body = array('response_code' => $code, 'response_status' => 'ERROR', 'response_result' => null);
            $a = api(array(response($body)), $history);
            $e = raises(\edrard\Turbosms\Exception\ApiException::class, function () use ($invoke, $a) { $invoke($a); });
            same($code, $e->getCode());
            same($body, $e->getResponse());
        }
    });
    foreach (array('', '<html>private diagnostic</html>', 'null', '{"response_code":"0","response_status":"OK","response_result":{}}') as $i => $body) {
        test($method . ' invalid JSON or envelope ' . $i . ' is sanitized', function () use ($invoke, $body) {
            $a = api(array(new \GuzzleHttp\Psr7\Response(200, array(), $body)), $history);
            $e = raises(\edrard\Turbosms\Exception\InvalidResponseException::class, function () use ($invoke, $a) { $invoke($a); });
            same(false, strpos($e->getMessage(), 'private diagnostic') !== false);
            same(false, strpos($e->getMessage(), 'sequence_id') !== false);
        });
    }
    test($method . ' HTTP error is read-only, sanitized and not retried', function () use ($invoke) {
        $a = api(array(new \GuzzleHttp\Psr7\Response(500, array(), 'private diagnostic')), $history);
        $e = raises(\edrard\Turbosms\Exception\TransportException::class, function () use ($invoke, $a) { $invoke($a); });
        same(500, $e->getCode());
        same(false, strpos($e->getMessage(), 'private diagnostic') !== false);
        same(false, strpos($e->getMessage(), 'sequence_id') !== false);
        same(1, count($history));
    });
    test($method . ' connection error does not expose request or original diagnostics', function () use ($invoke) {
        $a = api(array(new \GuzzleHttp\Exception\ConnectException('private diagnostic', new \GuzzleHttp\Psr7\Request('POST', 'https://api.turbosms.ua'))), $history);
        $e = raises(\edrard\Turbosms\Exception\TransportException::class, function () use ($invoke, $a) { $invoke($a); });
        same(null, $e->getPrevious());
        same(false, strpos($e->getMessage(), 'private diagnostic') !== false);
        same(1, count($history));
    });
    test($method . ' unreadable body is a read-only transport error', function () use ($invoke) {
        $stream = \GuzzleHttp\Psr7\Utils::streamFor('response');
        $stream->close();
        $a = api(array(new \GuzzleHttp\Psr7\Response(200, array(), $stream)), $history);
        raises(\edrard\Turbosms\Exception\TransportException::class, function () use ($invoke, $a) { $invoke($a); });
    });
}

test('send, status, balance and another send use independent routes and reset row errors', function () {
    $sendBody = envelope(802, array(row(), row(406)));
    $statusBody = queryEnvelope(array(deliveryRow()));
    $balanceBody = queryEnvelope(array('balance' => 42.5));
    $a = api(array(response($sendBody), response($statusBody), response($balanceBody), response(envelope())), $history);
    same(array(1 => row(406)), $a->sendMessage(message())['error']);
    same(array(), $a->getMessageStatus(array(deliveryRow()['message_id']))['error']);
    same(array(), $a->getBalance()['error']);
    same(array(), $a->sendMessage(message()->setChannel('hybrid')->setViberTtl(60))['error']);
    same(array('/message/send.json', '/message/status.json', '/user/balance.json', '/message/send.json'),
        array_map(function ($entry) { return $entry['request']->getUri()->getPath(); }, $history));
    same(array(0, 30, 30, 0), array_map(function ($entry) { return $entry['options']['timeout']; }, $history));
});

test('balance after an all-error status query does not carry status errors', function () {
    $bad = array('message_id' => deliveryRow()['message_id'], 'response_code' => 414, 'response_status' => 'NOT_ALLOWED_MESSAGE_ID');
    $a = api(array(response(queryEnvelope(array($bad))), response(queryEnvelope(array('balance' => 0)))), $history);
    same(array($bad), $a->getMessageStatus(array($bad['message_id']))['error']);
    same(array(), $a->getBalance()['error']);
});

test('read endpoints use changed base URL and credentials', function () {
    $a = api(array(response(queryEnvelope(array(deliveryRow()))), response(queryEnvelope(array('balance' => 10)))), $history);
    $a->changeConfig(array('url' => 'https://example.org/api/', 'token' => 'changed-placeholder'));
    $a->getMessageStatus(array(deliveryRow()['message_id']));
    $a->getBalance();
    same('https://example.org/api/message/status.json', (string) $history[0]['request']->getUri());
    same('https://example.org/api/user/balance.json', (string) $history[1]['request']->getUri());
    same('Bearer changed-placeholder', $history[1]['request']->getHeaderLine('Authorization'));
});
