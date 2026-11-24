#requires -Version 5.1
# FOODEX GCC CI agent: owner-authorized PR command, isolated advisory smoke only.
[CmdletBinding()]
param([ValidateSet('Doctor','Install','Once','Loop')][string]$Mode='Doctor',
      [ValidateRange(30,3600)][int]$PollSeconds=120)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
$Repo='50sols/FOOD'
$RepositoryId=1385223867
$Owner='walidatiyaai2025-gif'
$Base='Foodex_V2'
$Root=Join-Path $env:LOCALAPPDATA 'FoodexGccCI'
$State=Join-Path $Root 'cursor.json'
$Uri='repos/50sols/FOOD/issues/comments?per_page=100&sort=created&direction=desc'
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
# Walk newest-first pages until the saved cursor is reached. A single
# 100-comment page can silently lose owner commands during a busy interval.
# Cap the traversal so an API/backlog anomaly fails closed before Save().
function Comments([long]$After=0) {
 $found=New-Object 'System.Collections.Generic.List[object]'
 for($page=1;$page -le 100;$page++) {
  $batch=@(Api "$Uri&page=$page")
  foreach($comment in $batch) {
   if([long]$comment.id -le $After) { return $found.ToArray() }
   $found.Add($comment)
  }
  if($batch.Count -lt 100) { return $found.ToArray() }
 }
 throw 'GCC comment backlog exceeds 100 pages; refusing to advance cursor.'
}
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
 foreach($c in @(Api $Uri)) { if([long]$c.id -gt $latest) { $latest=[long]$c.id } }
 Save $latest
 Write-Output "Initialized at comment $latest; older commands ignored."
}
function Report([int]$Pr,[string]$Sha,[string]$Status,[int]$Exit,[long]$Request) {
 $body="<!-- foodex-gcc-ci:$Request -->" + [Environment]::NewLine +
 "**GCC isolated local smoke: $Status**" + [Environment]::NewLine +
 "PR #$Pr; exact source SHA: $Sha; exit: $Exit." + [Environment]::NewLine +
 "Last execution stage: $(if($Status -eq 'PASS'){'complete'}else{$script:GccLastFailedStage})." + [Environment]::NewLine +
 "Tests: CI map, failure registry, branch policy unit tests. Docker: offline, read-only source, no host credentials or socket." +
 [Environment]::NewLine + "**Advisory only**: does not substitute Laravel, Flutter, MySQL/Redis, signed ZIP, or exact-head Required CI."
 $result=& gh api "repos/$Repo/issues/$Pr/comments" -f "body=$body" 2>&1
 if($LASTEXITCODE -ne 0) { throw "Evidence posting failed on PR #$Pr" }
 if(-not (($result | Out-String | ConvertFrom-Json).id)) { throw 'GitHub did not confirm comment.' }
 Write-Output "PR #$Pr local evidence posted: $Status"
}
# Git may emit repository credentials, proxy details or private local paths.
# Inspect a small, private stderr file and return ONLY a fixed category.
# This classifies a failure; it never authorizes a retry or bypasses a lock.
function Classify-GitFetchFailure([string]$Path) {
 try {
  $file=Get-Item -LiteralPath $Path -ErrorAction Stop
  if($file.Length -le 0 -or $file.Length -gt 32768) {
   return 'git-fetch-nonzero-exit'
  }
  $privateDiagnostic=[System.IO.File]::ReadAllText($file.FullName)
  if($privateDiagnostic -match '(?i)(shallow|index|packed-refs)\.lock|another git process') {
   return 'git-fetch-lock'
  }
  if($privateDiagnostic -match '(?i)could not resolve host|could not resolve proxy|name or service not known|failed to connect|connection timed out|connection reset|ssl certificate problem|proxy connect|http (429|5[0-9][0-9])') {
   return 'git-fetch-network'
  }
  if($privateDiagnostic -match '(?i)repository not found|authentication failed|permission denied \(publickey\)|could not read username|http (401|403|407)') {
   return 'git-fetch-access'
  }
  if($privateDiagnostic -match '(?i)couldn.t find remote ref|not our ref|fatal: bad object') {
   return 'git-fetch-ref'
  }
 } catch {
  return 'git-fetch-nonzero-exit'
 }
 return 'git-fetch-nonzero-exit'
}
function Invoke-BoundedGitFetch([string]$Checkout,[int]$Pr,[long]$Request) {
 $bin=(Get-Command git.exe -ErrorAction Stop).Source
 $stdout=Join-Path $Root ("git-fetch-{0}-{1}.stdout" -f $Pr,$Request)
 $stderr=Join-Path $Root ("git-fetch-{0}-{1}.stderr" -f $Pr,$Request)
 $arguments='-C "'+$Checkout+'" -c credential.helper= fetch --quiet --depth 1 origin refs/pull/'+$Pr+'/head'
 $oldPrompt=$env:GIT_TERMINAL_PROMPT
 $env:GIT_TERMINAL_PROMPT='0'
 $process=$null
 try {
  try {
   $process=Start-Process -FilePath $bin -ArgumentList $arguments -PassThru -NoNewWindow -RedirectStandardOutput $stdout -RedirectStandardError $stderr -ErrorAction Stop
  } catch {
   $script:GccLastFailedStage='git-fetch-process-start'
   throw 'Git fetch process could not start; local diagnostic files are private.'
  }
  if(-not $process.WaitForExit(900000)) {
   $script:GccLastFailedStage='git-fetch-timeout'
   & taskkill.exe /T /F /PID $process.Id 1>$null 2>$null
   throw 'Git fetch exceeded 15-minute bounded timeout; exact-head test not executed.'
  }
  if($process.ExitCode -ne 0) {
   $script:GccLastFailedStage=Classify-GitFetchFailure -Path $stderr
   throw "Git fetch failed with exit code $($process.ExitCode); local diagnostic files are private."
  }
 } finally {
  $env:GIT_TERMINAL_PROMPT=$oldPrompt
  if($null -ne $process) { $process.Dispose() }
 }
}
function Smoke([int]$Pr,[string]$Sha,[long]$Request) {
 # Every attempt must have a private checkout. Reusing a prior request path
 # can replay a half-fetched .git/shallow.lock or an existing origin remote.
 $work=Join-Path $Root ("work\{0}-{1}-{2}" -f $Pr,$Request,[guid]::NewGuid().ToString("N"))
 New-Item -ItemType Directory -Path $work -Force | Out-Null
 try {
  & git -C $work init -q | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git init failed' }
  & git -C $work remote add origin "https://github.com/$Repo.git" | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git remote failed' }
  $script:GccLastFailedStage='git-fetch'
  Invoke-BoundedGitFetch -Checkout $work -Pr $Pr -Request $Request
  $script:GccLastFailedStage='checkout'
  $actual=(& git -C $work rev-parse FETCH_HEAD | Out-String).Trim()
  if($actual -cne $Sha) { throw 'Requested SHA differs from fetched PR head.' }
  & git -C $work checkout -q --detach $Sha | Out-Null
  if($LASTEXITCODE -ne 0) { throw 'git checkout failed' }
  $tests='python3 -B scripts/ci-plan.py --validate && python3 -B scripts/ci-failure-registry.py --validate && python3 -B -m unittest discover -s scripts/tests -p test_ci_plan.py && python3 -B -m unittest discover -s scripts/tests -p test_foodex_branch_policy.py'
  $args=@('run','--rm','--pull=missing','--network','none','--read-only','--cap-drop','ALL','--security-opt','no-new-privileges','--pids-limit','128','--memory','2g','--cpus','2','--tmpfs','/tmp:rw,nosuid,nodev,noexec,size=64m','-e','PYTHONDONTWRITEBYTECODE=1','-v',("$($work):/work:ro"),'-w','/work','python:3.12','sh','-ec',$tests)
  $log=Join-Path $Root "test-$Pr-$Request.log"
  # PowerShell 5.1 promotes native stderr into terminating errors with Stop.
  # unittest writes progress to stderr even when its exit code is zero.
  $script:GccLastFailedStage='smoke-tests'
  $exit=Invoke-DockerLogged -DockerArgs $args -LogPath $log -Stage 'smoke-tests'
  if($exit -ne 0) { Write-Warning "Failed smoke ($exit): $log" }
  return $exit
 } finally {
  if(Test-Path -LiteralPath $work) { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue }
 }
}

# Full backend execution uses immutable trusted tooling, not Compose files from PRs.
function Trusted-Toolkit {
 $pin='1518a10cac3aafc9f57f86990d70950aa1354bbd'
 $kit=Join-Path $Root "trusted\$pin"
 $expected=@{
  'Dockerfile.backend'='99709b10a0b31538e223fa48eedc0d0190b8796d'
  'compose.backend.yaml'='b1d06b2b39e8b67eb6534a8937d503c7500f7127'
  'Dockerfile.flutter'='2125d8138a9eb1956d67224f6ce652d9ba1a4815'
  'preview.sh'='d1a221bf213e116edcb70c4de16c14cb902c9279'
  'prepare.sh'='4ae94acdd1376973632a8306a0070017ffe8bf44'
  'full.sh'='301cdebcdf2599adb80958efe7cf66b9c1522c90'
 }
 New-Item -ItemType Directory -Path $kit -Force | Out-Null
 foreach($name in @('Dockerfile.backend','Dockerfile.flutter','compose.backend.yaml','prepare.sh','full.sh','preview.sh')) {
  $file=Join-Path $kit $name
  if(-not (Test-Path -LiteralPath $file)) {
   $url="https://raw.githubusercontent.com/$Repo/$pin/scripts/local-ci/$name"
   Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $file
  }
  $actual=(& git hash-object $file | Out-String).Trim()
  if($actual -cne $expected[$name]) { throw "Trusted GCC toolkit hash mismatch: $name" }
 }
 return $kit
}
# Do not pipe native Docker stderr through Windows PowerShell with
# ErrorActionPreference=Stop: successful BuildKit progress is printed on stderr
# and can be promoted to NativeCommandError before LASTEXITCODE is inspected.
# Capture both streams as files and use the native process exit code.
function Invoke-DockerLogged([string[]]$DockerArgs,[string]$LogPath,[string]$Stage) {
 $bin=(Get-Command docker.exe -ErrorAction Stop).Source
 $cmd=(@($DockerArgs | ForEach-Object {
  $item=[string]$_
  if($item -match '[\s"]') { '"' + $item.Replace('"','\"') + '"' } else { $item }
 }) -join ' ')
 $out="$LogPath.stdout"
 $err="$LogPath.stderr"
 Add-Content -LiteralPath $LogPath -Value "=== GCC docker stage $Stage ==="
 $process=Start-Process -FilePath $bin -ArgumentList $cmd -Wait -PassThru -NoNewWindow -RedirectStandardOutput $out -RedirectStandardError $err
 if(Test-Path -LiteralPath $out) {
  Get-Content -LiteralPath $out | Add-Content -LiteralPath $LogPath
  Remove-Item -LiteralPath $out -Force -ErrorAction SilentlyContinue
 }
 if(Test-Path -LiteralPath $err) {
  Get-Content -LiteralPath $err | Add-Content -LiteralPath $LogPath
  Remove-Item -LiteralPath $err -Force -ErrorAction SilentlyContinue
 }
 Add-Content -LiteralPath $LogPath -Value "=== GCC docker exit $($process.ExitCode) stage $Stage ==="
 return [int]$process.ExitCode
}

function Full-CI([int]$Pr,[string]$Sha,[long]$Request,[string]$Checkout,[ValidateSet('all','backend','setup','packages')][string]$Profile) {
 if(-not (Test-Path (Join-Path $Checkout 'backend/composer.lock'))) {
  $script:GccLastFailedStage='missing-composer-lock'
  Write-Warning 'Fail closed: exact PR head lacks backend/composer.lock; canonical dependency #3444 must be resolved first.'
  return 1
 }
 $kit=Trusted-Toolkit
 $report=Join-Path $Root "evidence\$Pr-$Request"
 New-Item -ItemType Directory -Path $report -Force | Out-Null
 # The versioned source tag is required by the genuine update-bundle builder.
 if($Profile -eq 'all' -or $Profile -eq 'packages') {
  & git -C $Checkout -c credential.helper= fetch --quiet --depth=1 origin tag v1.0.125
  if($LASTEXITCODE -ne 0) { throw 'Canonical update base v1.0.125 could not be fetched.' }
 }
 $env:GCC_SOURCE_DIR=$Checkout
 $env:GCC_EVIDENCE_DIR=$report
 $env:GCC_EXPECTED_SHA=$Sha
 $env:GCC_PROFILE=$Profile
 $compose=Join-Path $kit 'compose.backend.yaml'
 $project="gcc$Pr$Request"
 $log=Join-Path $Root "full-$Pr-$Request.log"
 $exit=1
 $stage='cache'
 try {
  $rc=Invoke-DockerLogged -DockerArgs @('volume','create','foodex-gcc-composer-cache') -LogPath $log -Stage 'cache-composer'
  if($rc -ne 0) { throw 'Composer cache Docker volume unavailable' }
  $rc=Invoke-DockerLogged -DockerArgs @('volume','create','foodex-gcc-flutter-cache') -LogPath $log -Stage 'cache-flutter'
  if($rc -ne 0) { throw 'Flutter cache Docker volume unavailable' }
  # Avoid Docker Compose Bake on Windows (Buildx file-already-closed
  # defect). Direct BuildKit docker build keeps layer caching, and Compose
  # uses the exact prebuilt images named <project>-<service>.
  $env:COMPOSE_BAKE='false'
  $stage='docker-image-build'
  $backendImage="$($project)-preparer"
  $runnerImage="$($project)-runner"
  $rc=Invoke-DockerLogged -DockerArgs @('build','--progress=plain','-f',(Join-Path $kit 'Dockerfile.backend'),'-t',$backendImage,$kit) -LogPath $log -Stage $stage
  if($rc -ne 0) { throw "Direct Docker backend build exited $rc" }
  $rc=Invoke-DockerLogged -DockerArgs @('tag',$backendImage,$runnerImage) -LogPath $log -Stage 'backend-image-alias'
  if($rc -ne 0) { throw "Runner image alias failed with $rc" }
  if($Profile -ne 'backend') {
   $stage='flutter-image-build'
   $previewImage="$($project)-preview"
   $rc=Invoke-DockerLogged -DockerArgs @('build','--progress=plain','-f',(Join-Path $kit 'Dockerfile.flutter'),'-t',$previewImage,$kit) -LogPath $log -Stage $stage
   if($rc -ne 0) { throw "Direct Docker Flutter build exited $rc" }
  }
  $stage='composer-dependencies'
  $rc=Invoke-DockerLogged -DockerArgs @('compose','-p',$project,'-f',$compose,'run','--rm','--no-deps','preparer') -LogPath $log -Stage $stage
  if($rc -ne 0) { throw "Composer preparation exited $rc" }
  if($Profile -ne 'backend') {
   $stage='flutter-web-previews'
   $rc=Invoke-DockerLogged -DockerArgs @('compose','-p',$project,'-f',$compose,'run','--rm','--no-deps','preview') -LogPath $log -Stage $stage
   if($rc -ne 0) { throw "Flutter preview build exited $rc" }
  }
  $stage='mysql-redis-startup'
  $rc=Invoke-DockerLogged -DockerArgs @('compose','-p',$project,'-f',$compose,'up','-d','--wait','--no-build','mysql','redis') -LogPath $log -Stage $stage
  if($rc -ne 0) { throw "Disposable MySQL/Redis startup exited $rc" }
  $stage='laravel-and-package-suite'
  $rc=Invoke-DockerLogged -DockerArgs @('compose','-p',$project,'-f',$compose,'run','--rm','runner') -LogPath $log -Stage $stage
  if($rc -ne 0) { throw "Laravel/package runner exited $rc" }
  $stage='validate-exact-head-results'
  $resultFile=Join-Path $report 'result.json'
  if(-not (Test-Path -LiteralPath $resultFile)) { throw 'Missing full CI result manifest.' }
  $result=Get-Content -LiteralPath $resultFile -Raw | ConvertFrom-Json
  if($result.result -cne 'PASS' -or $result.source_sha -cne $Sha -or $result.profile -cne $Profile -or
     $result.signed -ne $false -or $result.trusted_release -ne $false) {
   throw 'Full CI manifest failed exact-head/profile/staging validation.'
  }
  $artifacts=Get-ChildItem -Path $report -Filter '*.zip' -Recurse
  # Require exact canonical package names rather than a count of arbitrary ZIPs.
  if([string]$result.version -cnotmatch '^[0-9]+[.][0-9]+[.][0-9]+\z') {
   throw 'GCC result version must be a strict semantic version.'
  }
  $requiredPaths=@()
  if($Profile -ne 'backend') {
   $requiredPaths+=(Join-Path $report "FOODEX-Setup-v$($result.version).zip")
  }
  if($Profile -eq 'all' -or $Profile -eq 'packages') {
   $requiredPaths+=(Join-Path (Join-Path $report 'update') 'FOODEX-Update.zip')
  }
  foreach($expectedPath in $requiredPaths) {
   if(@($artifacts | Where-Object { $_.FullName -ceq $expectedPath }).Count -ne 1) {
    throw "Missing canonical ZIP artifact at expected evidence path: $expectedPath (profile=$Profile)"
   }
  }
  if($Profile -eq 'all' -or $Profile -eq 'backend') {
   foreach($name in @('laravel-shard-0.xml','laravel-shard-1.xml','laravel-shard-2.xml','laravel-shard-3.xml','deployment.xml')) {
    if(-not (Test-Path -LiteralPath (Join-Path $report $name))) { throw "Missing PHPUnit evidence: $name" }
   }
  }
  # A filename-keyed manifest cannot safely distinguish duplicate ZIP
  # basenames in separate directories. Reparse points are never real staging
  # artifacts and must not be followed while verifying SHA-256.
  $seenArtifactNames=[System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
  $canonicalEvidenceRoot=(Get-Item -LiteralPath $report -ErrorAction Stop).FullName
  foreach($artifact in $artifacts) {
   # A regular ZIP reached through a junction/symlinked parent still reads
   # bytes outside the isolated evidence tree. Check every directory up to
   # and including the canonical evidence root before reading the file.
   $parent=$artifact.Directory
   $withinEvidenceRoot=$false
   while($null -ne $parent) {
    if(($parent.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
     throw "Refusing reparse-point ZIP parent directory: $($artifact.Name)"
    }
    if($parent.FullName -eq $canonicalEvidenceRoot) {
     $withinEvidenceRoot=$true
     break
    }
    $parent=$parent.Parent
   }
   if(-not $withinEvidenceRoot) {
    throw "ZIP artifact lies outside canonical GCC evidence root: $($artifact.Name)"
   }
   if(($artifact.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
    throw "Refusing symlink/reparse-point ZIP artifact: $($artifact.Name)"
   }
   if(-not $seenArtifactNames.Add([string]$artifact.Name)) {
    throw "Duplicate ZIP artifact filename in GCC evidence: $($artifact.Name)"
   }
   $expectedHash=[string]$result.artifacts_sha256.($artifact.Name)
   $actualHash=(Get-FileHash -LiteralPath $artifact.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
   if(-not $expectedHash -or $expectedHash -cne $actualHash) {
    throw "Artifact SHA256 integrity failure: $($artifact.Name)"
   }
  }
  return 0
 } catch {
  $script:GccLastFailedStage=$stage
  Write-Warning "GCC full acceptance: $($_.Exception.Message)"
  return 1
 } finally {
  try {
   $null=Invoke-DockerLogged -DockerArgs @('compose','-p',$project,'-f',$compose,'down','--volumes','--remove-orphans') -LogPath $log -Stage 'cleanup'
  } catch { Write-Warning 'GCC compose cleanup failed; manual isolated-project cleanup may be required.' }
  Remove-Item -Path Env:\GCC_SOURCE_DIR,Env:\GCC_EVIDENCE_DIR,Env:\GCC_EXPECTED_SHA,Env:\GCC_PROFILE,Env:\COMPOSE_BAKE -ErrorAction SilentlyContinue
 }
}
function Report-Full([int]$Pr,[string]$Sha,[string]$Status,[int]$Exit,[long]$Request,[string]$Profile) {
 $report=Join-Path $Root "evidence\$Pr-$Request"
 $summary=''
 $stageLabel=if($Status -eq 'FAIL') { [string]$script:GccLastFailedStage } else { 'complete' }
 $evidence=Join-Path $report 'result.json'
 if(Test-Path -LiteralPath $evidence) {
  try {
   $data=Get-Content -LiteralPath $evidence -Raw | ConvertFrom-Json
   if($data.source_sha -cne $Sha) { throw 'Result SHA mismatch' }
   if($data.result -ceq 'FAIL' -and $null -ne $data.PSObject.Properties['failed_stage'] -and
      [string]$data.failed_stage -cmatch '^[a-z][a-z0-9-]{0,63}$') {
    $summary += [Environment]::NewLine + "- Failed trusted runtime step: $($data.failed_stage)"
   }
   # Only fixed labels and validated step numbers are safe for public evidence.
   if($data.result -ceq 'FAIL' -and $null -ne $data.PSObject.Properties['failure_class'] -and
      [string]$data.failure_class -cmatch '^(unclassified|finish-response-indeterminate|migration-timeout|migration-connection-refused|migration-empty-reply|migration-sqlstate|installer-validation|installer-http-status|http-readiness-timeout|http-server-exited)$') {
     $summary += [Environment]::NewLine + "- Installer failure category: $($data.failure_class)"
   }
   if($data.result -ceq 'FAIL' -and $null -ne $data.PSObject.Properties['installer_step'] -and
      [string]$data.installer_step -cmatch '^(?:[1-9]|1[0-5])$') {
     $summary += [Environment]::NewLine + "- Installer wizard step: $($data.installer_step)"
   }
   if($null -ne $data.PSObject.Properties['artifacts_sha256'] -and $data.artifacts_sha256) {
    foreach($item in $data.artifacts_sha256.PSObject.Properties) {
     $summary += [Environment]::NewLine + "- $($item.Name): SHA256 $($item.Value)"
    }
   }
  } catch {
   $summary=[Environment]::NewLine + '- Evidence JSON could not be validated'
  }
 }
 $body="<!-- foodex-gcc-full:$Request -->" + [Environment]::NewLine +
 "**GCC $Profile local Laravel/MySQL/ZIP staging: $Status**" + [Environment]::NewLine +
 "- Exact PR head: $Sha" + [Environment]::NewLine +
 "- Process exit code: $Exit" + [Environment]::NewLine +
 "- Stage: $stageLabel" + [Environment]::NewLine +
 "- Isolated GCC evidence directory: local only (no raw logs or secrets uploaded)" + $summary +
 [Environment]::NewLine + "**Staging, unsigned, untrusted for production.** A PASS does not prove a same-ZIP upgrade, digital signature, independent provenance, or production readiness. GitHub Required CI remains authoritative."
 $response=& gh api "repos/$Repo/issues/$Pr/comments" -f "body=$body" 2>&1
 if($LASTEXITCODE -ne 0) { throw "Failed to post GCC full CI evidence to PR #$Pr" }
 $posted=$response | Out-String | ConvertFrom-Json
 if(-not $posted.id) { throw 'GitHub did not confirm GCC full evidence.' }
 Write-Output "GCC full PR #$Pr comment $($posted.id): $Status"
}

# A replay after a cursor crash must find an older evidence marker even if
# over 100 newer PR comments exist. Fail closed if pagination is exhausted.
function RequestAlreadyReported([int]$Pr,[long]$Request) {
 $smokeMarker="<!-- foodex-gcc-ci:$Request -->"
 $fullMarker="<!-- foodex-gcc-full:$Request -->"
 for($page=1;$page -le 100;$page++) {
  $batch=@(Api "repos/$Repo/issues/$Pr/comments?per_page=100&sort=created&direction=desc&page=$page")
  foreach($item in $batch) {
   $body=[string]$item.body
   if($body.Contains($smokeMarker) -or $body.Contains($fullMarker)) { return $true }
   if([long]$item.id -le $Request) { return $false }
  }
  if($batch.Count -lt 100) { return $false }
 }
 throw 'GCC report lookup exceeds 100 pages; refusing duplicate execution.'
}
function Process($Comment) {
 if($null -eq $Comment.user -or $Comment.user.login -cne $Owner) { return }
 if([string]$Comment.body -cnotmatch '(?m)^/gcc-ci (run|full|backend|setup|packages) ([a-f0-9]{40})\s*$') { return }
 $profile=$Matches[1]
 $sha=$Matches[2]
 if([string]$Comment.issue_url -notmatch '^https://api\.github\.com/repos/50sols/FOOD/issues/([0-9]+)$') { return }
 $n=[int]$Matches[1]
 $pr=Api "repos/$Repo/pulls/$n"
 if($pr.state -ne 'open' -or $pr.base.ref -cne $Base -or
    [long]$pr.base.repo.id -ne $RepositoryId -or [long]$pr.head.repo.id -ne $RepositoryId -or
    $pr.head.repo.full_name -cne $Repo -or $pr.head.sha -cne $sha) {
  Write-Warning "Rejected non-V2, changed or foreign PR #$n"; return
 }
 if([string]$pr.head.ref -cnotmatch '\A(ci|feat|fix|test|chore|refactor|docs|worker)/[0-9]+-[a-z0-9-]+\z') { return }
 # Crash-safe cursor replay: scan older PR comment pages before re-running.
 if(RequestAlreadyReported -Pr $n -Request ([long]$Comment.id)) {
  Write-Output "GCC request $($Comment.id) already reported; skipping replay."
  return
 }
 $status='FAIL'; $exit=1
 $script:GccLastFailedStage='checkout-or-dispatch'
 try {
  if($profile -ne 'run') {
   $testProfile=if($profile -eq 'full') { 'all' } else { $profile }
   # The request ID identifies evidence, not a reusable checkout. A crash
   # before Report-Full must not replay against a half-fetched .git directory.
   $work=Join-Path $Root ("work\{0}-{1}-{2}" -f $n,$Comment.id,[guid]::NewGuid().ToString("N"))
   New-Item -ItemType Directory -Path $work -Force | Out-Null
   try {
    & git -C $work init -q | Out-Null
    if($LASTEXITCODE -ne 0) { throw 'Full CI git init failed' }
    & git -C $work remote add origin "https://github.com/$Repo.git" | Out-Null
    if($LASTEXITCODE -ne 0) { throw 'Full CI git remote failed' }
    $script:GccLastFailedStage='git-fetch'
    Invoke-BoundedGitFetch -Checkout $work -Pr $n -Request ([long]$Comment.id)
    $script:GccLastFailedStage='checkout'
    $fetched=(& git -C $work rev-parse FETCH_HEAD | Out-String).Trim()
    if($fetched -cne $sha) { throw 'Full CI fetched source SHA mismatch' }
    & git -C $work checkout -q --detach $sha | Out-Null
    if($LASTEXITCODE -ne 0) { throw 'Full CI checkout failed' }
    $exit=Full-CI -Pr $n -Sha $sha -Request ([long]$Comment.id) -Checkout $work -Profile $testProfile
   } finally {
    if(Test-Path $work) { Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue }
   }
  } else {
   $exit=Smoke -Pr $n -Sha $sha -Request ([long]$Comment.id)
  }
  if($exit -eq 0) { $status='PASS' }
 } catch { Write-Warning "Smoke error: $($_.Exception.Message)" }
 if($profile -ne 'run') {
  Report-Full -Pr $n -Sha $sha -Status $status -Exit $exit -Request ([long]$Comment.id) -Profile $testProfile
 } else {
  Report -Pr $n -Sha $sha -Status $status -Exit $exit -Request ([long]$Comment.id)
 }
}
function Poll {
 $mutex=New-Object System.Threading.Mutex($false,'Local\FOODEX_GCC_CI_AGENT')
 $held=$false
 try {
  $held=$mutex.WaitOne(0)
  if(-not $held) { return }
  Initialize
  $cursor=Cursor
  $recent=@(Comments -After $cursor | Sort-Object { [long]$_.id })
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
