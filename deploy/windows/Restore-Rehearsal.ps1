param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Root,[Parameter(Mandatory=$true)][string]$Archive,[Parameter(Mandatory=$true)][string]$Target,[Parameter(Mandatory=$true)][string]$Directory,[Parameter(Mandatory=$true)][string]$Mysql)
. (Join-Path $PSScriptRoot 'Common.ps1')
Invoke-Portal $Php $Root @('artisan','portal:verify-backup',$Archive)
Invoke-Portal $Php $Root @('artisan','portal:rehearse-restore',$Archive,$Target,$Directory,"--mysql=$Mysql",'--confirm')
