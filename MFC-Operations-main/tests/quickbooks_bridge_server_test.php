<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/core/quickbooks_exports.php';
require_once __DIR__ . '/../backend/core/quickbooks_bridge_client.php';

function server_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

function server_test_request(string $url, string $body, string $requestId, string $operation, string $secret, ?string $timestamp = null, ?string $signature = null, string $contentType = 'application/json'): array
{
    $timestamp = $timestamp ?? (string)time();
    $signature = $signature ?? hash_hmac(
        'sha256',
        $timestamp . "\n" . $operation . "\n" . $requestId . "\n" . hash('sha256', $body),
        $secret
    );
    $headers = [];
    $response = '';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $contentType,
            'X-MFC-Request-Id: ' . $requestId,
            'X-MFC-Timestamp: ' . $timestamp,
            'X-MFC-Signature: ' . $signature,
        ],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            if (preg_match('/^([^:]+):\s*(.*?)\s*$/', $line, $matches)) {
                $headers[strtolower($matches[1])] = $matches[2];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 65536) {
                return 0;
            }
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    $result = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle);
    curl_close($handle);
    if ($result === false || $error !== 0) {
        throw new RuntimeException('The bridge HTTP test request failed.');
    }
    return ['status' => $status, 'body' => $response, 'headers' => $headers];
}

function server_test_verify_response(array $response, string $requestId, string $secret): bool
{
    $timestamp = $response['headers']['x-mfc-timestamp'] ?? '';
    $signature = $response['headers']['x-mfc-signature'] ?? '';
    if (!preg_match('/^\d{10}$/D', $timestamp) || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
        return false;
    }
    $expected = hash_hmac(
        'sha256',
        $timestamp . "\nresponse\n" . $requestId . "\n" . $response['status'] . "\n" . hash('sha256', $response['body']),
        $secret
    );
    return hash_equals($expected, $signature);
}

function server_test_available_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errorMessage);
    if ($socket === false) {
        throw new RuntimeException('Could not allocate an isolated loopback test port.');
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address) || !preg_match('/:(\d+)$/D', $address, $matches)) {
        throw new RuntimeException('Could not read the isolated loopback test port.');
    }
    $port = (int)$matches[1];
    if ($port < 1024 || $port > 65535 || $port === 8765) {
        throw new RuntimeException('The isolated loopback test port is invalid or reserved for the live bridge.');
    }
    return $port;
}

function server_test_normalize_path(string $path): string
{
    return strtolower(rtrim(str_replace('/', '\\', $path), '\\'));
}

function server_test_path_is_within(string $path, string $directory): bool
{
    $normalizedPath = server_test_normalize_path($path);
    $normalizedDirectory = server_test_normalize_path($directory) . '\\';
    return str_starts_with($normalizedPath . '\\', $normalizedDirectory);
}

if (!function_exists('curl_init') || !function_exists('proc_open')) {
    throw new RuntimeException('This test requires PHP cURL and proc_open.');
}

$preserveTestBuild = false;
if (count($argv) > 1) {
    if (count($argv) !== 2 || $argv[1] !== '--preserve-test-build') {
        throw new RuntimeException('The only supported option is --preserve-test-build.');
    }
    $preserveTestBuild = true;
}

$tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mfc-qb-bridge-' . bin2hex(random_bytes(8));
if (!mkdir($tempDirectory, 0700) && !is_dir($tempDirectory)) {
    throw new RuntimeException('Could not create the isolated bridge test directory.');
}
$tempDirectory = realpath($tempDirectory);
$temporaryRoot = realpath(sys_get_temp_dir());
if (!is_string($tempDirectory)
    || !is_string($temporaryRoot)
    || server_test_normalize_path(dirname($tempDirectory)) !== server_test_normalize_path($temporaryRoot)
    || !preg_match('/^mfc-qb-bridge-[a-f0-9]{16}$/iD', basename($tempDirectory))) {
    throw new RuntimeException('The bridge test directory is not a unique direct child of the system temporary directory.');
}
$localAppData = getenv('LOCALAPPDATA');
if (!is_string($localAppData) || $localAppData === '') {
    throw new RuntimeException('Cannot confirm the installed replay-cache location; no bridge process was started.');
}
$productionBridgeDirectory = rtrim($localAppData, '\\/') . DIRECTORY_SEPARATOR
    . 'MFC-Operations' . DIRECTORY_SEPARATOR . 'QuickBooksBridge';
$productionReplayCache = $productionBridgeDirectory . DIRECTORY_SEPARATOR . 'replay-cache.txt';
$testReplayCache = $tempDirectory . DIRECTORY_SEPARATOR . 'replay-cache.txt';
if (server_test_path_is_within($testReplayCache, $productionBridgeDirectory)
    || server_test_path_is_within($productionReplayCache, $tempDirectory)
    || file_exists($testReplayCache)) {
    throw new RuntimeException('The test replay-cache path overlaps production state or is not new.');
}
$source = realpath(__DIR__ . '/../backend/quickbooks-bridge/Program.cs');
if (!is_string($source) || is_link($tempDirectory)) {
    throw new RuntimeException('The bridge source file is unavailable.');
}
$buildScript = realpath(__DIR__ . '/../backend/quickbooks-bridge/build.ps1');
if (!is_string($buildScript)) {
    throw new RuntimeException('The bridge build script is unavailable.');
}
$executable = $tempDirectory . DIRECTORY_SEPARATOR . 'QuickBooksReadOnlyBridge.exe';
$normalizedExecutable = server_test_normalize_path($executable);
if (server_test_path_is_within($normalizedExecutable, $productionBridgeDirectory)
    || server_test_path_is_within($productionReplayCache, $tempDirectory)
    || server_test_path_is_within($productionBridgeDirectory, $tempDirectory)) {
    throw new RuntimeException('The test executable or cache path overlaps installed production state.');
}
$powershell = getenv('SystemRoot') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
if (!is_file($powershell)) {
    throw new RuntimeException('Windows PowerShell is unavailable for the explicit test build.');
}
$build = proc_open(
    [$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', $buildScript, '-TestBuild', '-OutputDirectory', $tempDirectory],
    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $buildPipes,
    __DIR__,
    null
);
if (!is_resource($build)) {
    throw new RuntimeException('Could not start the explicit test-only bridge build.');
}
$buildOutput = stream_get_contents($buildPipes[1]);
$buildError = stream_get_contents($buildPipes[2]);
foreach ($buildPipes as $pipe) {
    fclose($pipe);
}
$buildStatus = proc_close($build);
$testBuildMarker = 'MFC-QB-BRIDGE-TEST-BUILD';
$testBuildMarkerUtf16 = implode("\0", str_split($testBuildMarker));
$testBuildContents = is_file($executable) ? (string)file_get_contents($executable) : '';
if ($buildStatus !== 0
    || !is_file($executable)
    || !str_contains((string)$buildOutput, 'Configuration: TEST ONLY')
    || (!str_contains($testBuildContents, $testBuildMarker)
        && !str_contains($testBuildContents, $testBuildMarkerUtf16))) {
    throw new RuntimeException('Test-only bridge build failed or did not contain the test-build marker. ' . trim((string)$buildError));
}
$testPort = server_test_available_port();
$bridgeUrl = 'http://127.0.0.1:' . $testPort;

$pathCheck = proc_open(
    [$executable, '--test-resolve-replay-cache-path', $testReplayCache],
    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pathCheckPipes,
    __DIR__,
    null
);
if (!is_resource($pathCheck)) {
    throw new RuntimeException('Could not start the replay-cache path isolation check.');
}
$pathCheckOutput = stream_get_contents($pathCheckPipes[1]);
$pathCheckError = stream_get_contents($pathCheckPipes[2]);
foreach ($pathCheckPipes as $pipe) {
    fclose($pipe);
}
$pathCheckStatus = proc_close($pathCheck);
$expectedPathLine = 'Test replay cache path: ' . $testReplayCache;
if ($pathCheckStatus !== 0
    || !is_string($pathCheckOutput)
    || server_test_normalize_path(trim($pathCheckOutput)) !== server_test_normalize_path($expectedPathLine)
    || file_exists($testReplayCache)) {
    throw new RuntimeException('Child-process replay-cache isolation check failed; server test was not started. ' . trim((string)$pathCheckError));
}
server_test_assert(
    true,
    'test process replay-cache path is isolated at ' . $testReplayCache
);
server_test_assert(
    true,
    'child-process resolver confirmed ' . trim($pathCheckOutput) . ' without opening a cache or starting a listener'
);

$selfTest = proc_open(
    [$executable, '--self-test'],
    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $selfTestPipes,
    __DIR__,
    null
);
if (!is_resource($selfTest)) {
    throw new RuntimeException('Could not start the isolated test-build self-tests.');
}
$selfTestOutput = stream_get_contents($selfTestPipes[1]);
$selfTestError = stream_get_contents($selfTestPipes[2]);
foreach ($selfTestPipes as $pipe) {
    fclose($pipe);
}
$selfTestStatus = proc_close($selfTest);
if ($selfTestStatus !== 0 || !str_contains((string)$selfTestOutput, 'All bridge self-tests passed.')) {
    throw new RuntimeException('Isolated test-build self-tests failed. ' . trim((string)$selfTestError));
}
server_test_assert(true, 'test-only executable completed offline self-tests');

$productionMode = proc_open(
    [$executable],
    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $productionModePipes,
    __DIR__,
    null
);
if (!is_resource($productionMode)) {
    throw new RuntimeException('Could not verify that the test build refuses production startup.');
}
$productionModeOutput = stream_get_contents($productionModePipes[1]) . stream_get_contents($productionModePipes[2]);
foreach ($productionModePipes as $pipe) {
    fclose($pipe);
}
$productionModeStatus = proc_close($productionMode);
if ($productionModeStatus === 0 || !str_contains($productionModeOutput, 'isolated test listener')) {
    throw new RuntimeException('Test executable unexpectedly accepted production-style startup.');
}
server_test_assert(true, 'test executable refuses production-style startup without opening its replay cache');

$secret = 'phase3c-bridge-http-test-secret-0123456789';
$previousSecret = getenv('WQ_QB_BRIDGE_SECRET');
$previousWriteEnabled = getenv('WQ_QB_INVOICE_WRITE_ENABLED');
putenv('WQ_QB_BRIDGE_SECRET=' . $secret);
putenv('WQ_QB_INVOICE_WRITE_ENABLED=true');
$process = proc_open(
    [$executable, '--test-listen-port', (string)$testPort, '--test-replay-cache', $testReplayCache],
    [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    __DIR__,
    null
);
if ($previousSecret === false) {
    putenv('WQ_QB_BRIDGE_SECRET');
} else {
    putenv('WQ_QB_BRIDGE_SECRET=' . $previousSecret);
}
if ($previousWriteEnabled === false) {
    putenv('WQ_QB_INVOICE_WRITE_ENABLED');
} else {
    putenv('WQ_QB_INVOICE_WRITE_ENABLED=' . $previousWriteEnabled);
}
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the compiled bridge.');
}

try {
    server_test_assert($testPort !== 8765, 'compiled bridge server test uses an isolated dynamic port');
    stream_set_timeout($pipes[1], 5);
    $cachePathLine = fgets($pipes[1]);
    $reportedCachePath = is_string($cachePathLine)
        ? trim(str_replace('Test replay cache path: ', '', $cachePathLine))
        : '';
    if ($reportedCachePath === ''
        || server_test_normalize_path($reportedCachePath) !== server_test_normalize_path($testReplayCache)
        || !server_test_path_is_within($reportedCachePath, $tempDirectory)
        || server_test_path_is_within($reportedCachePath, $productionBridgeDirectory)
        || server_test_path_is_within($productionReplayCache, $tempDirectory)) {
        throw new RuntimeException('The child bridge did not confirm an isolated replay-cache path; no HTTP request was sent.');
    }
    server_test_assert(
        true,
        'PHP test replay cache remains isolated at ' . $testReplayCache
    );
    server_test_assert(
        true,
        'child bridge resolved its replay cache to ' . $reportedCachePath . ' before any HTTP request'
    );
    $ready = false;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $testPort, $errno, $errorMessage, 0.1);
        if ($probe !== false) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException('The compiled bridge did not bind to the isolated test port.');
    }

    $client = new QuickBooksBridgeClient($bridgeUrl, $secret);
    $health = $client->health();
    server_test_assert($health['available']
        && $health['response']['sdkRegistered'] === true
        && $health['response']['transactionWritesSupported'] === false,
        'PHP client validates authenticated bridge health and confirms transaction writes are unsupported');

    $requestId = qb_export_uuid();
    $healthBody = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $valid = server_test_request($bridgeUrl . '/health', $healthBody, $requestId, 'health', $secret);
    server_test_assert($valid['status'] === 200 && server_test_verify_response($valid, $requestId, $secret), 'bridge success response is authenticated over exact response bytes');
    $replay = server_test_request($bridgeUrl . '/health', $healthBody, $requestId, 'health', $secret);
    server_test_assert($replay['status'] === 409 && server_test_verify_response($replay, $requestId, $secret), 'bridge rejects a replayed nonce and signs the rejection');

    $requestId = qb_export_uuid();
    $diagnosticBody = json_encode([
        'operation' => 'diagnose',
        'requestId' => $requestId,
        'entity' => 'company_preferences',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $diagnostic = server_test_request(
        $bridgeUrl . '/diagnose',
        $diagnosticBody,
        $requestId,
        'diagnose',
        $secret
    );
    $diagnosticData = json_decode($diagnostic['body'], true, 16, JSON_THROW_ON_ERROR);
    server_test_assert(
        $diagnostic['status'] === 400
            && ($diagnosticData['diagnosticCode'] ?? null) === 'TEST_DIAGNOSTICS_DISABLED',
        'test build rejects diagnostic operations without accessing QuickBooks'
    );

    $requestId = qb_export_uuid();
    $body = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $invalidSignature = server_test_request(
        $bridgeUrl . '/health',
        $body,
        $requestId,
        'health',
        $secret,
        null,
        str_repeat('0', 64)
    );
    server_test_assert($invalidSignature['status'] === 401 && server_test_verify_response($invalidSignature, $requestId, $secret), 'bridge rejects invalid request HMAC and authenticates the error response');

    $requestId = qb_export_uuid();
    $body = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stale = server_test_request(
        $bridgeUrl . '/health',
        $body,
        $requestId,
        'health',
        $secret,
        (string)(time() - 120)
    );
    server_test_assert($stale['status'] === 401, 'bridge rejects stale signed timestamps');

    $requestId = qb_export_uuid();
    $extraFieldBody = json_encode([
        'operation' => 'health',
        'requestId' => $requestId,
        'unexpected' => 'not-allowed',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $extraField = server_test_request(
        $bridgeUrl . '/health',
        $extraFieldBody,
        $requestId,
        'health',
        $secret
    );
    server_test_assert($extraField['status'] === 400, 'bridge rejects unexpected JSON fields');

    $requestId = qb_export_uuid();
    $unknownBody = json_encode([
        'operation' => 'InvoiceAdd',
        'requestId' => $requestId,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $unknown = server_test_request(
        $bridgeUrl . '/InvoiceAdd',
        $unknownBody,
        $requestId,
        'InvoiceAdd',
        $secret
    );
    server_test_assert($unknown['status'] === 400, 'transaction-creation operation is rejected before SDK access');

    foreach (['submit', 'InvoiceAdd', 'SalesOrderAdd'] as $writeOperation) {
        $requestId = qb_export_uuid();
        $writeBody = json_encode([
            'operation' => $writeOperation,
            'requestId' => $requestId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $writeResponse = server_test_request(
            $bridgeUrl . '/' . $writeOperation,
            $writeBody,
            $requestId,
            $writeOperation,
            $secret
        );
        $writeData = json_decode($writeResponse['body'], true, 16, JSON_THROW_ON_ERROR);
        server_test_assert(
            $writeResponse['status'] === 400
                && server_test_verify_response($writeResponse, $requestId, $secret)
                && ($writeData['diagnosticCode'] ?? null) === 'OPERATION_REJECTED',
            $writeOperation . ' remains unsupported even when the legacy write environment variable is true'
        );
    }

    $requestId = qb_export_uuid();
    $body = json_encode(['operation' => 'health', 'requestId' => $requestId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $wrongContentType = server_test_request(
        $bridgeUrl . '/health',
        $body,
        $requestId,
        'health',
        $secret,
        null,
        null,
        'text/plain'
    );
    server_test_assert($wrongContentType['status'] === 400, 'bridge rejects unexpected content types');

    $oversizedBody = str_repeat('x', 524289);
    $requestId = qb_export_uuid();
    $oversized = server_test_request(
        $bridgeUrl . '/health',
        $oversizedBody,
        $requestId,
        'health',
        $secret
    );
    server_test_assert($oversized['status'] === 413, 'bridge rejects oversized requests before parsing');

    echo "All compiled C# bridge HTTP checks passed.\n";
} finally {
    proc_terminate($process);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(50000);
    }
    foreach ([1, 2] as $pipeIndex) {
        if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
            fclose($pipes[$pipeIndex]);
        }
    }
    proc_close($process);
    foreach (glob($tempDirectory . DIRECTORY_SEPARATOR . 'replay-cache.txt*') ?: [] as $replayFile) {
        if (is_file($replayFile)
            && server_test_normalize_path(dirname(realpath($replayFile) ?: $replayFile))
                === server_test_normalize_path($tempDirectory)
            && preg_match('/^replay-cache\.txt(?:\.[a-f0-9]{32}\.tmp)?$/iD', basename($replayFile))) {
            unlink($replayFile);
        }
    }
    if (!$preserveTestBuild
        && is_file($executable)
        && server_test_normalize_path(dirname(realpath($executable) ?: $executable))
            === server_test_normalize_path($tempDirectory)) {
        unlink($executable);
    }
    if (!$preserveTestBuild && is_dir($tempDirectory)
        && server_test_normalize_path(realpath($tempDirectory) ?: '') === server_test_normalize_path($tempDirectory)
        && server_test_normalize_path(dirname($tempDirectory)) === server_test_normalize_path($temporaryRoot)
        && preg_match('/^mfc-qb-bridge-[a-f0-9]{16}$/iD', basename($tempDirectory))) {
        rmdir($tempDirectory);
    }
}
