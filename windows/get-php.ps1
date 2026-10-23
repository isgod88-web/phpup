# 首次运行时下载 Windows 版 PHP（官方 windows.php.net，NTS x64），解压到工具目录下的 php\
param([Parameter(Mandatory = $true)][string]$Dest)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$base = 'https://windows.php.net/downloads/releases/latest/'
try {
    $html = (Invoke-WebRequest -UseBasicParsing -Uri $base).Content
} catch {
    Write-Host "无法访问 $base ：$($_.Exception.Message)"
    exit 1
}
$name = $null
foreach ($ver in @('8.5', '8.4')) {
    $m = [regex]::Matches($html, "php-$([regex]::Escape($ver))-nts-Win32-vs\d+-x64-latest\.zip") | Select-Object -First 1
    if ($m) { $name = $m.Value; break }
}
if (-not $name) { Write-Host '在 windows.php.net 上没有找到 PHP 8.5/8.4 的下载包'; exit 1 }
$zip = Join-Path $env:TEMP $name
Write-Host "下载 $base$name ..."
Invoke-WebRequest -UseBasicParsing -Uri ($base + $name) -OutFile $zip
New-Item -ItemType Directory -Force -Path $Dest | Out-Null
Expand-Archive -Path $zip -DestinationPath $Dest -Force
Remove-Item $zip -Force

# php.ini：在官方 production 模板基础上启用本工具需要的扩展
$ini = Get-Content (Join-Path $Dest 'php.ini-production') -Raw
$ini = $ini -replace ';\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"'
foreach ($ext in @('mbstring', 'openssl', 'zip', 'fileinfo', 'curl')) {
    $ini = $ini -replace (";\s*extension=$ext\b"), "extension=$ext"
}
$ini += "`r`nmemory_limit = -1`r`nmax_execution_time = 0`r`n"
[IO.File]::WriteAllText((Join-Path $Dest 'php.ini'), $ini)

# 检查能否运行（缺少 VC++ 运行库时 php.exe 无法启动）
$php = Join-Path $Dest 'php.exe'
& $php -v *> $null
if ($LASTEXITCODE -ne 0) {
    Write-Host '需要安装微软 VC++ 运行库，正在下载安装（可能会弹出管理员确认）...'
    $vc = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Invoke-WebRequest -UseBasicParsing -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vc
    Start-Process -FilePath $vc -ArgumentList '/install', '/passive', '/norestart' -Wait
    & $php -v *> $null
    if ($LASTEXITCODE -ne 0) { Write-Host 'PHP 仍无法运行，请手动安装 VC++ 运行库后重试'; exit 1 }
}
& $php -v
exit 0
