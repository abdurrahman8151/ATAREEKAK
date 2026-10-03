#Requires -Version 5.0
<#
.SYNOPSIS
  Runs the tests RELATED to a change - derived from the diff, never the full suite.

.DESCRIPTION
  Implements the "Test selection" + "Run discipline" sections of AGENTS.md end to end:

    1. changed files  - working tree (default: diff vs HEAD + untracked), or -Commit <ref>
                        for a past commit (its own diff vs its parent), or -File <list>.
    2. git grep       - for every changed symbol (class / config key / route name / route
                        URI / artisan signature / migration table):
                            git grep -l -F "<symbol>" -- tests
    3. area floors    - the minimum test paths from the AGENTS.md map, matched on each
                        changed file's path. A floor is a MINIMUM and is never reduced
                        (Money and Identity included).
    4. boundary test  - tests/Feature/AppFuture/BoundaryDependencyTest.php is added when a
                        use line was added/removed, or a file under app/ was added, moved
                        or deleted (rule 3). Absent at that commit -> reported GENUINELY
                        UNVERIFIED, never silently skipped.
    5. static checks  - php -l and pint --test on changed PHP files only, before tests.
    6. DB ping + run  - scripts/db-ping.ps1 first (hard 3 s; FAILS if DB_HOST is not
                        127.0.0.1 or DB_PORT is not 3399). The phpunit run then gets a
                        wall-clock timeout: 180 s for a scoped run, 600 s when whole floor
                        directories are in the selection (-TimeoutSec overrides). A killed
                        run is an INFRASTRUCTURE failure (exit 3), never a test result.
    7. summary only   - prints the summary lines, then failure details (about the last 40
                        lines). Full output goes to a temp log whose path is printed.

  Safety: the scratch gate is re-asserted here, and the phpunit configuration actually used
  is a GENERATED copy of phpunit.xml with every DB env pinned to the scratch values
  force="true". PHPUnit applies force="true" unconditionally (putenv + $_ENV) and runs
  before Laravel's immutable Dotenv, whose writer never overwrites an existing variable -
  so the .env Aiven target can never take over. The repo's phpunit.xml is never modified.
  RefreshDatabase drops every table, so this pin is what makes a run safe.

  Past commits: -Commit <ref> runs in a disposable sandbox under $env:TEMP - git worktree
  + a REAL copy of vendor (never a junction: deleting through a junction would destroy the
  root vendor) + composer dump-autoload + .env + generated config. The working tree, app
  code and phpunit.xml of the repo are NEVER touched. The sandbox is deleted afterwards;
  -KeepSandbox keeps it.

  If a changed symbol has no referencing test anywhere in tests/, it is listed under
  GENUINELY UNVERIFIED (rule 4): write a targeted test under tests/Feature/Review/ or keep
  it listed there. Never skipped silently.

  The test run executes in-process via scripts/test-related-runner.ps1 (System.Diagnostics
  .Process + WaitForExit(ms)/Kill): in this harness Start-Job workers cannot spawn a
  redirected child at all, so a job-based runner hangs at startup with no output - the
  .NET API in the main session works and still enforces the wall-clock budget.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts/test-related.ps1
  # related tests for the current working-tree diff

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts/test-related.ps1 -Commit d776c1f
  # related tests for what past commit d776c1f changed, executed from that commit's own tree

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts/test-related.ps1 -Commit c785f73 -DryRun
  # show the selection only; no ping, no test run

.NOTES
  Exit codes:
    0  selected tests passed (or nothing applicable to run)
    1  a selected test failed, or the static gate failed on changed files
    2  environment violation / missing prerequisites (incl. scratch gate refusal)
    3  wall-clock timeout or DB ping failure = INFRASTRUCTURE (a hung run proves nothing)
#>
[CmdletBinding()]
param(
  [string]$Commit = '',
  [string[]]$File = @(),
  [int]$TimeoutSec = 0,           # 0 = automatic: 180 s scoped, 600 s with floor dirs
  [switch]$DryRun = $false,       # selection + static checks only; no ping, no phpunit run
  [switch]$SkipStatic = $false,   # skip php -l / pint --test
  [switch]$SkipDbPing = $false,   # debug only: skip the guarded scratch DB ping
  [switch]$KeepSandbox = $false   # keep the -Commit sandbox under TEMP
)

# git / composer / robocopy write informational text to stderr. With ErrorActionPreference
# 'Stop' PowerShell turns native-command stderr into a terminating error, which would kill
# the script on success paths ("Preparing worktree..."). So: 'Continue' globally, and every
# external call decides by its explicit exit-code check below (code paths that must pass
# are asserted, not assumed).
$ErrorActionPreference = 'Continue'

$scriptDir = if ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }
$RepoRoot = Split-Path -Parent $scriptDir
. (Join-Path $scriptDir 'scratch-env.ps1')

function Fail([string]$Msg, [int]$Code = 2) {
  Write-Output ('TEST-RELATED FAIL: ' + $Msg)
  # never leave a half-built sandbox behind
  if ($script:sandbox) { try { Cleanup-Sandbox } catch { } }
  exit $Code
}

function Invoke-Git {
  param([string[]]$GitArgs, [string]$InDir)
  $prev = Push-Location $InDir
  try {
    $out = & git -c core.quotepath=off @GitArgs 2>&1
    $code = $LASTEXITCODE
  } finally { Pop-Location }
  $lines = @()
  foreach ($l in $out) { if ($l -is [string]) { $lines += $l } else { $lines += ($l.ToString()) } }
  return @{ Code = $code; Lines = $lines; Text = ($lines -join "`n") }
}

function ToRel([string]$Path) {
  $p = ($Path -replace '\\', '/')
  $rootFwd = ($RepoRoot -replace '\\', '/')
  if ($p.StartsWith($rootFwd + '/')) { $p = $p.Substring($rootFwd.Length + 1) }
  return $p.TrimStart('/')
}

# ------------------------------------------------------------------- 0. prerequisites
foreach ($need in @('phpunit.xml', 'composer.json', 'tests', 'vendor')) {
  if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot $need))) {
    Fail "repo not provisioned: '$need' missing under $RepoRoot"
  }
}
foreach ($side in @('db-ping.ps1', 'scratch-env.ps1', 'test-related-runner.ps1')) {
  if (-not (Test-Path -LiteralPath (Join-Path $scriptDir $side))) { Fail "scripts/$side missing" }
}

$boundaryRel = 'tests/Feature/AppFuture/BoundaryDependencyTest.php'

# Area floors, transcribed from the AGENTS.md map (changed-path pattern -> minimum paths).
$AreaFloors = [ordered]@{
  'ride'          = @{ Pattern = 'Services/Ride/|RideRepository|Domain/Score/|RideController|RideResource|GeoPoint'
                       Paths   = @('tests/Feature/Rides', 'tests/Feature/Bookings', 'tests/Unit/Domain') }
  'money'         = @{ Pattern = 'Services/Wallet/|Services/Payment/|Domain/Payment/|[Ww]allet|escrow|[Ll]edger|[Pp]ayment'
                       Paths   = @('tests/Feature/Wallet', 'tests/Feature/Payment', 'tests/Unit/Domain') }
  'identity'      = @{ Pattern = 'Services/Auth/|Services/Staff/|Services/Verification/|Services/Otp/|app/Http/Middleware/|(^|/)Otp|Employee|Jwt|Login|[Pp]assword|Token'
                       Paths   = @('tests/Feature/Auth', 'tests/Feature/Staff', 'tests/Feature/Otp', 'tests/Feature/Security', 'tests/Unit/Middleware') }
  'communication' = @{ Pattern = 'Notification|Services/Chat/|Listeners/|Events/|Jobs/|(^|/)Push'
                       Paths   = @('tests/Feature/Notifications') }
  'console'       = @{ Pattern = 'Admin'
                       Paths   = @('tests/Feature/Admin') }
  'routes'        = @{ Pattern = '^routes/|RateLimit|Throttle'
                       Paths   = @('tests/Feature/RateLimiting') }
  'config'        = @{ Pattern = '^config/|app/Providers/|app/Exceptions/'
                       Paths   = @('tests/Feature/Config', 'tests/Unit/Providers') }
}

# --------------------------------------------------------- 1. change set + diff text
$script:appStructural = $false
$script:changed = @()

function Add-Changed {
  param([string]$Path, [string]$Status)
  $p = ToRel $Path
  if (-not $p) { return }
  # AGENTS.md rule 3: boundary test when a file under app/ was ADDED, MOVED or DELETED
  if ($p -match '^app/' -and $Status -match '^(A|R|C|T|D)') { $script:appStructural = $true }
  $script:changed += [pscustomobject]@{ Path = $p; Status = $Status }
}

$runDir = $RepoRoot
$sha = ''
$subject = ''
$diffText = ''

if ($Commit) {
  $subject = 'commit ' + $Commit
  $rev = Invoke-Git @('rev-parse', '--verify', ($Commit + '^{commit}')) $RepoRoot
  if ($rev.Code -ne 0) { Fail "unknown commit '$Commit'" }
  $sha = $rev.Lines[0].Trim()
  $show = Invoke-Git @('show', '-M', '--name-status', '--format=%s', $sha) $RepoRoot
  if ($show.Code -ne 0) { Fail "git show failed for $sha" }
  Write-Output ('COMMIT: ' + $sha.Substring(0, 8) + '  ' + $show.Lines[0])
  foreach ($line in ($show.Lines | Select-Object -Skip 1)) {
    $f = ($line -split "`t")
    Add-Changed $f[-1] $f[0]
  }
  $diffText = (Invoke-Git @('show', '--format=', $sha) $RepoRoot).Text
}
elseif ($File.Count -gt 0) {
  $subject = 'explicit file list'
  foreach ($p in $File) {
    $rel = ToRel $p
    $full = Join-Path $RepoRoot ($rel -replace '/', '\')
    if (-not (Test-Path -LiteralPath $full)) { Add-Changed $rel 'D'; continue }
    $dt = Invoke-Git @('diff', '--unified=3', 'HEAD', '--', $rel) $RepoRoot
    if ($dt.Text -match '\S') {
      Add-Changed $rel 'M'
      $diffText += "`n" + $dt.Text
    } else {
      Add-Changed $rel 'A'
      $diffText += ("`n+++ b/" + $rel + "`n")
      $diffText += (((Get-Content -LiteralPath $full -EA SilentlyContinue) | ForEach-Object { '+' + $_ }) -join "`n")
    }
  }
}
else {
  $subject = 'working tree vs HEAD'
  $tracked = @((Invoke-Git @('diff', '--name-only', 'HEAD') $RepoRoot).Lines | Where-Object { $_ })
  $deleted = @((Invoke-Git @('diff', '--name-only', '--diff-filter=D', 'HEAD') $RepoRoot).Lines | Where-Object { $_ })
  $untracked = @((Invoke-Git @('ls-files', '--others', '--exclude-standard') $RepoRoot).Lines | Where-Object { $_ })
  foreach ($p in (@($tracked + $untracked) | Select-Object -Unique)) {
    $st = 'M'
    if ($untracked -contains $p) { $st = 'A' }
    if ($deleted -contains $p) { $st = 'D' }
    Add-Changed $p $st
  }
  $diffText = (Invoke-Git @('diff', '--unified=3', 'HEAD') $RepoRoot).Text
  foreach ($u in ($untracked | Where-Object { $_ -match '\.php$' })) {
    $full = Join-Path $RepoRoot ($u -replace '/', '\')
    if (Test-Path -LiteralPath $full -PathType Leaf) {
      $diffText += ("`n+++ b/" + $u + "`n")
      $diffText += (((Get-Content -LiteralPath $full -EA SilentlyContinue) | ForEach-Object { '+' + $_ }) -join "`n")
    }
  }
}

if ($script:changed.Count -eq 0) {
  Write-Output 'CHANGE SET: empty. Nothing changed; nothing to run (and never the full suite).'
  exit 0
}
$phpFiles = @($script:changed | Where-Object { $_.Path -match '\.php$' })
Write-Output ('CHANGE SET (' + $subject + '): ' + $script:changed.Count + ' file(s), ' + $phpFiles.Count + ' PHP')
foreach ($c in $script:changed) { Write-Output ('  ' + $c.Status + '  ' + $c.Path) }

# ------------------------------------------- 1b. scratch gate + DB ping (before any run)
$envInfo = Read-ScratchEnv -RepoRoot $RepoRoot
if ($envInfo.Missing.Count -gt 0) {
  Fail ('scratch DB settings not found (missing: ' + ($envInfo.Missing -join ', ') +
        '); expected the shell environment or ' + $envInfo.SourceFile)
}
$gate = @(Assert-ScratchDbGate -Values $envInfo.Values)
if ($gate.Count -gt 0) { Fail ($gate -join ' | ') }

if (-not $DryRun -and -not $SkipDbPing) {
  # own process: db-ping reports through exit; infrastructure failure stops here.
  $pingOut = & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $scriptDir 'db-ping.ps1') 2>&1
  $pingCode = $LASTEXITCODE
  Write-Output ('DB PING: ' + (($pingOut | ForEach-Object { $_.ToString() }) -join ' '))
  if ($pingCode -ne 0) {
    Fail ('scratch DB ping failed (exit ' + $pingCode +
          '). Protocol: restart it once per AGENTS.local.md, re-ping once, then stop and report - do not wait. ' +
          'Infrastructure, not a test result; nothing was run.') 3
  }
}

# --------------------------------------------- 1c. sandbox for a past commit (-Commit)
$sandbox = $null

function Cleanup-Sandbox {
  if (-not $script:sandbox) { return }
  $null = Invoke-Git @('worktree', 'remove', '--force', (Join-Path $script:sandbox 'tree')) $RepoRoot
  $null = Invoke-Git @('worktree', 'prune') $RepoRoot
  if (Test-Path -LiteralPath $script:sandbox) {
    Remove-Item -LiteralPath $script:sandbox -Recurse -Force -EA SilentlyContinue
  }
  # prove the root install was never touched by the sandbox vendor copy
  if (Test-Path -LiteralPath (Join-Path $RepoRoot 'vendor\composer\autoload_real.php')) {
    Write-Output 'SANDBOX: removed; root vendor intact.'
  } else {
    Write-Output 'SANDBOX WARNING: root vendor/composer is MISSING - stop and restore it before any other run.'
  }
}

if ($Commit) {
  $stamp = (Get-Date).ToString('yyyyMMddHHmmss')
  $sandbox = Join-Path $env:TEMP ('test-related-' + $sha.Substring(0, 8) + '-' + $stamp)
  $null = New-Item -ItemType Directory -Force -Path $sandbox
  $wt = Join-Path $sandbox 'tree'
  $w = Invoke-Git @('worktree', 'add', '--detach', $wt, $sha) $RepoRoot
  if ($w.Code -ne 0) { Fail "git worktree add failed: $($w.Text)" }
  $runDir = $wt

  # vendor: a REAL copy of the root vendor. Never a junction - a recursive delete through a
  # junction follows it and destroys the root install, and dump-autoload would regenerate
  # the ROOT autoloader. robocopy /MIR /MT of 14k files is a few seconds.
  $t0 = Get-Date
  $null = robocopy (Join-Path $RepoRoot 'vendor') (Join-Path $wt 'vendor') /MIR /NFL /NDL /NJH /NJS /R:1 /W:1 /MT:16
  $rc = $LASTEXITCODE
  if ($rc -ge 8) { Fail ('robocopy vendor copy failed (exit ' + $rc + ')') }
  Write-Output ('SANDBOX: vendor copied in ' + [int]((Get-Date) - $t0).TotalSeconds + 's')

  foreach ($d in @('storage/framework/cache/data', 'storage/framework/sessions',
                   'storage/framework/views', 'storage/framework/testing', 'storage/logs',
                   'bootstrap/cache')) {
    $null = New-Item -ItemType Directory -Force -Path (Join-Path $wt ($d -replace '/', '\'))
  }
  $rootEnv = Join-Path $RepoRoot '.env'
  if (Test-Path -LiteralPath $rootEnv) { Copy-Item -Force $rootEnv (Join-Path $wt '.env') }

  $prev = Push-Location $wt
  try {
    $t0 = Get-Date
    $da = & composer dump-autoload --no-scripts --no-interaction 2>&1
    $daCode = $LASTEXITCODE
  } finally { Pop-Location }
  if ($daCode -ne 0) { Fail ('composer dump-autoload failed in the sandbox: ' + (($da | Select-Object -Last 5) -join ' ')) }
  Write-Output ('SANDBOX: autoloader dumped in ' + [int]((Get-Date) - $t0).TotalSeconds + 's -> ' + $wt)

  if (-not (Test-Path -LiteralPath (Join-Path $wt 'tests'))) {
    Write-Output 'SANDBOX: this commit has no tests/ directory; selection will be empty.'
  }
  Write-Output ('SANDBOX: ' + $(if ($KeepSandbox) { 'kept (-KeepSandbox)' } else { 'disposable' }))
}

# --------------------- 1d. generated scratch-pinned phpunit config (phpunit.xml untouched)
$srcXml = Join-Path $runDir 'phpunit.xml'
if (-not (Test-Path -LiteralPath $srcXml)) { Fail "no phpunit.xml in run tree: $srcXml" }
$cfgDir = if ($sandbox) { $sandbox } else { $env:TEMP }
$tag = $(if ($sha) { $sha.Substring(0, 8) } else { 'tree' }) + '.' + (Get-Date).ToString('yyyyMMddHHmmss')
$scratchXmlPath = Write-ScratchPhpunitConfig -SourceXmlPath $srcXml -Values $envInfo.Values `
                     -OutDir $cfgDir -RunDir $runDir -Tag $tag
Write-Output ('SCRATCH PIN: ' + $scratchXmlPath + ' (DB forced to ' + $envInfo.Values['DB_HOST'] + ':' +
              $envInfo.Values['DB_PORT'] + ' db=' + $envInfo.Values['DB_DATABASE'] + ', APP_ENV=testing)')

# ------------------------------------------------------------ 2. symbols + git grep
function Get-ChangedSymbols {
  param([object[]]$Files, [string]$Diff)
  $syms = New-Object System.Collections.Generic.HashSet[string]
  foreach ($f in $Files) {
    $p = $f.Path
    if ($p -match '^routes/' -or $p -match '^database/migrations/') { continue }  # covered by floors / table names
    if ($p -match '([A-Za-z0-9_]+)\.php$') { $null = $syms.Add($Matches[1]) }
  }
  foreach ($m in [regex]::Matches($Diff, '(?m)^[ +\-]?\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+([A-Za-z][A-Za-z0-9_]*)')) {
    $null = $syms.Add($m.Groups[1].Value)
  }
  foreach ($m in [regex]::Matches($Diff, "env\(\s*'([A-Z][A-Z0-9_]{2,})'")) { $null = $syms.Add($m.Groups[1].Value) }
  foreach ($f in $Files) {
    if ($f.Path -match '^config/(.+)\.php$') { $null = $syms.Add(($Matches[1] -replace '/', '.')) }
  }
  foreach ($m in [regex]::Matches($Diff, "->name\(\s*'([A-Za-z0-9_\.\-]{3,})'")) { $null = $syms.Add($m.Groups[1].Value) }
  foreach ($m in [regex]::Matches($Diff, "Route::(?:get|post|put|patch|delete|options|any|match)\(\s*'([^']+)'")) {
    $uri = ($m.Groups[1].Value -replace '\{[^}]*\}', '').Trim('/')
    if ($uri.Length -gt 3) { $null = $syms.Add($uri) }
  }
  foreach ($m in [regex]::Matches($Diff, "signature\s*=\s*'([A-Za-z0-9_:\-]+)")) { $null = $syms.Add($m.Groups[1].Value) }
  foreach ($m in [regex]::Matches($Diff, "Schema::(?:create|table|drop)\('([A-Za-z0-9_]+)'")) { $null = $syms.Add($m.Groups[1].Value) }
  return @($syms)
}

$symbols = Get-ChangedSymbols -Files $script:changed -Diff $diffText
if ($symbols.Count -gt 40) {
  Write-Output ('  (symbols capped at 40, was ' + $symbols.Count + ')')
  $symbols = @($symbols | Select-Object -First 40)
}
Write-Output ('SYMBOLS (' + $symbols.Count + '): ' + ($symbols -join ', '))

$grepTestFiles = @{}
$unverified = New-Object System.Collections.Generic.HashSet[string]
foreach ($s in $symbols) {
  $g = Invoke-Git @('grep', '-l', '-F', $s, '--', 'tests') $runDir
  $hits = @($g.Lines | Where-Object { $_ -match '\.php$' })
  if ($hits.Count -eq 0) { $null = $unverified.Add($s) }
  foreach ($h in $hits) {
    $rel = ToRel $h
    # tests/Support/** are shared traits/helpers, not runnable test classes
    if ($rel -match '^tests/Support/') { continue }
    $grepTestFiles[$rel] = $true
  }
}
Write-Output ('GIT GREP: ' + $grepTestFiles.Count + ' referencing test file(s); ' +
              $unverified.Count + ' symbol(s) with no referencing test')

# ------------------------------------------------------------------ 3. area floors
$floorSet = New-Object System.Collections.Generic.HashSet[string]
$matched = New-Object System.Collections.Generic.HashSet[string]
foreach ($f in $script:changed) {
  foreach ($k in $AreaFloors.Keys) {
    if ($f.Path -match $AreaFloors[$k].Pattern) {
      $null = $matched.Add($k)
      foreach ($p in $AreaFloors[$k].Paths) { $null = $floorSet.Add($p) }
    }
  }
}
$floorDirs = @()
foreach ($p in ($floorSet | Sort-Object)) {
  if (Test-Path -LiteralPath (Join-Path $runDir ($p -replace '/', '\'))) { $floorDirs += $p }
}
Write-Output ('AREA FLOORS: ' + $(if ($matched.Count) { (@($matched | Sort-Object) -join ', ') } else { '(none matched; grep decides)' }))
Write-Output ('FLOOR PATHS (' + $floorDirs.Count + '): ' + ($floorDirs -join ', '))

# ------------------------------------------------------------ 4. boundary test (rule 3)
$useChanged = [bool]($diffText -match "(?m)^[+-]use [A-Za-z0-9_\\\\]+")
$reasons = @()
if ($useChanged) { $reasons += 'use line added/removed' }
if ($script:appStructural) { $reasons += 'file under app/ added/moved/deleted' }
$runBoundary = ($reasons.Count -gt 0)
$boundaryPresent = Test-Path -LiteralPath (Join-Path $runDir ($boundaryRel -replace '/', '\'))
if ($runBoundary -and -not $boundaryPresent) {
  Write-Output ('BOUNDARY: needed (' + ($reasons -join ', ') + ') but ' + $boundaryRel +
                ' is absent at this commit -> GENUINELY UNVERIFIED')
  $runBoundary = $false
}
if ($runBoundary) { Write-Output ('BOUNDARY: included (' + ($reasons -join ', ') + ')') }

# ------------------------------------------------------------- assemble selection
$changedTestFiles = @($script:changed | Where-Object { $_.Path -match '^tests/.*\.php$' -and $_.Status -ne 'D' } | ForEach-Object { $_.Path })
$sel = New-Object System.Collections.Generic.HashSet[string]
foreach ($x in $changedTestFiles) { $null = $sel.Add($x) }
foreach ($x in $grepTestFiles.Keys) { $null = $sel.Add($x) }
if ($runBoundary) { $null = $sel.Add($boundaryRel) }
foreach ($x in $floorDirs) { $null = $sel.Add($x) }   # floors are a minimum; expanded to files

$script:selFiles = New-Object System.Collections.Generic.HashSet[string]
$script:selFrom = @{}
# reason priority: a more specific reason wins over a broader one in the report
$whyRank = @{ 'changed-test' = 4; 'boundary' = 3; 'grep' = 2; 'floor' = 1 }

function Add-SelFile {
  param([string]$Rel, [string]$Why)
  $full = Join-Path $runDir ($Rel -replace '/', '\')
  if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { return }
  if (-not $script:selFiles.Contains($Rel)) {
    $null = $script:selFiles.Add($Rel)
    $script:selFrom[$Rel] = $Why
  } elseif ($whyRank[$Why] -gt $whyRank[$script:selFrom[$Rel]]) {
    $script:selFrom[$Rel] = $Why
  }
}

foreach ($s in $sel) {
  $full = Join-Path $runDir ($s -replace '/', '\')
  if (Test-Path -LiteralPath $full -PathType Container) {
    foreach ($fi in (Get-ChildItem -LiteralPath $full -Filter *.php -File -Recurse)) {
      # relativise against the RUN tree (a sandbox lives outside the repo root)
      $rel = $fi.FullName.Substring($runDir.Length + 1) -replace '\\', '/'
      Add-SelFile $rel 'floor'
    }
  } else {
    $why = 'grep'
    if ($changedTestFiles -contains $s) { $why = 'changed-test' }
    if ($s -eq $boundaryRel) { $why = 'boundary' }
    Add-SelFile $s $why
  }
}
$selPaths = @($script:selFiles | Sort-Object)
Write-Output ('SELECTION (' + $selPaths.Count + ' file(s) - scoped, NOT the full suite):')
foreach ($s in $selPaths) { Write-Output ('  [' + $script:selFrom[$s] + '] ' + $s) }
if ($selPaths.Count -eq 0) {
  Write-Output 'SELECTION EMPTY: no referencing tests and no area floor matched; nothing applicable to run.'
}
if ($unverified.Count -gt 0) {
  Write-Output ('GENUINELY UNVERIFIED (no test under tests/ references these changed symbols): ' +
                (@($unverified | Sort-Object) -join ', '))
  Write-Output '  -> AGENTS.md rule 4: write a targeted test in tests/Feature/Review/, or keep it listed here.'
}

# ------------------------------------------------------------ 5. static checks first
if (-not $SkipStatic -and $phpFiles.Count -gt 0) {
  $exist = @()
  foreach ($f in $phpFiles) {
    if ($f.Status -eq 'D') { continue }
    $full = Join-Path $runDir ($f.Path -replace '/', '\')
    if (Test-Path -LiteralPath $full -PathType Leaf) { $exist += $full }
  }
  foreach ($full in $exist) {
    $r = & php -l $full 2>&1
    if ($LASTEXITCODE -ne 0) {
      Write-Output ('STATIC: php -l FAILED for ' + $full + ':')
      foreach ($l in ($r | ForEach-Object { $_.ToString() })) { Write-Output ('  ' + $l) }
      Fail 'syntax error in a changed file' 1
    }
  }
  if ($exist.Count -gt 0) {
    $prev = Push-Location $runDir
    try {
      # pint takes paths POSITIONALLY; it has no --paths/--colors options
      $pi = & php vendor\bin\pint --test ($exist | ForEach-Object { $_ }) 2>&1
      $pc = $LASTEXITCODE
      $ptxt = (($pi | ForEach-Object { $_.ToString() }) -join "`n")
    } finally { Pop-Location }
    if ($pc -ne 0) {
      Write-Output 'STATIC: pint --test reported violations on changed files:'
      foreach ($l in (($ptxt -split "`n") | Select-Object -Last 15)) { Write-Output ('  ' + $l) }
      Fail 'style gate failed on changed files (fix with: php vendor/bin/pint <files>)' 1
    }
  }
  Write-Output ('STATIC: php -l + pint clean on ' + $exist.Count + ' changed PHP file(s)')
}

if ($DryRun) {
  Write-Output 'DRY-RUN: stopping before the phpunit run.'
  Cleanup-Sandbox
  exit 0
}

# ------------------------------------------------------------- 6. run with hard timeout
if ($selPaths.Count -eq 0) {
  Write-Output 'RESULT: nothing to run (selection empty). Not running the full suite.'
  Cleanup-Sandbox
  exit 0
}

$isLarger = ($floorDirs.Count -gt 0)
if ($TimeoutSec -le 0) { $TimeoutSec = if ($isLarger) { 600 } else { 180 } }

$phpunitPhp = Join-Path $runDir 'vendor\phpunit\phpunit\phpunit'
if (-not (Test-Path -LiteralPath $phpunitPhp)) { Fail 'vendor/phpunit/phpunit/phpunit not found in the run tree' }

$argList = @(
  ($phpunitPhp -replace '\\', '/'),
  '--configuration', ($scratchXmlPath -replace '\\', '/'),
  '--no-coverage', '--colors=never', '--do-not-cache-result'
)
foreach ($s in $selPaths) {
  $argList += ((Join-Path $runDir ($s -replace '/', '\')) -replace '\\', '/')
}

$logFile = Join-Path $env:TEMP ('test-related-run.' + (Get-Date).ToString('yyyyMMdd-HHmmss') + '.' + $PID + '.log')
$refName = if ($Commit) { $sha.Substring(0, 8) } else { 'working-tree' }
Write-Output ('RUN: phpunit scoped on ' + $refName + ' (' + $selPaths.Count + ' file(s)), wall-clock timeout ' +
              $TimeoutSec + 's; full output -> ' + $logFile)

$dbEnvArgs = @()
foreach ($k in @('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_REPLICA_HOST', 'DB_REPLICA_PORT')) {
  if ($envInfo.Values.ContainsKey($k)) { $dbEnvArgs += ($k + '=' + $envInfo.Values[$k]) }
}

# in-process .NET child + WaitForExit(ms)/Kill (see scripts/test-related-runner.ps1 for
# why Start-Job/Start-Process are unusable in this harness)
. (Join-Path $scriptDir 'test-related-runner.ps1')
$res = Invoke-ScopedPhpunit -RunDir $runDir -ArgList $argList -LogFile $logFile `
        -TimeoutMs ($TimeoutSec * 1000) -EnvPairs $dbEnvArgs
Write-Output ('RUNNER: ' + $res.Kind + ' code=' + $res.Code + ' wallMs=' + $res.WallMs +
              $(if ($res.Message) { ' msg=' + $res.Message } else { '' }))

# ------------------------------------------------------------- 7. summary only
$markerLine = ''
$keep = @()
if (Test-Path -LiteralPath $logFile) {
  $mm = Select-String -Path $logFile -Pattern '^(KILLED\.|EXIT\.|START-ERROR\.)' -EA SilentlyContinue | Select-Object -Last 1
  if ($mm) { $markerLine = $mm.Line }
  $keep = @(Get-Content -LiteralPath $logFile -Encoding UTF8 | Where-Object { $_ -notmatch '^(KILLED\.|EXIT\.|START-ERROR\.)' })
}

$summ = @($keep | Select-String -Pattern '^(OK \(|FAILURES!|ERRORS!|WARNINGS!|Tests: |Time: |There (was|were) \d)' | ForEach-Object { $_.Line })
Write-Output 'SUMMARY:'
if ($summ.Count -gt 0) {
  foreach ($l in ($summ | Select-Object -Last 4)) { Write-Output ('  ' + $l) }
} else {
  Write-Output ('  (no phpunit summary line found; runner marker: ' + $(if ($markerLine) { $markerLine } else { 'none' }) + ')')
}

if ($markerLine -match '^KILLED\.(\d+)') {
  Write-Output ('INFRASTRUCTURE FAILURE: the run exceeded its ' + ([int]$Matches[1] / 1000) +
                ' s wall-clock budget and was killed. It proves nothing - re-run the same scoped ' +
                'selection, and check the DB/process instead of waiting. Full output: ' + $logFile)
  Cleanup-Sandbox
  exit 3
}
if ($markerLine -match '^START-ERROR') {
  Write-Output ('INFRASTRUCTURE FAILURE: the phpunit child could not be started: ' + $markerLine +
                ' (log: ' + $logFile + ')')
  Cleanup-Sandbox
  exit 3
}

$phpunitExit = -1
if ($markerLine -match '^EXIT\.(-?\d+)$') { $phpunitExit = [int]$Matches[1] }
$sawDefect = @($keep | Select-String -Pattern '^(FAILURES!|ERRORS!)').Count -gt 0

if ($phpunitExit -ne 0 -or $sawDefect) {
  Write-Output ('FAILURE DETAILS (last ' + [Math]::Min(40, $keep.Count) + ' lines; full output: ' + $logFile + '):')
  foreach ($l in ($keep | Select-Object -Last 40)) { Write-Output ('  ' + $l) }
  Write-Output ('PHPUNIT EXIT: ' + $(if ($phpunitExit -ge 0) { $phpunitExit } else { 'unknown' }))
  Cleanup-Sandbox
  exit 1
}

Write-Output ('RESULT: PASS (' + $refName + ', ' + $selPaths.Count + ' scoped file(s)); full output: ' + $logFile)
Cleanup-Sandbox
exit 0
