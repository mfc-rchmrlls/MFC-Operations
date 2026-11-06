$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'local-config.ps1')

$path = Get-QbLocalConfigPath
if (Test-Path -LiteralPath $path -PathType Leaf) {
    $null = Get-QbLocalConfig
    Write-Output 'Protected QuickBooks configuration already exists for this Windows user; secrets were not changed.'
    exit 0
}

function New-QbSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
        return [Convert]::ToBase64String($bytes)
    } finally {
        $generator.Dispose()
        [Array]::Clear($bytes, 0, $bytes.Length)
    }
}

$configuration = [ordered]@{
    version = 1
    bridgeUrl = 'http://127.0.0.1:8765'
    bridgeSecret = New-QbSecret
    verificationSecret = New-QbSecret
    companyFingerprint = ''
}
Save-QbLocalConfig -Configuration $configuration
Write-Output 'Created per-user DPAPI-protected QuickBooks configuration. Secret values were not displayed.'
Write-Output 'Transaction writes are unsupported. The company fingerprint remains unset pending read-only identity verification.'
