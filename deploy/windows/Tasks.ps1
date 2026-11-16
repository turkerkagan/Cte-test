param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Root,[switch]$Register,[ValidatePattern('^[A-Za-z][A-Za-z0-9-]{0,59}$')][string]$TaskPrefix='CTE')
. (Join-Path $PSScriptRoot 'Common.ps1')
$CleanupTask = "$TaskPrefix-PrivateDocuments"
$SchedulerTask = "$TaskPrefix-Scheduler"
# Plain manual invocation works without installing a recurring task.
if (-not $Register) { Invoke-Portal $Php $Root @('artisan','portal:clean-private-documents'); exit }
$Action = New-ScheduledTaskAction -Execute $Php -Argument 'artisan portal:clean-private-documents' -WorkingDirectory $Root
$Trigger = New-ScheduledTaskTrigger -Daily -At '03:00'
if (Get-ScheduledTask -TaskName $CleanupTask -ErrorAction SilentlyContinue) {
    Set-ScheduledTask -TaskName $CleanupTask -Action $Action -Trigger $Trigger -ErrorAction Stop | Out-Null
} else {
    Register-ScheduledTask -TaskName $CleanupTask -Action $Action -Trigger $Trigger -Description 'Expired private attachments and document outputs' -ErrorAction Stop | Out-Null
}
$SchedulerAction = New-ScheduledTaskAction -Execute $Php -Argument 'artisan schedule:run' -WorkingDirectory $Root
$SchedulerTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$SchedulerSettings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable
if (Get-ScheduledTask -TaskName $SchedulerTask -ErrorAction SilentlyContinue) {
    Set-ScheduledTask -TaskName $SchedulerTask -Action $SchedulerAction -Trigger $SchedulerTrigger -Settings $SchedulerSettings -ErrorAction Stop | Out-Null
} else {
    Register-ScheduledTask -TaskName $SchedulerTask -Action $SchedulerAction -Trigger $SchedulerTrigger -Settings $SchedulerSettings -Description 'Portal backup plans and scheduled maintenance every minute' -ErrorAction Stop | Out-Null
}
