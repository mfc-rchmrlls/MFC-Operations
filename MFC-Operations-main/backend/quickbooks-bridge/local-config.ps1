$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Security

function Get-QbLocalConfigPath {
    return Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge\local-config.bin'
}

function Set-QbLocalConfigAcl {
    param([Parameter(Mandatory = $true)][string]$Path, [switch]$Directory)

    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $grant = '*' + $identity.User.Value + ':F'
    if ($Directory) {
        $grant = '*' + $identity.User.Value + ':(OI)(CI)F'
    }
    $icacls = Join-Path $env:SystemRoot 'System32\icacls.exe'
    if (-not (Test-Path -LiteralPath $icacls -PathType Leaf)) {
        throw 'Windows ACL utility is unavailable; protected configuration was not saved.'
    }
    & $icacls $Path '/inheritance:r' '/grant:r' $grant | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not restrict the protected configuration ACL to the current Windows user.'
    }
}

function Get-QbLocalConfig {
    $path = Get-QbLocalConfigPath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw 'Protected QuickBooks local configuration is missing. Run configure-local.ps1 as the interactive Windows user.'
    }

    try {
        $entropy = [Text.Encoding]::UTF8.GetBytes('MFC-Operations-QuickBooks-LocalConfig-v1')
        $encrypted = [IO.File]::ReadAllBytes($path)
        $plain = [Security.Cryptography.ProtectedData]::Unprotect(
            $encrypted,
            $entropy,
            [Security.Cryptography.DataProtectionScope]::CurrentUser
        )
        try {
            $json = [Text.Encoding]::UTF8.GetString($plain)
            $config = ConvertFrom-Json -InputObject $json
        } finally {
            [Array]::Clear($plain, 0, $plain.Length)
        }
    } catch {
        throw 'Protected QuickBooks configuration could not be decrypted for this Windows user.'
    }

    $hasInvalidFingerprint = -not [string]::IsNullOrEmpty([string]$config.companyFingerprint) `
        -and [string]$config.companyFingerprint -cnotmatch '^[a-f0-9]{64}$'
    $isInvalidConfiguration = $config.version -ne 1 `
        -or [string]::IsNullOrWhiteSpace([string]$config.bridgeUrl) `
        -or [string]::IsNullOrWhiteSpace([string]$config.bridgeSecret) `
        -or [string]::IsNullOrWhiteSpace([string]$config.verificationSecret) `
        -or [Text.Encoding]::UTF8.GetByteCount([string]$config.bridgeSecret) -lt 32 `
        -or [Text.Encoding]::UTF8.GetByteCount([string]$config.verificationSecret) -lt 32 `
        -or [string]$config.bridgeUrl -cne 'http://127.0.0.1:8765' `
        -or $hasInvalidFingerprint
    if ($isInvalidConfiguration) {
        throw 'Protected QuickBooks configuration is invalid or unsafe.'
    }

    return $config
}

function Save-QbLocalConfig {
    param([Parameter(Mandatory = $true)]$Configuration)

    $directory = Split-Path -Parent (Get-QbLocalConfigPath)
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
    Set-QbLocalConfigAcl -Path $directory -Directory

    $Configuration.version = 1
    $Configuration.bridgeUrl = 'http://127.0.0.1:8765'
    if ($Configuration -is [System.Collections.IDictionary]) {
        $Configuration.Remove('invoiceWriteEnabled')
    } elseif ($Configuration.PSObject.Properties['invoiceWriteEnabled']) {
        $Configuration.PSObject.Properties.Remove('invoiceWriteEnabled')
    }
    $json = ConvertTo-Json -InputObject $Configuration -Compress
    $plain = [Text.Encoding]::UTF8.GetBytes($json)
    $entropy = [Text.Encoding]::UTF8.GetBytes('MFC-Operations-QuickBooks-LocalConfig-v1')
    $encrypted = [Security.Cryptography.ProtectedData]::Protect(
        $plain,
        $entropy,
        [Security.Cryptography.DataProtectionScope]::CurrentUser
    )
    [Array]::Clear($plain, 0, $plain.Length)

    $path = Get-QbLocalConfigPath
    $temporaryPath = $path + '.tmp'
    try {
        [IO.File]::WriteAllBytes($temporaryPath, $encrypted)
        Set-QbLocalConfigAcl -Path $temporaryPath
        Move-Item -LiteralPath $temporaryPath -Destination $path -Force
        Set-QbLocalConfigAcl -Path $path
    } finally {
        if (Test-Path -LiteralPath $temporaryPath -PathType Leaf) {
            Remove-Item -LiteralPath $temporaryPath -Force
        }
        [Array]::Clear($encrypted, 0, $encrypted.Length)
    }
}

function Set-QbProcessEnvironment {
    param(
        [Parameter(Mandatory = $true)]$Configuration,
        [switch]$RequireFingerprint
    )

    $fingerprint = [string]$Configuration.companyFingerprint
    if ($RequireFingerprint -and $fingerprint -notmatch '^[a-f0-9]{64}$') {
        throw 'The verified QuickBooks company fingerprint is missing. Run verify-company.ps1 after confirming the intended company is open.'
    }

    $env:WQ_QB_BRIDGE_URL = [string]$Configuration.bridgeUrl
    $env:WQ_QB_BRIDGE_SECRET = [string]$Configuration.bridgeSecret
    $env:WQ_QB_VERIFICATION_SECRET = [string]$Configuration.verificationSecret
    Remove-Item Env:\WQ_QB_INVOICE_WRITE_ENABLED -ErrorAction SilentlyContinue
    if ($fingerprint -match '^[a-f0-9]{64}$') {
        $env:WQ_QB_COMPANY_FINGERPRINT = $fingerprint
    } else {
        Remove-Item Env:\WQ_QB_COMPANY_FINGERPRINT -ErrorAction SilentlyContinue
    }
}
