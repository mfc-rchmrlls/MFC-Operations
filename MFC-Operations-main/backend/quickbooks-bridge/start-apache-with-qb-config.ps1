$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'local-config.ps1')
$configuration = Get-QbLocalConfig
Set-QbProcessEnvironment -Configuration $configuration -RequireFingerprint

$xamppRoot = 'C:\xampp'
$controlPanel = Join-Path $xamppRoot 'xampp-control.exe'
$httpd = Join-Path $xamppRoot 'apache\bin\httpd.exe'
if (-not (Test-Path -LiteralPath $controlPanel -PathType Leaf) -or -not (Test-Path -LiteralPath $httpd -PathType Leaf)) {
    throw 'The expected XAMPP Control Panel or Apache executable was not found under C:\xampp.'
}

$runningControlPanel = @(Get-Process -Name xampp-control -ErrorAction SilentlyContinue)
$runningApache = @(Get-Process -Name httpd -ErrorAction SilentlyContinue)
if ($runningControlPanel.Count -gt 0 -or $runningApache.Count -gt 0) {
    throw 'XAMPP Control Panel or Apache is already running. Stop Apache normally and close the Control Panel, then run this launcher; no process was stopped.'
}

$apacheServices = @(Get-CimInstance Win32_Service -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -match 'Apache' -or $_.PathName -match 'xampp.*httpd'
})
if ($apacheServices.Count -gt 0) {
    throw 'An Apache Windows service is registered. This per-user DPAPI configuration requires interactive Control Panel startup, not service startup; no service was changed.'
}

$null = Start-Process -FilePath $controlPanel -WorkingDirectory $xamppRoot
Write-Output 'Started XAMPP Control Panel under the current interactive user with protected QuickBooks settings inherited.'
Write-Output 'Start Apache from the Control Panel. PHP will receive the same bridge URL, secrets, fingerprint, and disabled write flag.'
Write-Output 'Secret values were not displayed or written to Apache configuration.'
