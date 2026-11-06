<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $secret = getenv('WQ_QB_TEST_BRIDGE_SECRET') ?: '';
    $mode = getenv('WQ_QB_TEST_BRIDGE_MODE') ?: 'normal';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = array_change_key_case(is_array($headers) ? $headers : [], CASE_LOWER);
    $body = (string)file_get_contents('php://input');
    $requestId = (string)($headers['x-mfc-request-id'] ?? '');
    $timestamp = (string)($headers['x-mfc-timestamp'] ?? '');
    $decoded = json_decode($body, true);
    $operation = is_array($decoded) ? (string)($decoded['operation'] ?? '') : '';
    $expected = hash_hmac(
        'sha256',
        $timestamp . "\n" . $operation . "\n" . $requestId . "\n" . hash('sha256', $body),
        $secret
    );
    if (strlen($secret) < 32
        || !preg_match('/^\d{10}$/D', $timestamp)
        || abs(time() - (int)$timestamp) > 30
        || !preg_match('/^[a-f0-9-]{36}$/iD', $requestId)
        || !hash_equals($expected, (string)($headers['x-mfc-signature'] ?? ''))) {
        http_response_code(401);
        exit;
    }

    if ($mode === 'redirect') {
        http_response_code(302);
        header('Location: http://127.0.0.1:1/not-the-bridge');
        exit;
    }
    if ($mode === 'delay') {
        usleep(6000000);
    }
    $responseBody = $mode === 'oversized'
        ? str_repeat('x', 65537)
        : ($mode === 'malformed'
            ? '{not-json'
            : json_encode([
                'requestId' => $requestId,
                'status' => 'ok',
                'sdkRegistered' => true,
                'clrVersion' => '4.0.30319.42000',
                'dotNetFrameworkRelease' => '533509',
                'quickBooksProcessRunning' => false,
                'companySessionChecked' => false,
                'companySessionReady' => null,
                'companyFingerprint' => null,
                'transactionWritesSupported' => false,
            ], JSON_THROW_ON_ERROR));
    $responseTimestamp = (string)time();
    $responseSignature = hash_hmac(
        'sha256',
        $responseTimestamp . "\nresponse\n" . $requestId . "\n200\n" . hash('sha256', $responseBody),
        $secret
    );
    header('Content-Type: application/json');
    header('X-MFC-Timestamp: ' . $responseTimestamp);
    header('X-MFC-Signature: ' . $responseSignature);
    echo $responseBody;
    exit;
}

require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';

function http_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function run_mock_bridge(string $mode, callable $test): void
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Could not reserve an ephemeral localhost test port: ' . $errorMessage);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || !preg_match('/:(\d+)$/', $address, $matches)) {
        throw new RuntimeException('Could not determine the ephemeral test port.');
    }
    $port = (int)$matches[1];
    $secret = str_repeat('http-test-secret-', 3);
    $previousSecret = getenv('WQ_QB_TEST_BRIDGE_SECRET');
    $previousMode = getenv('WQ_QB_TEST_BRIDGE_MODE');
    putenv('WQ_QB_TEST_BRIDGE_SECRET=' . $secret);
    putenv('WQ_QB_TEST_BRIDGE_MODE=' . $mode);
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __FILE__];
    $process = proc_open(
        $command,
        [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
        $pipes,
        __DIR__,
        null
    );
    if ($previousSecret === false) {
        putenv('WQ_QB_TEST_BRIDGE_SECRET');
    } else {
        putenv('WQ_QB_TEST_BRIDGE_SECRET=' . $previousSecret);
    }
    if ($previousMode === false) {
        putenv('WQ_QB_TEST_BRIDGE_MODE');
    } else {
        putenv('WQ_QB_TEST_BRIDGE_MODE=' . $previousMode);
    }
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the localhost test bridge.');
    }
    try {
        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
            if ($probe !== false) {
                fclose($probe);
                $ready = true;
                break;
            }
            usleep(50000);
        }
        if (!$ready) {
            throw new RuntimeException('The localhost test bridge did not start.');
        }
        $test(new QuickBooksBridgeClient('http://127.0.0.1:' . $port, $secret));
    } finally {
        proc_terminate($process);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50000);
        }
        proc_close($process);
    }
}

if (!function_exists('curl_init') || !function_exists('proc_open')) {
    throw new RuntimeException('This HTTP integration test requires PHP cURL and proc_open.');
}

try {
    run_mock_bridge('normal', static function (QuickBooksBridgeClient $client): void {
        $response = $client->health();
        http_test_assert($response['available'] && $response['response']['status'] === 'ok', 'real cURL request and signed response succeed against localhost');
    });
    run_mock_bridge('malformed', static function (QuickBooksBridgeClient $client): void {
        $response = $client->health();
        http_test_assert($response['diagnostic_code'] === 'BRIDGE_INVALID_RESPONSE', 'real cURL path rejects malformed signed JSON');
    });
    run_mock_bridge('oversized', static function (QuickBooksBridgeClient $client): void {
        $response = $client->health();
        http_test_assert($response['diagnostic_code'] === 'BRIDGE_RESPONSE_TOO_LARGE', 'real cURL write callback stops oversized responses');
    });
    run_mock_bridge('redirect', static function (QuickBooksBridgeClient $client): void {
        $response = $client->health();
        http_test_assert($response['diagnostic_code'] === 'BRIDGE_RESPONSE_AUTH_FAILED', 'real cURL path rejects unsigned redirects without following them');
    });
    run_mock_bridge('delay', static function (QuickBooksBridgeClient $client): void {
        $response = $client->health();
        http_test_assert(!$response['available'] && $response['diagnostic_code'] === 'BRIDGE_UNAVAILABLE', 'real cURL request times out within the configured five-second limit');
    });
    echo "All localhost bridge HTTP checks passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
