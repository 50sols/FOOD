#requires -Version 5.1
# FOODEX GCC CI agent: owner-authorized PR command, isolated advisory smoke only.
[CmdletBinding()]
param([ValidateSet('Doctor','Install','Once','Loop')][string]$Mode='Doctor',
      [ValidateRange(30,3600)][int]$PollSeconds=120)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$Repo='walidatiyaai2025-gif/FOOD'
$Owner='walidatiyaai2025-gif'
$Base='Foodex_V2'
$Root=Join-Path $env:LOCALAPPDATA 'FoodexGccCI'
$State=Join-Path $Root 'cursor.json'
$Uri='repos/walidatiyaai2025-gif/FOOD/issues/comments?per_page=100&sort=created&direction=desc'
$Task='FOODEX-GCC-Local-CI'

function Doctor {
 foreach($tool in @('git','gh','docker')) {
  if(-not (Get-Command $tool -ErrorAction SilentlyContinue)) { throw "Missing: $tool" }
 }
 & gh auth status 2>$null | Out-Null
 if($LASTEXITCODE -ne 0) { throw 'GitHub CLI authentication is required to post PR evidence.' }
 $os=(& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
 if($LASTEXITCODE -ne 0 -or $os -ne 'linux') { throw 'Docker Desktop Linux containers are not available.' }
}
function Api([string]$Path) {
 $raw=& gh api $Path 2>&1
 if($LASTEXITCODE -ne 0) { throw "GitHub API failure: $Path" }
 return (ConvertFrom-Json -InputObject ($raw | Out-String))
}
function Comments { return @(Api $Uri) }
function Cursor {
 if(-not (Test-Path -LiteralPath $State)) { return [long]0 }
 $value=Get-Content -LiteralPath $State -Raw | ConvertFrom-Json
 if($null -eq $value.last_comment_id) { throw 'Invalid cursor file.' }
 return [long]$value.last_comment_id
}
function Save([long]$Id) {
 New-Item -ItemType Directory -Path $Root -Force | Out-Null
 $tmp="$State.tmp"
 @{last_comment_id=$Id} | ConvertTo-Json -Compress | Set-Content -LiteralPath $tmp -Encoding ASCII
 Move-Item -LiteralPath $tmp -Destination $State -Force
}
function Initialize {
 if(Test-Path -LiteralPath $State) { return }
 $latest=[long]0
 foreach($c in @(Comments)) { if([long]$c.id -gt $latest) { $latest=[long]$c.id } }
 Save $latest
 Write-Output "Initialized at comment $latest; older commands ignored."
}
function Report([int]$Pr,[string]$Sha,[string]$Status,[int]$Exit,[long]$Request) {
 $body="<!-- foodex-gcc-ci:$Request -->" + [Environment]::NewLine +
 "**GCC isolated local smoke: $Status**" + [Environment]::NewLine +
 "PR #$Pr; exact source SHA: $Sha; exit: $Exit." + [Environment]::NewLine +
 "Tests: CI map, failure registry, branch policy unit tests. Docker: offline, read-only source, no host credentials or socket." +
 [Environment]::NewLine + "**Advisory only**: does not substitute Laravel, Flutter, MySQL/Redis, signed ZIP, or exact-head Required CI."
 $result=& gh api "repos/$Repo/issues/$Pr/comments" -f "body=$body" 2>&1
 if($LASTEXITCODE -ne 0) { throw "Evidence posting failed on PR #$Pr" }
 if(-not (($result | Out-String | ConvertFrom-Json).id)) { throw 'GitHub did not confirm comment.' }
 Write-Output "PR #$Pr local evidence posted: $Status"
}
function Smoke([int]$Pr,[string]$Sha,[long]$Request) {
 $work=Join-Path $Root "work\$Pr-$Request"
 New-Item -ItemType Directory -Path $work -Force | Out-Null
 try {
  & git -C $work init -q | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git init failed' }
  & git -C $work remote add origin "https://github.com/$Repo.git" | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git remote failed' }
  & git -C $work -c credential.helper= fetch -q --depth 1 origin "refs/pull/$Pr/head" | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git fetch failed' }
  $actual=(& git -C $work rev-parse FETCH_HEAD | Out-String).Trim()
  if($actual -cne $Sha) { throw 'Requested SHA differs from fetched PR head.' }
  & git -C $work checkout -q --detach $Sha | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git checkout failed' }
  $tests='python3 -B scripts/ci-plan.py --validate && python3 -B scripts/ci-failure-registry.py --validate && python3 -B -m unittest discover -s scripts/tests -p test_ci_plan.py && python3 -B -m unittest discover -s scripts/tests -p test_foodex_branch_policy.py'
  $args=@('run','--rm','--pull=missing','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--pids-limit','128','--memory','2g','--cpus','2','--tmpfs','/tmp:rw,nosuid,nodev,noexec,size=64m','-e','PYTHONDONTWRITEBYTECODE=1','-v',("$($work):/work:ro"),'-w','/work','python:3.12-slim','sh','-ec',$tests)
  $log=Join-Path $Root "test-$Pr-$Request.log"
  & docker @args *> $log
  $exit=[int]$LASTEXITCODE
  if($exit -ne 0) { Write-Warning "Failed smoke ($exit): $log" }
  return $exit
 } finally {
  if(Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue }
 }
}
function Process($Comment) {
 if($null -eq $Comment.user -or $Comment.user.login -cne $Owner) { return }
 if([string]$Comment.body -cnotmatch '(?m)^/gcc-ci run ([a-f0-9]{40})\s*$') { return }
 $sha=$Matches[1]
 if([string]$Comment.issue_url -notmatch '^https://api\.github\.com/repos/walidatiyaai2025-gif/FOOD/issues/([0-9]+)$') { return }
 $n=[int]$Matches[1]
 $pr=Api "repos/$Repo/pulls/$n"
 if($pr.state -ne 'open' -or $pr.base.ref -cne $Base -or $pr.head.repo.full_name -cne $Repo -or $pr.head.sha -cne $sha) {
  Write-Warning "Rejected non-V2, changed or foreign PR #$n"; return
 }
 if([string]$pr.head.ref -cnotmatch '^(ci|feat|fix|test|chore|refactor|docs|worker)/[0-9]+-[a-z0-9-]+$') { return }
 $status='FAIL'; $exit=1
 try {
  $exit=Smoke -Pr $n -Sha $sha -Request ([long]$Comment.id)
  if($exit -eq 0) { $status='PASS' }
 } catch { Write-Warning "Smoke error: $($_.Exception.Message)" }
 Report -Pr $n -Sha $sha -Status $status -Exit $exit -Request ([long]$Comment.id)
}
function Poll {
 $mutex=New-Object System.Threading.Mutex($false,'Local\FOODEX_GCC_CI_AGENT')
 $held=$false
 try {
  $held=$mutex.WaitOne(0)
  if(-not $held) { return }
  Initialize
  $cursor=Cursor
  $recent=@(Comments | Where-Object { [long]$_.id -gt $cursor } | Sort-Object { [long]$_.id })
  foreach($comment in $recent) { Process $comment; Save ([long]$comment.id) }
  Write-Output "GCC poll processed $($recent.Count) new comment(s)."
 } finally {
  if($held) { $mutex.ReleaseMutex() }
  $mutex.Dispose()
 }
}
switch($Mode) {
 'Doctor' { Doctor; Write-Output 'GCC prerequisites OK; no runner installed.' }
 'Once' { Doctor; Poll }
 'Loop' {
  Doctor
  while($true) {
   try { Poll } catch { Write-Warning "GCC poll: $($_.Exception.Message)" }
   Start-Sleep -Seconds $PollSeconds
  }
 }
 'Install' {
  Doctor
  New-Item -ItemType Directory -Path $Root -Force | Out-Null
  Initialize
  $user=[System.Security.Principal.WindowsIdentity]::GetCurrent().Name
  $args='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $MyInvocation.MyCommand.Path + '" -Mode Loop -PollSeconds ' + $PollSeconds
  $action=New-ScheduledTaskAction -Execute (Get-Command powershell.exe).Source -Argument $args
  $trigger=New-ScheduledTaskTrigger -AtLogOn -User $user
  $principal=New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
  $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable
  Register-ScheduledTask -TaskName $Task -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
  Start-ScheduledTask -TaskName $Task
  Write-Output "Installed $Task. Owner PR comment trigger: /gcc-ci run <exact 40-char SHA>"
 }
}
