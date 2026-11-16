param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Current,[Parameter(Mandatory=$true)][string]$Next,[Parameter(Mandatory=$true)][string]$BackupDirectory,[Parameter(Mandatory=$true)][string]$Mysqldump)
. (Join-Path $PSScriptRoot 'Common.ps1')
$Current = (Resolve-Path $Current).Path; $Next = (Resolve-Path $Next).Path
if ($Current -eq $Next) { throw 'Use a separate release directory.' }
Test-PortalPackage $Php $Next
if (Test-Path (Join-Path $Next '.env')) { throw 'Next release must have no configuration yet.' }
Invoke-Portal $Php $Current @('artisan','down','--retry=60')
# Any failure leaves current in maintenance; no migration or automatic cutover follows.
Invoke-Portal $Php $Current @('artisan','portal:backup',$BackupDirectory,"--mysqldump=$Mysqldump")
Copy-Item (Join-Path $Current '.env') (Join-Path $Next '.env') -ErrorAction Stop
foreach ($Name in @('private','public')) {
    $Source = Join-Path $Current "storage/app/$Name"
    $Target = Join-Path $Next "storage/app/$Name"
    if (Test-Path $Source) {
        if (Test-Path $Target) { if (@(Get-ChildItem -Force $Target | Where-Object {$_.Name -ne '.gitignore'}).Count -gt 0) { throw 'Next storage is not empty' } }
        New-Item -ItemType Directory -Path $Target -Force | Out-Null
        Get-ChildItem -Force $Source | Copy-Item -Destination $Target -Recurse -ErrorAction Stop
    }
}
# APP_KEY is copied, never regenerated. Correct only the bundled document runtime path.
Invoke-Portal $Php $Next @('tools/release/update-runtime.php')
Invoke-Portal $Php $Next @('artisan','down','--retry=60')
Invoke-Portal $Php $Next @('artisan','portal:doctor')
Invoke-Portal $Php $Next @('artisan','migrate','--force')
Invoke-Portal $Php $Next @('artisan','portal:acceptance')
Write-Host 'Next release stays in maintenance. Complete restore rehearsal and admin/personnel browser acceptance, switch Apache public root, then run artisan up. No automatic production cutover.'
