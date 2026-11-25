<?php

// Example endpoint. Configure HTTPS, environment variables and an SQLite path outside the web root.
require dirname(__DIR__) . '/vendor/autoload.php';

use edrard\Turbosms\Webhook;
use edrard\Turbosms\Exception\InvalidWebhookException;
use edrard\Turbosms\Exception\WebhookAuthenticationException;

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

try {
    // Optional extra authentication supported in the TurboSMS webhook settings.
    $user = getenv('TURBOSMS_WEBHOOK_USER');
    $password = getenv('TURBOSMS_WEBHOOK_PASSWORD');
    $hasUser = is_string($user) && $user !== '';
    $hasPassword = is_string($password) && $password !== '';
    if ($hasUser !== $hasPassword) {
        throw new RuntimeException('Incomplete endpoint configuration.');
    }
    if ($hasUser && (!hash_equals($user, $_SERVER['PHP_AUTH_USER'] ?? '') ||
        !hash_equals($password, $_SERVER['PHP_AUTH_PW'] ?? ''))) {
        header('WWW-Authenticate: Basic realm="TurboSMS webhook"');
        http_response_code(401);
        exit('Unauthorized');
    }

    $limit = 1048576;
    if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $limit) {
        http_response_code(413);
        exit('Payload too large');
    }
    $webhook = new Webhook(getenv('TURBOSMS_WEBHOOK_SECRET'), $limit);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
    if ($mediaType === 'multipart/form-data') {
        if (!empty($_FILES)) {
            throw new InvalidWebhookException('File uploads are not supported.');
        }
        $event = $webhook->parseForm($_POST);
    } else {
        $body = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($body === false) {
            throw new RuntimeException('Could not read request.');
        }
        $event = $webhook->parse($body, $contentType);
    }

    $path = getenv('TURBOSMS_WEBHOOK_DB');
    if (!is_string($path) || $path === '' ||
        preg_match('~^(?:[a-zA-Z]:[\\\\/]|/)~', $path) !== 1) {
        throw new RuntimeException('An absolute SQLite database path is required.');
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = FULL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS turbosms_webhook_inbox (
        event_id TEXT PRIMARY KEY NOT NULL, payload TEXT NOT NULL, received_at TEXT NOT NULL
    )');
    $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Could not encode event.');
    }
    // One atomic insert; a repeated event ID is already durable and is acknowledged too.
    $statement = $pdo->prepare('INSERT OR IGNORE INTO turbosms_webhook_inbox
        (event_id, payload, received_at) VALUES (:id, :payload, :received)');
    $statement->execute(array(':id' => $event['id'], ':payload' => $payload, ':received' => gmdate('c')));
    http_response_code(200);
    echo 'OK';
} catch (WebhookAuthenticationException $e) {
    http_response_code(401);
    echo 'Unauthorized';
} catch (InvalidWebhookException $e) {
    http_response_code(400);
    echo 'Invalid webhook';
} catch (Throwable $e) {
    // No success acknowledgment when parsing/configuration/storage failed. Never echo secrets.
    http_response_code(503);
    echo 'Webhook processing failed';
}
