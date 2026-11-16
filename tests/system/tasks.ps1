param([Parameter(Mandatory=$true)][string]$Php,[Parameter(Mandatory=$true)][string]$Root,[Parameter(Mandatory=$true)][string]$Output)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') { throw 'Actual Windows Task Scheduler is required' }
$Root = (Resolve-Path $Root).Path
$Private = Split-Path $Root
if ((Split-Path $Private -Leaf) -notmatch '^cte-system-[a-f0-9]{12}$' -or
    (Split-Path $Root -Leaf) -ne 'portal-next' -or
    (Split-Path $Private) -ne (Get-Item $env:TEMP).FullName -or
    (Get-Content (Join-Path $Root '.env') -Raw) -notmatch 'SENTETİK KURULUM') {
    throw 'Only the private synthetic installation may register test tasks'
}
$Prefix = 'CTE-Test-' + [Guid]::NewGuid().ToString('N').Substring(0,12)
$Names = @("$Prefix-PrivateDocuments", "$Prefix-Scheduler")
$Checks = [System.Collections.Generic.List[string]]::new()
try {
    # Register twice: updates must reuse the same two tasks, never create duplicates.
    & (Join-Path $Root 'deploy/windows/Tasks.ps1') -Php $Php -Root $Root -Register -TaskPrefix $Prefix
    & (Join-Path $Root 'deploy/windows/Tasks.ps1') -Php $Php -Root $Root -Register -TaskPrefix $Prefix
    foreach ($Name in $Names) {
        $Task = Get-ScheduledTask -TaskName $Name -ErrorAction Stop
        if ($Task.Actions.Execute -ne $Php -or $Task.Actions.WorkingDirectory -ne $Root) { throw "Invalid action: $Name" }
        $Checks.Add("registered_idempotent_$Name")
        $Started = Get-Date
        Start-ScheduledTask -TaskName $Name -ErrorAction Stop
        $Finished = $false
        for ($Attempt=0; $Attempt -lt 90; $Attempt++) {
            Start-Sleep -Milliseconds 500
            $Info = Get-ScheduledTaskInfo -TaskName $Name
            $State = (Get-ScheduledTask -TaskName $Name).State
            if ($Info.LastRunTime -ge $Started.AddSeconds(-1) -and $State -ne 'Running' -and $Info.LastTaskResult -eq 0) { $Finished = $true; break }
        }
        if (-not $Finished) { throw "Task did not complete successfully: $Name" }
        $Checks.Add("executed_exit_zero_$Name")
    }
    $StatePath = Join-Path $Root 'storage/app/private/operations/state.json'
    $State = Get-Content $StatePath -Raw | ConvertFrom-Json
    if (-not $State.job.success -or -not $State.scheduler.success) { throw 'Job and scheduler heartbeats must both succeed' }
    $Checks.Add('actual_task_processes_wrote_job_and_scheduler_heartbeats')
} finally {
    foreach ($Name in $Names) {
        $Task = Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
        if ($Task) {
            Stop-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
            Unregister-ScheduledTask -TaskName $Name -Confirm:$false -ErrorAction Stop
        }
    }
}
foreach ($Name in $Names) { if (Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue) { throw 'Disposable task was not removed' } }
$Checks.Add('test_tasks_removed')
@{status='passed';platform='Windows';checks=$Checks.ToArray();productionTasksTouched=$false} | ConvertTo-Json -Depth 6 | Set-Content $Output -Encoding utf8
