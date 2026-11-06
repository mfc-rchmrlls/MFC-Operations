$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'local-config.ps1')
if (-not [Environment]::UserInteractive) {
    throw 'Start the bridge from the authorized interactive Windows user session; service/session-0 startup is unsupported.'
}
$configuration = Get-QbLocalConfig
Set-QbProcessEnvironment -Configuration $configuration -RequireFingerprint

$executable = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge\QuickBooksReadOnlyBridge.exe'
if (-not (Test-Path $executable)) {
    throw 'The read-only bridge is not installed. build.ps1 creates a separate staging executable and does not install it.'
}
$listener = @(Get-NetTCPConnection -State Listen -LocalPort 8765 -ErrorAction SilentlyContinue)
if ($listener.Count -gt 0) {
    $owners = @($listener | Select-Object -ExpandProperty OwningProcess -Unique)
    throw ('Port 8765 already has a listener (PID ' + ($owners -join ', ') + '). Verify authenticated health; no process was stopped.')
}

$quickBooks = @(Get-Process -Name QBW, QBW32 -ErrorAction SilentlyContinue)
if ($quickBooks.Count -eq 0) {
    Write-Warning 'QuickBooks Desktop is not running. The bridge can start for health checks, but company diagnostics will not succeed until the intended company is opened manually.'
}

Write-Output 'Using the current interactive Windows user and DPAPI-protected local configuration; invoice writes are disabled.'
& $executable
