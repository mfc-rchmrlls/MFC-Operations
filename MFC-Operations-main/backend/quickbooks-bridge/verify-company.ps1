param([switch]$SaveFingerprint)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'local-config.ps1')
if (-not [Environment]::UserInteractive) {
    throw 'Run the read-only company check from the authorized interactive Windows user session.'
}
$configuration = Get-QbLocalConfig
Set-QbProcessEnvironment -Configuration $configuration

$confirmation = Read-Host 'After visually confirming the QuickBooks title bar shows Testing QB.qbw, type exactly Testing QB.qbw'
if ($confirmation -cne 'Testing QB.qbw') {
    throw 'Company identity check cancelled; no SDK request was made.'
}

$quickBooks = @(Get-Process -Name QBW, QBW32 -ErrorAction SilentlyContinue)
if ($quickBooks.Count -eq 0) {
    throw 'QuickBooks Desktop is not running. Open Testing QB.qbw manually, then run this read-only check again.'
}

$executable = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge\QuickBooksReadOnlyBridge.exe'
if (-not (Test-Path -LiteralPath $executable -PathType Leaf)) {
    throw 'The bridge executable is not installed. build.ps1 creates a separate staging executable and does not install it.'
}

$output = @(& $executable --identify-current-company 2>&1)
$exitCode = $LASTEXITCODE
if ($exitCode -ne 0) {
    $message = ($output | ForEach-Object { [string]$_ }) -join ' '
    if ($message -match 'SDK_COM_80040418') {
        Write-Error 'QuickBooks SDK authorization is required. The built-in QuickBooks Admin must authorize this integration in QuickBooks first; do not bypass the prompt.'
    }
    if ($message -match 'SDK_COM_[0-9A-Fa-f]{8}') {
        Write-Error $message
    }
    throw 'The read-only QuickBooks company identity check failed. Review the sanitized SDK diagnostic and resolve the normal QuickBooks authorization/session issue.'
}

try {
    $result = (($output | ForEach-Object { [string]$_ }) -join "`n") | ConvertFrom-Json
} catch {
    throw 'The bridge returned an invalid read-only company identity response.'
}
$invalidIdentity = $result.status -cne 'candidate' `
    -or [string]$result.companyFingerprint -notmatch '^[a-f0-9]{64}$' `
    -or [string]::IsNullOrWhiteSpace([string]$result.companyIdentity.companyName) `
    -or [string]::IsNullOrWhiteSpace([string]$result.companyIdentity.legalCompanyName)
if ($invalidIdentity) {
    throw 'The bridge did not return a complete company identity candidate; no fingerprint was saved.'
}

$fingerprint = ([string]$result.companyFingerprint).ToLowerInvariant()
$matchesConfigured = [string]$configuration.companyFingerprint -ceq $fingerprint
Write-Output 'Read-only QuickBooks SDK identity result:'
Write-Output 'QuickBooks SDK authorization successful: yes (read-only CompanyQuery succeeded)'
Write-Output ('SDK company name: ' + [string]$result.companyIdentity.companyName)
Write-Output ('SDK legal company name: ' + [string]$result.companyIdentity.legalCompanyName)
Write-Output ('Company fingerprint matches protected configuration: ' + $(if ($matchesConfigured) { 'yes' } else { 'no' }))
Write-Output ('Candidate fingerprint: ' + $fingerprint)
Write-Output 'The SDK identity response does not return the company filename or path. The file title must be confirmed visually in QuickBooks.'

if ($SaveFingerprint) {
    $saveConfirmation = Read-Host 'After reviewing the SDK company names and confirming the open file is Testing QB.qbw, type exactly SAVE TESTING QB FINGERPRINT'
    if ($saveConfirmation -cne 'SAVE TESTING QB FINGERPRINT') {
        throw 'Fingerprint was not saved; protected configuration was not changed.'
    }
    $configuration.companyFingerprint = $fingerprint
    Save-QbLocalConfig -Configuration $configuration
    Write-Output 'The confirmed candidate fingerprint was saved to the current user DPAPI-protected configuration.'
}
