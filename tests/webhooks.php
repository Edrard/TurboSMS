<?php

function webhookRow($status = 'DELIVRD') {
    return array('message_id' => 'f83f8868-5e46-c6cf-e4fb-615e5a293754', 'status' => $status,
        'sent_date' => '2026-11-25 10:00:00', 'dlr_date' => '2026-11-25 10:00:01', 'error_code' => '000');
}
function webhookPayload($type = 'DLR_SMS_API', $data = null, $id = 'test-event-42') {
    return array('id' => $id, 'signature' => sha1('test-webhook-secret' . $id), 'type' => $type,
        'date' => '2026-11-25 10:00:02', 'try' => 1, 'data' => $data === null ? webhookRow() : $data);
}
function webhook() { return new \edrard\Turbosms\Webhook('test-webhook-secret'); }

test('SMS JSON webhook verifies signature and produces a normalized delivery', function () {
    $payload = webhookPayload();
    same(array('id' => 'test-event-42', 'type' => 'DLR_SMS_API', 'date' => $payload['date'], 'try' => 1,
        'deliveries' => array(array('message_id' => webhookRow()['message_id'], 'channel' => 'sms',
            'status' => 'Delivered', 'raw_status' => 'DELIVRD', 'sent' => webhookRow()['sent_date'],
            'updated' => webhookRow()['dlr_date'], 'error_code' => '000', 'data' => webhookRow()))),
        webhook()->parse(json_encode($payload)));
});
foreach (array('sms', 'viber') as $channel) {
    test('Viber HTTP API webhook retains actual delivery channel ' . $channel, function () use ($channel) {
        $row = webhookRow('READ');
        $row['channel'] = $channel;
        $event = webhook()->parse(json_encode(webhookPayload('DLR_VIBER_API', $row)));
        same($channel, $event['deliveries'][0]['channel']);
        same('Read', $event['deliveries'][0]['status']);
    });
}
foreach (array('DELIVRD' => 'Delivered', 'READ' => 'Read', 'EXPIRED' => 'Expired',
    'UNDELIV' => 'Undelivered', 'REJECTD' => 'Rejected', 'FUTURE_STATE' => 'Unknown') as $raw => $state) {
    test('webhook maps delivery state ' . $raw . ' without losing original value', function () use ($raw, $state) {
        $event = webhook()->parse(json_encode(webhookPayload('DLR_SMS_API', webhookRow($raw))));
        same($state, $event['deliveries'][0]['status']);
        same($raw, $event['deliveries'][0]['raw_status']);
    });
}
test('batch delivery data is validated and normalized in order', function () {
    $rows = array(webhookRow(), webhookRow('EXPIRED'));
    $rows[1]['message_id'] = 'c51f4301-5e3c-78c9-134b-d1ce1e56a9ff';
    $rows[1]['error_code'] = '030';
    $rows[1]['extra'] = array('future' => 'retained');
    $rows[1]['sent_date'] = null;
    $event = webhook()->parse(json_encode(webhookPayload('DLR_SMS_API', $rows)));
    same(2, count($event['deliveries']));
    same('030', $event['deliveries'][1]['error_code']);
    same(null, $event['deliveries'][1]['sent']);
    same($rows[1], $event['deliveries'][1]['data']);
    same(false, array_key_exists('signature', $event));
});
test('JSON content type accepts case and parameters', function () {
    same('test-event-42', webhook()->parse(json_encode(webhookPayload()), 'Application/JSON; charset=UTF-8')['id']);
});
test('form with nested fields accepts attempt strings and preserves zero-padded errors', function () {
    $payload = webhookPayload();
    $payload['try'] = '2';
    $event = webhook()->parse(http_build_query($payload), 'application/x-www-form-urlencoded; charset=utf-8');
    same(2, $event['try']);
    same('000', $event['deliveries'][0]['error_code']);
});
test('parsed multipart form supports JSON data field', function () {
    $payload = webhookPayload();
    $payload['try'] = '1';
    $payload['data'] = json_encode(array(webhookRow(), webhookRow('REJECTD')));
    $event = webhook()->parseForm($payload);
    same(2, count($event['deliveries']));
    same('Rejected', $event['deliveries'][1]['status']);
});
test('URL-encoded form supports JSON data field', function () {
    $payload = webhookPayload();
    $payload['data'] = json_encode(webhookRow());
    same('Delivered', webhook()->parse(http_build_query($payload), 'application/x-www-form-urlencoded')['deliveries'][0]['status']);
});
test('numeric event ID and uppercase signature are accepted without changing signed ID', function () {
    $payload = webhookPayload('DLR_SMS_API', null, 42);
    $payload['signature'] = strtoupper($payload['signature']);
    same('42', webhook()->parse(json_encode($payload))['id']);
});
test('large numeric JSON event ID is not rounded before signature verification', function () {
    $payload = webhookPayload('DLR_SMS_API', null, '9223372036854775808123');
    $json = str_replace('"id":"9223372036854775808123"', '"id":9223372036854775808123', json_encode($payload));
    same('9223372036854775808123', webhook()->parse($json)['id']);
});
test('string event ID keeps leading zeros when verifying signature', function () {
    same('00042', webhook()->parse(json_encode(webhookPayload('DLR_SMS_API', null, '00042')))['id']);
});
test('documented signature is SHA1(secret + id), not HMAC or reversed concatenation', function () {
    foreach (array(sha1('test-event-42test-webhook-secret'), hash_hmac('sha1', 'test-event-42', 'test-webhook-secret')) as $signature) {
        $payload = webhookPayload();
        $payload['signature'] = $signature;
        raises(\edrard\Turbosms\Exception\WebhookAuthenticationException::class, function () use ($payload) {
            webhook()->parse(json_encode($payload));
        });
    }
});
test('signature authenticates ID only, matching provider scheme rather than claiming body integrity', function () {
    $payload = webhookPayload();
    $payload['data']['status'] = 'EXPIRED';
    same('Expired', webhook()->parse(json_encode($payload))['deliveries'][0]['status']);
});
foreach (array(null, '', 'bad', str_repeat('0', 40), array(), true) as $i => $signature) {
    test('invalid signature ' . $i . ' never invokes consumer', function () use ($signature) {
        $payload = webhookPayload();
        $payload['signature'] = $signature;
        $called = false;
        $e = raises(\edrard\Turbosms\Exception\WebhookAuthenticationException::class, function () use ($payload, &$called) {
            webhook()->handle(json_encode($payload), function () use (&$called) { $called = true; });
        });
        same(false, $called);
        same('Webhook signature verification failed.', $e->getMessage());
        same(null, $e->getPrevious());
    });
}
test('wrong secret fails and is not exposed in exception message', function () {
    $parser = new \edrard\Turbosms\Webhook('different-test-only-secret');
    $e = raises(\edrard\Turbosms\Exception\WebhookAuthenticationException::class, function () use ($parser) {
        $parser->parse(json_encode(webhookPayload()));
    });
    same(false, strpos($e->getMessage(), 'different-test-only-secret') !== false);
});
test('successful consumer is called once and then acknowledgment becomes available', function () {
    $stored = array();
    $ack = webhook()->handle(json_encode(webhookPayload()), function (array $event) use (&$stored) { $stored[] = $event; });
    same('OK', $ack);
    same(1, count($stored));
    same('test-event-42', $stored[0]['id']);
});
test('consumer storage failure propagates without returning acknowledgment', function () {
    $failure = new RuntimeException('test storage failure');
    $ack = null;
    $caught = raises(RuntimeException::class, function () use ($failure, &$ack) {
        $ack = webhook()->handle(json_encode(webhookPayload()), function () use ($failure) { throw $failure; });
    });
    same($failure, $caught);
    same(null, $ack);
});
test('retry event reaches consumer but can be stored once using event ID', function () {
    $inbox = array();
    $store = function (array $event) use (&$inbox) { if (!isset($inbox[$event['id']])) { $inbox[$event['id']] = $event; } };
    $payload = webhookPayload();
    same('OK', webhook()->handle(json_encode($payload), $store));
    $payload['try'] = 2;
    same('OK', webhook()->handle(json_encode($payload), $store));
    same(1, count($inbox));
    same(1, $inbox['test-event-42']['try']);
});
test('invalid row in a batch prevents all consumer processing', function () {
    $rows = array(webhookRow(), array('message_id' => webhookRow()['message_id']));
    $called = false;
    raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($rows, &$called) {
        webhook()->handle(json_encode(webhookPayload('DLR_SMS_API', $rows)), function () use (&$called) { $called = true; });
    });
    same(false, $called);
});
foreach (array('INBOX_VIBER_SESSION', 'DLR_SMS_SQL', 'VIBER_BUTTON_CLICK', 'FUTURE_EVENT') as $type) {
    test('unsupported webhook type ' . $type . ' is explicit', function () use ($type) {
        raises(\edrard\Turbosms\Exception\UnsupportedWebhookException::class, function () use ($type) {
            webhook()->parse(json_encode(webhookPayload($type)));
        });
    });
}
foreach (array('', '{', 'null', '[]', '{}', '<html>private body</html>', false, array()) as $i => $body) {
    test('invalid webhook body ' . $i . ' is rejected without reflecting it', function () use ($body) {
        $e = raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($body) { webhook()->parse($body); });
        same(false, strpos($e->getMessage(), 'private body') !== false);
    });
}
foreach (array('', 'text/plain', 'multipart/form-data', array()) as $i => $type) {
    test('unsupported raw webhook content type ' . $i . ' is rejected', function () use ($type) {
        raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($type) { webhook()->parse(json_encode(webhookPayload()), $type); });
    });
}
$invalidPayloads = array();
foreach (array('id', 'type', 'date', 'try', 'data') as $field) {
    $payload = webhookPayload();
    unset($payload[$field]);
    $invalidPayloads[] = $payload;
}
foreach (array('', null, true, 1.5, array(), "bad\nID") as $id) {
    $payload = webhookPayload();
    $payload['id'] = $id;
    $invalidPayloads[] = $payload;
}
foreach (array(0, -1, true, 1.0, '1', array()) as $attempt) {
    $payload = webhookPayload();
    $payload['try'] = $attempt;
    $invalidPayloads[] = $payload;
}
foreach (array('2026-02-30 10:00:00', '2026-11-25', '2026-11-25 25:00:00', null, array()) as $date) {
    $payload = webhookPayload();
    $payload['date'] = $date;
    $invalidPayloads[] = $payload;
}
foreach (array(null, array(), 'data', array('arbitrary' => webhookRow()), array(null)) as $data) {
    $payload = webhookPayload();
    $payload['data'] = $data;
    $invalidPayloads[] = $payload;
}
foreach (array('message_id', 'status', 'sent_date', 'dlr_date', 'error_code') as $field) {
    $row = webhookRow();
    unset($row[$field]);
    $invalidPayloads[] = webhookPayload('DLR_SMS_API', $row);
}
foreach (array('message_id' => 'invalid', 'status' => 'delivered', 'sent_date' => 'bad',
    'dlr_date' => '2026-02-30 10:00:00', 'error_code' => false) as $field => $value) {
    $row = webhookRow();
    $row[$field] = $value;
    $invalidPayloads[] = webhookPayload('DLR_SMS_API', $row);
}
$invalidPayloads[] = webhookPayload('DLR_VIBER_API');
foreach (array('email', array(), false) as $channel) {
    $row = webhookRow();
    $row['channel'] = $channel;
    $invalidPayloads[] = webhookPayload('DLR_VIBER_API', $row);
}
$row = webhookRow();
$row['channel'] = 'viber';
$invalidPayloads[] = webhookPayload('DLR_SMS_API', $row);
foreach ($invalidPayloads as $i => $payload) {
    test('invalid webhook envelope or delivery ' . $i . ' is rejected', function () use ($payload) {
        raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($payload) {
            webhook()->parse(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
        });
    });
}
test('body byte limit is enforced for raw and parsed form input', function () {
    $body = json_encode(webhookPayload());
    same('test-event-42', (new \edrard\Turbosms\Webhook('test-webhook-secret', strlen($body)))->parse($body)['id']);
    $small = new \edrard\Turbosms\Webhook('test-webhook-secret', strlen($body) - 1);
    raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($small, $body) { $small->parse($body); });
    raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($small) { $small->parseForm(webhookPayload()); });
});
test('form parsing rejects malformed data and overflowing attempts', function () {
    foreach (array('not JSON', 'null') as $data) {
        $payload = webhookPayload();
        $payload['data'] = $data;
        raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($payload) { webhook()->parseForm($payload); });
    }
    $payload = webhookPayload();
    $payload['try'] = str_repeat('9', 40);
    raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($payload) { webhook()->parseForm($payload); });
});
test('excessive form nesting is rejected before parse_str can truncate it', function () {
    raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () {
        webhook()->parse('data[a][b][c][d]=x', 'application/x-www-form-urlencoded');
    });
});
test('form variable limit is checked before parse_str can produce a partial event', function () {
    $limit = (int) ini_get('max_input_vars');
    if ($limit > 0) {
        raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($limit) {
            webhook()->parse(implode('&', array_fill(0, $limit + 1, 'field=value')), 'application/x-www-form-urlencoded');
        });
    }
});
test('parsed multipart form at variable boundary is not acknowledged as a complete event', function () {
    $limit = (int) ini_get('max_input_vars');
    if ($limit > 0) {
        $payload = webhookPayload();
        $payload['padding'] = array_fill(0, $limit, 'test');
        raises(\edrard\Turbosms\Exception\InvalidWebhookException::class, function () use ($payload) { webhook()->parseForm($payload); });
    }
});
foreach (array('', null, array(), false) as $i => $secret) {
    test('invalid webhook secret configuration ' . $i . ' fails early', function () use ($secret) {
        raises(InvalidArgumentException::class, function () use ($secret) { new \edrard\Turbosms\Webhook($secret); });
    });
}
foreach (array(0, -1, '1024', true) as $limit) {
    test('invalid webhook limit ' . var_export($limit, true) . ' fails early', function () use ($limit) {
        raises(InvalidArgumentException::class, function () use ($limit) { new \edrard\Turbosms\Webhook('test-webhook-secret', $limit); });
    });
}
