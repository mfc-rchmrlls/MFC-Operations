param(
    [switch]$TestBuild,
    [string]$OutputDirectory
)

$ErrorActionPreference = 'Stop'
$compiler = 'C:\Windows\Microsoft.NET\Framework64\v4.0.30319\csc.exe'
$source = Join-Path $PSScriptRoot 'Program.cs'
$installedDirectory = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge'
$buildRoot = Join-Path $env:LOCALAPPDATA 'MFC-Operations\QuickBooksBridge-Builds'

function Get-NormalizedDirectory([string]$Path) {
    return [IO.Path]::GetFullPath($Path).TrimEnd('\') + '\'
}

function Test-PathOverlap([string]$Left, [string]$Right) {
    $leftPath = Get-NormalizedDirectory $Left
    $rightPath = Get-NormalizedDirectory $Right
    return $leftPath.StartsWith($rightPath, [StringComparison]::OrdinalIgnoreCase) `
        -or $rightPath.StartsWith($leftPath, [StringComparison]::OrdinalIgnoreCase)
}

function Test-ReparsePointInPath([string]$Path) {
    $current = New-Object -TypeName System.IO.DirectoryInfo -ArgumentList ([IO.Path]::GetFullPath($Path))
    while ($null -ne $current) {
        if ($current.Exists -and ($current.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            return $true
        }
        $current = $current.Parent
    }
    return $false
}

function Test-SystemTempDirectory([string]$Path) {
    $normalizedPath = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    foreach ($candidate in @([IO.Path]::GetTempPath(), $env:TEMP, $env:TMP)) {
        if ([String]::IsNullOrWhiteSpace($candidate)) {
            continue
        }
        $normalizedCandidate = [IO.Path]::GetFullPath($candidate).TrimEnd('\')
        if ([String]::Equals($normalizedPath, $normalizedCandidate, [StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }
    return $false
}

function Set-TestBuildDirectoryAcl([string]$Path) {
    $userSid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $acl.SetOwner($userSid)
    foreach ($sid in @($userSid, $systemSid)) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        $acl.AddAccessRule($rule)
    }
    [IO.Directory]::SetAccessControl($Path, $acl)

    $verified = [IO.Directory]::GetAccessControl(
        $Path,
        [Security.AccessControl.AccessControlSections]::Owner -bor [Security.AccessControl.AccessControlSections]::Access
    )
    $owner = $verified.GetOwner([Security.Principal.SecurityIdentifier])
    if (-not $owner.Equals($userSid) -or -not $verified.AreAccessRulesProtected) {
        throw 'Test build directory owner or protected ACL could not be verified.'
    }
    $rules = @($verified.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    if ($rules.Count -ne 2) {
        throw 'Test build directory ACL contains unexpected access rules.'
    }
    $ruleSids = @()
    foreach ($rule in $rules) {
        if ($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow `
            -or ($rule.FileSystemRights -band [Security.AccessControl.FileSystemRights]::FullControl) `
                -ne [Security.AccessControl.FileSystemRights]::FullControl `
            -or (-not $rule.IdentityReference.Equals($userSid) -and -not $rule.IdentityReference.Equals($systemSid))) {
            throw 'Test build directory ACL is not restricted to the current user and LocalSystem.'
        }
        $ruleSids += $rule.IdentityReference.Value
    }
    if ($ruleSids -notcontains $userSid.Value -or $ruleSids -notcontains $systemSid.Value) {
        throw 'Test build directory ACL must grant access only to the current user and LocalSystem.'
    }
}

if (-not (Test-Path -LiteralPath $compiler -PathType Leaf)) {
    throw 'The .NET Framework 4.x x64 C# compiler was not found.'
}
if (-not (Test-Path -LiteralPath $source -PathType Leaf)) {
    throw 'The bridge source was not found.'
}
if (-not $OutputDirectory) {
    if ($TestBuild) {
        throw 'Test builds require an explicit unique temporary output directory.'
    }
    $OutputDirectory = Join-Path $buildRoot 'Production'
}

$outputDirectory = [IO.Path]::GetFullPath($OutputDirectory)
$installedDirectory = [IO.Path]::GetFullPath($installedDirectory)
if (Test-PathOverlap $outputDirectory $installedDirectory) {
    throw 'Build output must be separate from the installed bridge directory.'
}
if (Test-ReparsePointInPath $outputDirectory) {
    throw 'Build output directory or one of its parent directories is a reparse point.'
}

if ($TestBuild) {
    $leaf = Split-Path -Leaf $outputDirectory
    if (-not (Test-SystemTempDirectory (Split-Path -Parent $outputDirectory)) `
        -or $leaf -notmatch '^mfc-qb-bridge-[a-f0-9]{16}$') {
        throw 'Test builds must use a unique direct child of the system temporary directory.'
    }
    if (-not (Test-Path -LiteralPath $outputDirectory -PathType Container)) {
        throw 'The test harness must atomically create the unique test directory before building.'
    }
    if (Test-ReparsePointInPath $outputDirectory) {
        throw 'Test build directory or one of its parent directories is a reparse point.'
    }
    if (@([IO.Directory]::EnumerateFileSystemEntries($outputDirectory)).Count -ne 0) {
        throw 'Test build output directory must be empty before compilation.'
    }
    Set-TestBuildDirectoryAcl $outputDirectory
} elseif (Test-Path -LiteralPath $outputDirectory) {
    if (-not (Test-Path -LiteralPath $outputDirectory -PathType Container)) {
        throw 'Production staging output path exists and is not a directory.'
    }
    if (@([IO.Directory]::EnumerateFileSystemEntries($outputDirectory)).Count -ne 0) {
        throw 'Production staging output directory must be new and empty.'
    }
} else {
    New-Item -ItemType Directory -Path $outputDirectory | Out-Null
}

$output = Join-Path $outputDirectory 'QuickBooksReadOnlyBridge.exe'
if (Test-Path -LiteralPath $output) {
    throw 'Refusing to overwrite an existing bridge executable.'
}

$arguments = @(
    '/nologo',
    '/target:exe',
    '/platform:x64',
    ('/out:' + $output),
    '/reference:System.Web.Extensions.dll',
    '/reference:System.Xml.dll',
    '/reference:Microsoft.CSharp.dll'
)
if ($TestBuild) {
    $arguments += '/define:BRIDGE_TEST_BUILD'
}
$arguments += $source

$compilerInfo = (Get-Item -LiteralPath $compiler).VersionInfo
Write-Output ('Configuration: ' + $(if ($TestBuild) { 'TEST ONLY' } else { 'PRODUCTION STAGING' }))
Write-Output ('Compiler: ' + $compiler + ' (' + $compilerInfo.FileVersion + ')')
Write-Output ('Output: ' + $output)
Write-Output ('Arguments: ' + ($arguments -join ' '))
& $compiler @arguments
if ($LASTEXITCODE -ne 0) {
    throw 'The QuickBooks bridge compilation failed.'
}
if (-not (Test-Path -LiteralPath $output -PathType Leaf)) {
    throw 'The compiler reported success but the output executable is missing.'
}
Write-Output ('Built ' + $(if ($TestBuild) { 'test-only' } else { 'production' }) + ' bridge staging executable: ' + $output)
