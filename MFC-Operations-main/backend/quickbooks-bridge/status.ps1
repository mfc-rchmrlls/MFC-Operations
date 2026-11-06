$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'local-config.ps1')

$configuration = $null
$configurationAvailable = $false
try {
    $configuration = Get-QbLocalConfig
    $configurationAvailable = $true
} catch {
    Write-Output 'Protected local configuration: missing or invalid'
}

$executable = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge\QuickBooksReadOnlyBridge.exe'
$installed = Test-Path -LiteralPath $executable -PathType Leaf
$listeners = @(Get-NetTCPConnection -State Listen -LocalPort 8765 -ErrorAction SilentlyContinue)
$quickBooks = @(Get-Process -Name QBW, QBW32 -ErrorAction SilentlyContinue)

Write-Output ('Bridge executable installed: ' + $(if ($installed) { 'yes' } else { 'no' }))
Write-Output ('Bridge listener running: ' + $(if ($listeners.Count -gt 0) { 'yes' } else { 'no' }))
if ($listeners.Count -gt 0) {
    $owners = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
    Write-Output ('Listener process ID(s): ' + ($owners -join ', '))
}
Write-Output ('PHP bridge URL configured for the XAMPP launcher: ' + $(if ($configurationAvailable) { 'yes (loopback only; runtime not tested)' } else { 'no' }))
Write-Output ('Shared bridge secret in protected settings: ' + $(if ($configurationAvailable) { 'yes; runtime match requires signed health' } else { 'no' }))
Write-Output ('PHP reference-verification secret in protected settings: ' + $(if ($configurationAvailable) { 'yes; PHP runtime not tested' } else { 'no' }))
Write-Output ('QuickBooks Desktop process running: ' + $(if ($quickBooks.Count -gt 0) { 'yes' } else { 'no' }))
Write-Output 'QuickBooks SDK authorization successful: not tested; run verify-company.ps1 explicitly.'
Write-Output 'Company identity verified: not tested; run verify-company.ps1 explicitly.'
Write-Output ('Expected company fingerprint configured: ' + $(if ($configurationAvailable -and [string]$configuration.companyFingerprint -match '^[a-f0-9]{64}$') { 'yes' } else { 'no' }))
Write-Output 'Company fingerprint matched: not tested; run verify-company.ps1 explicitly.'
Write-Output 'Invoice posting configuration ready: no; writes are forced disabled and live preflight is not established.'
