<?php

foreach (array('viber', 'hybrid') as $channel) {
    test($channel . ' adds image URL only to the Viber block', function () use ($channel) {
        $m = message()->setChannel($channel)->setViberTtl(60)->setSequenceId('image-order-42');
        same($m, $m->setViberImageUrl('https://example.org/order.jpg'));
        $a = api(array(response(envelope())), $history);
        same(array('resp' => envelope(), 'error' => array()), $a->sendMessage($m));
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        same(array('text' => 'Hello', 'sender' => 'Company_name', 'ttl' => 60,
            'image_url' => 'https://example.org/order.jpg'), $payload['viber']);
        same('image-order-42', $payload['sequence_id']);
        if ($channel === 'hybrid') {
            same(array('text' => 'Hello', 'sender' => 'Company_name'), $payload['sms']);
        } else {
            same(false, array_key_exists('sms', $payload));
        }
        same(1, count($history));
    });
    test($channel . ' adds an existing image file ID as an integer', function () use ($channel) {
        $m = message()->setChannel($channel);
        same($m, $m->setViberImageFileId(12345));
        $a = api(array(response(envelope())), $history);
        $a->sendMessage($m);
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        same(12345, $payload['viber']['file_id']);
        same(false, array_key_exists('image_url', $payload['viber']));
        same(1, count($history)); // No implicit upload request.
    });
}
test('image-only Viber omits optional empty text', function () {
    $m = (new \edrard\Turbosms\Message(array('380000000000'), 'Viber Sender'))
        ->setChannel('viber')->setViberImageUrl('https://example.org/image');
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    same(array('recipients' => array('380000000000'),
        'viber' => array('sender' => 'Viber Sender', 'image_url' => 'https://example.org/image')),
        json_decode((string) $history[0]['request']->getBody(), true));
});
test('image-only Viber can use an existing file ID', function () {
    $m = (new \edrard\Turbosms\Message(array('380000000000'), 'Viber Sender'))
        ->setChannel('viber')->setViberImageFileId(12);
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    same(array('sender' => 'Viber Sender', 'file_id' => 12),
        json_decode((string) $history[0]['request']->getBody(), true)['viber']);
});
test('hybrid permits image-only Viber while retaining SMS fallback text and timers', function () {
    $m = message()->setChannel('hybrid')->setViberText('')->setViberImageUrl('https://example.org/image.png')
        ->setViberTtl(60)->setHybridTtl(300)->setSmsText('SMS fallback');
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    $payload = json_decode((string) $history[0]['request']->getBody(), true);
    same(false, array_key_exists('text', $payload['viber']));
    same(60, $payload['viber']['ttl']);
    same(array('text' => 'SMS fallback', 'sender' => 'Company_name', 'hybrid_ttl' => 300), $payload['sms']);
});
test('latest non-null image source replaces the previous source', function () {
    $m = message()->setChannel('viber')->setViberImageUrl('https://example.org/first.jpg')->setViberImageFileId(42);
    same(false, array_key_exists('image_url', $m->getChannels()['viber']));
    same(42, $m->getChannels()['viber']['file_id']);
    $m->setViberImageUrl('https://example.org/last.jpg');
    same(false, array_key_exists('file_id', $m->getChannels()['viber']));
    same('https://example.org/last.jpg', $m->getChannels()['viber']['image_url']);
});
test('clearing image restores exact previous text-only configuration and payload', function () {
    $original = message()->setChannel('viber');
    $m = message()->setChannel('viber')->setViberImageUrl('https://example.org/image.jpg');
    same($m, $m->setViberImageUrl(null));
    same($original->getConfig(), $m->getConfig());
    $m->setViberImageFileId(42);
    same($m, $m->setViberImageFileId(null));
    same($original->getConfig(), $m->getConfig());
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    same(array('text' => 'Hello', 'sender' => 'Company_name'),
        json_decode((string) $history[0]['request']->getBody(), true)['viber']);
});
test('clearing one image option does not clear a different active source', function () {
    $m = message()->setChannel('viber')->setViberImageFileId(42)->setViberImageUrl(null);
    same(42, $m->getChannels()['viber']['file_id']);
    $m->setViberImageUrl('https://example.org/image.jpg')->setViberImageFileId(null);
    same('https://example.org/image.jpg', $m->getChannels()['viber']['image_url']);
});
test('clearing image options permits switching back to unmodified SMS usage', function () {
    $m = message()->setChannel('viber')->setViberImageUrl('https://example.org/image.jpg')
        ->setViberImageUrl(null)->setChannel('sms');
    same(message()->getConfig(), $m->getConfig());
    $a = api(array(response(envelope())), $history);
    $a->sendMessage($m);
    same(array('recipients' => array('380000000000'), 'sms' => array('text' => 'Hello', 'sender' => 'Company_name')),
        json_decode((string) $history[0]['request']->getBody(), true));
});
foreach (array('https://example.org/image.jpg', 'http://example.org/image.png',
    'https://cdn.example.org/resource?signature=test-only&expires=123',
    'https://example.org/%D1%84%D0%BE%D1%82%D0%BE.jpg') as $i => $url) {
    test('valid image URL ' . $i . ' is retained without requiring a filename suffix', function () use ($url) {
        $m = message()->setChannel('viber')->setViberImageUrl($url);
        $m->validate();
        same($url, $m->getChannels()['viber']['image_url']);
    });
}
$invalidImageMessages = array(
    message()->setViberImageUrl('https://example.org/image.jpg'),
    message()->setViberImageFileId(42),
    message()->setChannel('hybrid')->setText('')->setViberImageUrl('https://example.org/image.jpg'),
    message()->setChannel('viber')->setText('')->setViberImageUrl('https://example.org/image.jpg')->setViberImageUrl(null),
    message()->setChannel('viber')->setFrom('')->setViberImageUrl('https://example.org/image.jpg'),
    message()->setChannel('viber')->setText(str_repeat('я', 1001))->setViberImageUrl('https://example.org/image.jpg'),
    message()->setChannel('viber')->setText(array())->setViberImageUrl('https://example.org/image.jpg'),
    message()->setChannel('viber')->setText("\xff")->setViberImageUrl('https://example.org/image.jpg')
);
foreach (array('', 'not a URL', '/local/image.jpg', 'C:\\image.jpg', 'file:///tmp/image.jpg', 'data:image/png;base64,test',
    'ftp://example.org/image.jpg', 'javascript:alert(1)', 'https://user:pass@example.org/image.jpg',
    'https://example.org/image.jpg#fragment', "https://example.org/image\n.jpg", 'https://example.org/image name.jpg',
    array(), true, 42) as $url) {
    $invalidImageMessages[] = message()->setChannel('viber')->setViberImageUrl($url);
}
foreach (array(0, -1, '42', 42.0, true, false, array()) as $id) {
    $invalidImageMessages[] = message()->setChannel('viber')->setViberImageFileId($id);
}
foreach ($invalidImageMessages as $i => $m) {
    test('invalid Viber image or associated content ' . $i . ' never reaches transport', function () use ($m) {
        $a = api(array(), $history);
        raises(InvalidArgumentException::class, function () use ($a, $m) { $a->sendMessage($m); });
        same(array(), $history);
    });
}
test('image request preserves per-recipient errors and only performs one send', function () {
    $body = envelope(802, array(row(), row(406)));
    $a = api(array(response($body)), $history);
    same(array(1 => row(406)), $a->sendMessage(message()->setChannel('hybrid')->setViberImageUrl('https://example.org/image.jpg'))['error']);
    same(1, count($history));
});
test('image API rejection exposes structured error without retries or downloads', function () {
    $body = array('response_code' => 411, 'response_status' => 'NOT_ALLOWED_MESSAGE_DATA', 'response_result' => null);
    $a = api(array(response($body)), $history);
    $e = raises(\edrard\Turbosms\Exception\ApiException::class, function () use ($a) {
        $a->sendMessage(message()->setChannel('viber')->setViberImageUrl('https://example.org/image.jpg'));
    });
    same($body, $e->getResponse());
    same(1, count($history));
});
