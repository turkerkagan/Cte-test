Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
function Invoke-Portal([string]$Php, [string]$Root, [string[]]$Arguments) {
    Push-Location $Root
    try { & $Php @Arguments; if ($LASTEXITCODE -ne 0) { throw "Portal command failed: $($Arguments[0])" } }
    finally { Pop-Location }
}
function Test-PortalPackage([string]$Php, [string]$Root) {
    Invoke-Portal $Php $Root @('tools/release/verify.php')
    Invoke-Portal $Php $Root @('-r', "require 'vendor/autoload.php'; echo PHP_VERSION;")
    $Python = Join-Path $Root 'runtime/python/python.exe'
    & $Python -I -c 'import openpyxl,xlrd,et_xmlfile; print("Document runtime OK")'
    if ($LASTEXITCODE -ne 0) { throw 'Document runtime unavailable' }
}
