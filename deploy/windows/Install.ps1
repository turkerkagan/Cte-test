param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Root,[Parameter(Mandatory=$true)][string]$Administrator)
. (Join-Path $PSScriptRoot 'Common.ps1')
Test-PortalPackage $Php $Root
if (Test-Path (Join-Path $Root '.env')) { throw 'Fresh installation requires absent .env. Use update for an existing release.' }
Invoke-Portal $Php $Root @('tools/release/configure.php')
Invoke-Portal $Php $Root @('artisan','portal:doctor')
# The database is prepared by its administrator. No CREATE/seed/migrate:fresh is used.
Invoke-Portal $Php $Root @('artisan','portal:assert-empty')
Invoke-Portal $Php $Root @('artisan','migrate','--force')
Invoke-Portal $Php $Root @('artisan','portal:install-admin',$Administrator)
Invoke-Portal $Php $Root @('artisan','portal:acceptance')
Write-Host 'Fresh installation ready for offline browser acceptance and Apache public-root configuration.'
