# ==============================================================
# نصب «هانا» روی ویندوز
#
# این فایل خودش کاری نمی‌کند جز پیدا کردن bash و اجرای install.sh —
# منطق نصب یک‌جا در install.sh است تا ویندوز و لینوکس دو رفتار مختلف
# پیدا نکنند.
#
# اجرا: راست‌کلیک روی فایل ← Run with PowerShell
# یا در پاورشل:
#     powershell -ExecutionPolicy Bypass -File .\install.ps1
#     powershell -ExecutionPolicy Bypass -File .\install.ps1 --port 9000
# ==============================================================

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$script = Join-Path $root 'install.sh'

if (-not (Test-Path $script)) {
    Write-Host "install.sh پیدا نشد. مخزن ناقص کلون شده است." -ForegroundColor Red
    exit 1
}

# ۱) Git Bash — همراه Git for Windows نصب می‌شود
$candidates = @(
    (Join-Path $env:ProgramFiles 'Git\bin\bash.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'Git\bin\bash.exe'),
    (Join-Path $env:LOCALAPPDATA 'Programs\Git\bin\bash.exe')
)

$bash = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

if ($bash) {
    & $bash $script @args
    exit $LASTEXITCODE
}

# ۲) وگرنه WSL
if (Get-Command wsl.exe -ErrorAction SilentlyContinue) {
    Write-Host "Git Bash پیدا نشد؛ با WSL اجرا می‌شود." -ForegroundColor Yellow
    Push-Location $root
    try {
        & wsl.exe bash ./install.sh @args
        exit $LASTEXITCODE
    } finally {
        Pop-Location
    }
}

Write-Host @"
نه Git Bash پیدا شد و نه WSL.

یکی از این دو را نصب کنید و دوباره امتحان کنید:
  Git for Windows : https://git-scm.com/download/win
  WSL             : wsl --install
"@ -ForegroundColor Red
exit 1
