<?php

// Optional loopback-only integration test. See docs/WEBHOOKS.uk.md for server setup.
require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || count($argv) !== 3 ||
    preg_match('~^http://127\.0\.0\.1:[0-9]+/examples/webhook\.php$~D', $argv[1]) !== 1) {
    fwrite(STDERR, "Usage: php -d extension=pdo_sqlite tests/webhook-endpoint.php http://127.0.0.1:PORT/examples/webhook.php ABSOLUTE_TEST_DB_PATH\n");
    exit(1);
}
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This optional integration test requires PDO SQLite.\n");
    exit(1);
}
if (file_exists($argv[2])) {
    fwrite(STDERR, "Use a fresh disposable test database path.\n");
    exit(1);
}
$client = new GuzzleHttp\Client(array('timeout' => 15, 'http_errors' => false, 'allow_redirects' => false));
$url = $argv[1];
$auth = array('endpoint-test-user', 'endpoint-test-password');
$checks = 0;
function endpointSame($expected, $actual) {
    if ($expected !== $actual) { throw new RuntimeException('Integration assertion failed.'); }
}
function endpointCheck($name, callable $test) {
    global $checks;
    $test();
    ++$checks;
    echo 'PASS: ' . $name . "\n";
}
function endpointPayload($id = 'endpoint-test-1', $type = 'DLR_SMS_API') {
    $row = array('message_id' => 'f83f8868-5e46-c6cf-e4fb-615e5a293754', 'status' => 'DELIVRD',
        'sent_date' => '2026-11-25 10:00:00', 'dlr_date' => '2026-11-25 10:00:01', 'error_code' => '000');
    if ($type === 'DLR_VIBER_API') { $row['channel'] = 'sms'; }
    return array('id' => $id, 'signature' => sha1('endpoint-test-secret' . $id), 'type' => $type,
        'date' => '2026-11-25 10:00:02', 'try' => 1, 'data' => $row);
}
try {
    endpointCheck('GET is rejected', function () use ($client, $url) {
        endpointSame(405, $client->get($url)->getStatusCode());
    });
    endpointCheck('Basic authentication is required when configured', function () use ($client, $url) {
        endpointSame(401, $client->post($url, array('json' => endpointPayload()))->getStatusCode());
    });
    endpointCheck('invalid signature is rejected', function () use ($client, $url, $auth) {
        $payload = endpointPayload();
        $payload['signature'] = str_repeat('0', 40);
        endpointSame(401, $client->post($url, array('auth' => $auth, 'json' => $payload))->getStatusCode());
    });
    endpointCheck('invalid JSON is rejected', function () use ($client, $url, $auth) {
        endpointSame(400, $client->post($url, array('auth' => $auth, 'headers' => array('Content-Type' => 'application/json'), 'body' => '{'))->getStatusCode());
    });
    endpointCheck('successful event is acknowledged after storage', function () use ($client, $url, $auth) {
        $response = $client->post($url, array('auth' => $auth, 'json' => endpointPayload()));
        endpointSame(200, $response->getStatusCode());
        endpointSame('OK', (string) $response->getBody());
    });
    $pdo = new PDO('sqlite:' . $argv[2]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    endpointCheck('normalized event is durable without storing signature', function () use ($pdo) {
        endpointSame(1, (int) $pdo->query('SELECT COUNT(*) FROM turbosms_webhook_inbox')->fetchColumn());
        $event = json_decode($pdo->query('SELECT payload FROM turbosms_webhook_inbox')->fetchColumn(), true);
        endpointSame('Delivered', $event['deliveries'][0]['status']);
        endpointSame(false, array_key_exists('signature', $event));
    });
    endpointCheck('retry is acknowledged without a second row', function () use ($client, $url, $auth, $pdo) {
        $payload = endpointPayload();
        $payload['try'] = 2;
        endpointSame(200, $client->post($url, array('auth' => $auth, 'json' => $payload))->getStatusCode());
        endpointSame(1, (int) $pdo->query('SELECT COUNT(*) FROM turbosms_webhook_inbox')->fetchColumn());
    });
    endpointCheck('Viber hybrid fallback event is accepted', function () use ($client, $url, $auth) {
        endpointSame(200, $client->post($url, array('auth' => $auth, 'json' => endpointPayload('endpoint-test-2', 'DLR_VIBER_API')))->getStatusCode());
    });
    endpointCheck('multipart form with JSON data is accepted', function () use ($client, $url, $auth) {
        $parts = array();
        foreach (endpointPayload('endpoint-test-3') as $key => $value) {
            $parts[] = array('name' => $key, 'contents' => is_array($value) ? json_encode($value) : (string) $value);
        }
        endpointSame(200, $client->post($url, array('auth' => $auth, 'multipart' => $parts))->getStatusCode());
    });
    endpointCheck('invalid batch stores no partial rows', function () use ($client, $url, $auth, $pdo) {
        $payload = endpointPayload('endpoint-test-4');
        $payload['data'] = array($payload['data'], array('status' => 'DELIVRD'));
        endpointSame(400, $client->post($url, array('auth' => $auth, 'json' => $payload))->getStatusCode());
        endpointSame(3, (int) $pdo->query('SELECT COUNT(*) FROM turbosms_webhook_inbox')->fetchColumn());
    });
    endpointCheck('oversized body is rejected', function () use ($client, $url, $auth) {
        endpointSame(413, $client->post($url, array('auth' => $auth, 'headers' => array('Content-Type' => 'application/json'), 'body' => str_repeat('x', 1048577)))->getStatusCode());
    });
    endpointCheck('storage failure is not acknowledged', function () use ($client, $url, $auth, $pdo) {
        $pdo->exec('BEGIN EXCLUSIVE');
        try {
            endpointSame(503, $client->post($url, array('auth' => $auth, 'json' => endpointPayload('endpoint-test-4')))->getStatusCode());
        } finally {
            $pdo->exec('ROLLBACK');
        }
        endpointSame(3, (int) $pdo->query('SELECT COUNT(*) FROM turbosms_webhook_inbox')->fetchColumn());
    });
    endpointCheck('same event succeeds after storage recovers', function () use ($client, $url, $auth, $pdo) {
        endpointSame(200, $client->post($url, array('auth' => $auth, 'json' => endpointPayload('endpoint-test-4')))->getStatusCode());
        endpointSame(4, (int) $pdo->query('SELECT COUNT(*) FROM turbosms_webhook_inbox')->fetchColumn());
    });
    endpointCheck('URL-encoded form is accepted', function () use ($client, $url, $auth, $pdo) {
        endpointSame(200, $client->post($url, array('auth' => $auth, 'form_params' => endpointPayload('endpoint-test-5')))->getStatusCode());
        endpointSame(5, (int) $pdo->query('SELECT COUNT(*) FROM turbosms_webhook_inbox')->fetchColumn());
    });
    echo $checks . " endpoint checks, 0 failures.\n";
} catch (Throwable $e) {
    // Do not print exception requests, headers, signatures or bodies.
    fwrite(STDERR, 'Endpoint integration test failed after ' . $checks . " checks.\n");
    exit(1);
}
