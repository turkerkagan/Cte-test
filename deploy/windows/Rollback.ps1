param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Previous,[Parameter(Mandatory=$true)][string]$Current,[Parameter(Mandatory=$true)][string]$Target,[switch]$SchemaCompatibilityConfirmed)
. (Join-Path $PSScriptRoot 'Common.ps1')
if (-not $SchemaCompatibilityConfirmed) { throw 'Confirm previous-code compatibility with the CURRENT schema first. Schema downgrade is never automatic.' }
$Previous = (Resolve-Path $Previous).Path; $Current = (Resolve-Path $Current).Path
if (Test-Path $Target) { throw 'Rollback target must be a NEW separate directory.' }
Test-PortalPackage $Php $Previous
Invoke-Portal $Php $Current @('artisan','down','--retry=60')
New-Item -ItemType Directory -Path $Target | Out-Null
Get-ChildItem -Force $Previous | Where-Object {$_.Name -notin @('.env','storage')} | Copy-Item -Destination $Target -Recurse -ErrorAction Stop
Copy-Item (Join-Path $Current '.env') (Join-Path $Target '.env') -ErrorAction Stop
foreach ($Name in @('app/private','app/public','framework/cache/data','framework/sessions','framework/views','logs')) { New-Item -ItemType Directory -Path (Join-Path $Target "storage/$Name") -Force | Out-Null }
foreach ($Name in @('private','public')) {
    $Source = Join-Path $Current "storage/app/$Name"
    if (Test-Path $Source) { Get-ChildItem -Force $Source | Copy-Item -Destination (Join-Path $Target "storage/app/$Name") -Recurse -ErrorAction Stop }
}
Get-ChildItem (Join-Path $Target 'bootstrap/cache') -Filter '*.php' | Remove-Item -ErrorAction Stop
Invoke-Portal $Php $Target @('tools/release/update-runtime.php')
Invoke-Portal $Php $Target @('artisan','down','--retry=60')
Invoke-Portal $Php $Target @('artisan','portal:doctor')
Invoke-Portal $Php $Target @('artisan','portal:acceptance')
Write-Host 'Rollback release prepared with CURRENT database, APP_KEY and files. No SQL restore or migration downgrade was executed. Complete browser acceptance, switch Apache public root and scheduled task paths to this target, then artisan up. Both original releases remain preserved.'
