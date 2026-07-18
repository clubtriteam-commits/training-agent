# Деплой на PHP дашборда към сървъра през scp.
# Настройки: копирай deploy.config.example.psd1 -> deploy.config.psd1 и попълни.
#
#     .\deploy.ps1
#
# След копирането изрично сваля permissions до 644 (файлове) / 755 (директории) —
# group-writable файлове чупят PHP на хостинга (suPHP отказва да ги изпълни).

$ErrorActionPreference = 'Stop'

$configPath = Join-Path $PSScriptRoot 'deploy.config.psd1'
if (-not (Test-Path $configPath)) {
    Write-Host "Липсва deploy.config.psd1. Копирай deploy.config.example.psd1 и попълни настройките:" -ForegroundColor Red
    Write-Host "  Copy-Item deploy.config.example.psd1 deploy.config.psd1"
    exit 1
}
$cfg = Import-PowerShellDataFile $configPath
$target = "$($cfg.User)@$($cfg.Host)"
$remotePath = $cfg.RemotePath.TrimEnd('/')
$port = if ($cfg.Port) { $cfg.Port } else { 22 }

# SSH ключ (KeyFile в конфига): деплой без питане за парола. BatchMode
# гарантира, че при проблем с ключа скриптът гърми веднага, а не виси на prompt.
$sshArgs = @('-p', $port)
$scpArgs = @('-P', $port)
if ($cfg.KeyFile) {
    if (-not (Test-Path $cfg.KeyFile)) {
        Write-Host "Не намирам SSH ключа: $($cfg.KeyFile)" -ForegroundColor Red
        exit 1
    }
    $sshArgs += @('-i', $cfg.KeyFile, '-o', 'BatchMode=yes')
    $scpArgs += @('-i', $cfg.KeyFile, '-o', 'BatchMode=yes')
}

$localDir = Join-Path $PSScriptRoot 'dashboard-backup'
if (-not (Test-Path $localDir)) {
    Write-Host "Не намирам $localDir" -ForegroundColor Red
    exit 1
}

Write-Host "Деплой на dashboard-backup/ -> ${target}:${remotePath} (порт $port)" -ForegroundColor Cyan

# 1. Уверяваме се, че целевата директория съществува
ssh @sshArgs $target "mkdir -p '$remotePath'"
if ($LASTEXITCODE -ne 0) { Write-Host "ssh mkdir се провали" -ForegroundColor Red; exit 1 }

# 2. Копираме съдържанието (PowerShell не разширява wildcards за scp, затова изброяваме)
$items = Get-ChildItem $localDir | ForEach-Object { $_.FullName }
scp @scpArgs -r @items "${target}:${remotePath}/"
if ($LASTEXITCODE -ne 0) { Write-Host "scp се провали" -ForegroundColor Red; exit 1 }

# 3. Правилни permissions: 755 директории, 644 файлове (никога group-writable)
ssh @sshArgs $target "find '$remotePath' -type d -exec chmod 755 {} + && find '$remotePath' -type f -exec chmod 644 {} +"
if ($LASTEXITCODE -ne 0) { Write-Host "chmod се провали" -ForegroundColor Red; exit 1 }

# 4. Проверка: показваме какво има на сървъра
ssh @sshArgs $target "ls -la '$remotePath'"

Write-Host "Готово." -ForegroundColor Green
