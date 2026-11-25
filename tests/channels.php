<?php

// Registered by run.php; uses only MockHandler responses and placeholder data.

test('Viber mode sends one Viber block and retains the existing result shape', function () {
    $a = api(array(response(envelope())), $history);
    $m = message();
    same($m, $m->setChannel('viber'));
    same(array('resp' => envelope(), 'error' => array()), $a->sendMessage($m));
    same(array('recipients' => array('380000000000'), 'viber' => array('text' => 'Hello', 'sender' => 'Company_name')),
        json_decode((string) $history[0]['request']->getBody(), true));
    same(1, count($history));
});

test('hybrid mode sends both blocks in one request with shared defaults', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setChannel('hybrid'));
    same(array('recipients' => array('380000000000'),
        'viber' => array('text' => 'Hello', 'sender' => 'Company_name'),
        'sms' => array('text' => 'Hello', 'sender' => 'Company_name')),
        json_decode((string) $history[0]['request']->getBody(), true));
    same(1, count($history));
});

test('hybrid channel-specific senders and text override common setters', function () {
    $a = api(array(response(envelope())), $history);
    $m = message()->setChannel('hybrid');
    same($m, $m->setViberFrom('Viber Sender')->setViberText('Повідомлення у Viber')
        ->setSmsFrom('SMSAlpha')->setSmsText('Резервне SMS')->setViberTtl(60)->setHybridTtl(300));
    // Later changes of shared defaults must not replace explicit channel overrides.
    $m->setText('Changed common text')->setFrom('CommonSender')->setSequenceId('hybrid-order-42');
    $a->sendMessage($m);
    same(array('recipients' => array('380000000000'),
        'viber' => array('text' => 'Повідомлення у Viber', 'sender' => 'Viber Sender', 'ttl' => 60),
        'sms' => array('text' => 'Резервне SMS', 'sender' => 'SMSAlpha', 'hybrid_ttl' => 300),
        'sequence_id' => 'hybrid-order-42'), json_decode((string) $history[0]['request']->getBody(), true));
    same(0, $history[0]['options']['timeout']);
    same(1, count($history));
});

test('Viber wait is transmitted without adding an SMS expiration deadline', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setChannel('hybrid')->setViberTtl(60));
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    same(60, $payload['viber']['ttl']);
    same(false, array_key_exists('hybrid_ttl', $payload['sms']));
    same(false, array_key_exists('ttl', $payload['sms']));
    same(false, array_key_exists('channel', $payload));
});

test('Viber-only ttl is inside Viber block and sequence remains at top level', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setChannel('viber')->setViberTtl(120)->setSequenceId('viber-order-42'));
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    same(120, $payload['viber']['ttl']);
    same('viber-order-42', $payload['sequence_id']);
    same(false, array_key_exists('sms', $payload));
    same(false, array_key_exists('ttl', $payload));
});

test('hybrid deadline can be shorter than Viber lifetime for expiring messages', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setChannel('hybrid')->setViberTtl(120)->setHybridTtl(60));
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    same(120, $payload['viber']['ttl']);
    same(60, $payload['sms']['hybrid_ttl']);
});

test('equal Viber lifetime and hybrid deadline are allowed for the API documented case', function () {
    $a = api(array(response(envelope())), $history);
    $a->sendMessage(message()->setChannel('hybrid')->setViberTtl(60)->setHybridTtl(60));
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    same(60, $payload['viber']['ttl']);
    same(60, $payload['sms']['hybrid_ttl']);
});

test('null clears overrides and restores shared defaults and API timing defaults', function () {
    $m = message()->setChannel('hybrid')->setViberFrom('Viber Sender')->setViberText('Viber text')
        ->setSmsFrom('SMSAlpha')->setSmsText('SMS text')->setViberTtl(60)->setHybridTtl(300);
    $m->setViberFrom(null)->setViberText(null)->setSmsFrom(null)->setSmsText(null)->setViberTtl(null)->setHybridTtl(null);
    same(array('viber' => array('text' => 'Hello', 'sender' => 'Company_name'),
        'sms' => array('text' => 'Hello', 'sender' => 'Company_name')), $m->getChannels());
    $m->setChannel('sms');
    same(message()->getConfig(), $m->getConfig());
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    same(array('recipients' => array('380000000000'), 'sms' => array('text' => 'Hello', 'sender' => 'Company_name')),
        json_decode((string) $history[0]['request']->getBody(), true));
});

test('channel-specific settings can provide all required content without shared defaults', function () {
    $m = new \edrard\Turbosms\Message(array('380000000000'));
    $m->setChannel('hybrid')->setViberFrom('Viber Sender')->setViberText('Viber text')->setSmsFrom('SMSAlpha')->setSmsText('SMS text');
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    same('Viber text', $payload['viber']['text']);
    same('SMS text', $payload['sms']['text']);
});

test('SMS-specific setters work without selecting a new channel', function () {
    $m = message()->setSmsFrom('NewAlpha')->setSmsText('New SMS');
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    same(array('recipients' => array('380000000000'), 'sms' => array('text' => 'New SMS', 'sender' => 'NewAlpha')),
        json_decode((string) $history[0]['request']->getBody(), true));
});

test('hybrid applies Viber and SMS text limits independently in Unicode characters', function () {
    $m = message()->setChannel('hybrid')->setText(str_repeat('a', 1521))->setViberText(str_repeat('я', 1000));
    $m->validate();
    same(str_repeat('я', 1000), $m->getChannels()['viber']['text']);
    same(str_repeat('a', 1521), $m->getChannels()['sms']['text']);
});

foreach (array(30, 86400) as $ttl) {
    test('Viber ttl boundary ' . $ttl . ' is serialized as an integer', function () use ($ttl) {
        $a = api(array(response(envelope())), $history);
        $a->sendMessage(message()->setChannel('viber')->setViberTtl($ttl));
        same($ttl, json_decode((string) $history[0]['request']->getBody(), true)['viber']['ttl']);
    });
}
foreach (array(1, 86400, 86401) as $ttl) {
    test('positive hybrid deadline ' . $ttl . ' has no invented upper bound', function () use ($ttl) {
        $a = api(array(response(envelope())), $history);
        $a->sendMessage(message()->setChannel('hybrid')->setHybridTtl($ttl));
        same($ttl, json_decode((string) $history[0]['request']->getBody(), true)['sms']['hybrid_ttl']);
    });
}

foreach (array('viber', 'hybrid') as $channel) {
    test($channel . ' preserves partial rejection handling', function () use ($channel) {
        $a = api(array(response(envelope(802, array(row(), row(406))))), $history);
        same(array(1 => row(406)), $a->sendMessage(message()->setChannel($channel))['error']);
        same(1, count($history));
    });
    test($channel . ' preserves duplicate response and never resends automatically', function () use ($channel) {
        $body = array('response_code' => 507, 'response_status' => 'FAILED_DUPLICATE_REQUEST', 'response_result' => array(row()));
        $a = api(array(response($body)), $history);
        $e = raises(\edrard\Turbosms\Exception\DuplicateRequestException::class, function () use ($a, $channel) {
            $a->sendMessage(message()->setChannel($channel)->setSequenceId('logical-send-42'));
        });
        same($body, $e->getResponse());
        same(1, count($history));
    });
    test($channel . ' transport failure never triggers a separate SMS request', function () use ($channel) {
        $a = api(array(new \GuzzleHttp\Psr7\Response(500)), $history);
        raises(\edrard\Turbosms\Exception\TransportException::class, function () use ($a, $channel) {
            $a->sendMessage(message()->setChannel($channel));
        });
        same(1, count($history));
    });
}

$invalidChannelMessages = array(
    message()->setChannel(''), message()->setChannel('email'), message()->setChannel('Viber'),
    message()->setChannel(null), message()->setChannel(array()),
    message()->setViberTtl(60), message()->setHybridTtl(300), message()->setViberText('Ignored?'),
    message()->setViberFrom('Ignored?'), message()->setChannel('viber')->setHybridTtl(300),
    message()->setChannel('viber')->setSmsText('Ignored?'), message()->setChannel('viber')->setSmsFrom('Ignored?'),
    message()->setChannel('viber')->setText(str_repeat('я', 1001)),
    message()->setChannel('hybrid')->setText(str_repeat('a', 1001)),
    message()->setChannel('hybrid')->setViberText(''), message()->setChannel('hybrid')->setSmsText(''),
    message()->setChannel('hybrid')->setViberFrom(''), message()->setChannel('hybrid')->setSmsFrom(''),
    message()->setChannel('hybrid')->setSmsText(str_repeat('a', 1522)),
    message()->setChannel('hybrid')->setViberText("\xff"), message()->setChannel('viber')->setViberText(array()),
    message()->setChannel('hybrid')->setViberFrom(str_repeat('я', 26)),
    message()->setChannel('hybrid')->setSmsFrom("bad\nname"), message()->setChannel('hybrid')->setSmsFrom(array())
);
foreach (array(0, -1, 29, 86401, '60', 60.0, true, false, array()) as $ttl) {
    $invalidChannelMessages[] = message()->setChannel('viber')->setViberTtl($ttl);
    $invalidChannelMessages[] = message()->setChannel('hybrid')->setViberTtl($ttl);
}
foreach (array(0, -1, '300', 300.0, true, false, array()) as $ttl) {
    $invalidChannelMessages[] = message()->setChannel('hybrid')->setHybridTtl($ttl);
}
foreach ($invalidChannelMessages as $i => $m) {
    test('invalid channel content or timing ' . $i . ' never reaches transport', function () use ($m) {
        $a = api(array(), $history);
        raises(InvalidArgumentException::class, function () use ($a, $m) { $a->sendMessage($m); });
        same(array(), $history);
    });
}
