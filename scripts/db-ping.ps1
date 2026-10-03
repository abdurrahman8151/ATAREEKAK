#Requires -Version 5.0
<#
.SYNOPSIS
  Guarded 3-second ping of the throwaway scratch MySQL the test suite must use.

.DESCRIPTION
  Implements the "Run discipline" rule from AGENTS.md plus the scratch settings from
  AGENTS.local.md. Env resolution and the safety gate live once in
  scripts/scratch-env.ps1 (shared with scripts/test-related.ps1).

    1. Environment: shell values win; missing ones are filled from the
       $env:... assignments in AGENTS.local.md (gitignored - no credentials in this script).
    2. SAFETY GATE (hard FAIL, never a warning): DB_HOST must be 127.0.0.1 and DB_PORT
       must be 3399; DB_REPLICA_HOST/PORT, when present, must be that same pair.
       Any violation means a phpunit/RefreshDatabase run could drop a REAL database.
    3. TCP ping against the validated 127.0.0.1:3399, with a 3-second wall-clock budget,
       then reads the MySQL greeting so "port open but not MySQL" also fails.

  This script never starts mysqld and never waits. On failure the protocol
  (AGENTS.md + AGENTS.local.md) is: ask the operator to restart the scratch MySQL once,
  re-ping once, then stop and report. Do not wait.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts/db-ping.ps1

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File scripts\db-ping.ps1 -ProbeHost 127.0.0.1 -ProbePort 1 -TimeoutSec 2
  # SELF-TEST ONLY - exercises the connectivity branches against a closed port, labelled
  # PROBE. It deliberately skips the scratch gate, and test-related.ps1 never uses it, so
  # it cannot retarget a real run.

.NOTES
  Exit codes:
    0  scratch env valid AND MySQL greeting received
    1  connectivity failure (refused / timeout / not a MySQL listener)
    2  environment violation (DB_HOST/DB_PORT not the scratch values, or settings not found)
  The password is never printed.
#>
[CmdletBinding()]
param(
  [string]$RepoRoot = '',
  [int]$TimeoutSec = 3,
  [string]$ProbeHost = '',
  [int]$ProbePort = 0
)

$ErrorActionPreference = 'Stop'

if (-not $RepoRoot) {
  if ($PSScriptRoot) { $RepoRoot = Split-Path -Parent $PSScriptRoot } else { $RepoRoot = (Get-Location).Path }
}
. (Join-Path $PSScriptRoot 'scratch-env.ps1')

function Fail([int]$Code, [string]$Msg, [string]$Label = 'DB-PING') {
  Write-Output ($Label + ' FAIL: ' + $Msg)
  exit $Code
}

# ------------------------------------------------------------------ ping primitive
function Test-MySqlGreeting {
  param([string]$Host2, [int]$Port, [int]$BudgetSec, [string]$Label = 'DB-PING')

  $budgetMs = [int]($BudgetSec * 1000)
  $sw = [System.Diagnostics.Stopwatch]::StartNew()
  $client = New-Object System.Net.Sockets.TcpClient
  try {
    $iar = $client.BeginConnect($Host2, $Port, $null, $null)
    if (-not $iar.AsyncWaitHandle.WaitOne($budgetMs)) {
      Fail 1 ('no TCP connection to ' + $Host2 + ':' + $Port + ' within ' + $BudgetSec +
              's. Do NOT wait: restart the scratch MySQL once (AGENTS.local.md), re-ping once, then stop and report.') $Label
    }
    $client.EndConnect($iar)

    $ns = $client.GetStream()
    $left = $budgetMs - [int]$sw.ElapsedMilliseconds
    if ($left -lt 200) { $left = 200 }
    $ns.ReadTimeout = $left

    $hdr = New-Object byte[] 4
    $got = 0
    while ($got -lt 4) {
      $n = $ns.Read($hdr, $got, 4 - $got)
      if ($n -le 0) { Fail 1 ('connection closed while reading the MySQL greeting on ' + $Host2 + ':' + $Port) $Label }
      $got += $n
    }

    $proto = -1
    if ($hdr[3] -eq 0) {  # the server greeting is sequence 0x00
      $b = New-Object byte[] 1
      $n = $ns.Read($b, 0, 1)
      if ($n -gt 0) { $proto = [int]$b[0] }
    }
    if ($proto -ne 9 -and $proto -ne 10) {
      Fail 1 ('port ' + $Port + ' is open but sent no MySQL greeting (protocol byte ' + $proto +
              ', packet seq ' + $hdr[3] + ') - something else is listening; treat as infrastructure, stop and report.') $Label
    }
    return @{ Proto = $proto; ElapsedMs = [int]$sw.ElapsedMilliseconds }
  }
  catch [System.Net.Sockets.SocketException] {
    Fail 1 ('connect error to ' + $Host2 + ':' + $Port + ': ' + $_.Exception.Message +
            ' - restart the scratch MySQL once (AGENTS.local.md), re-ping once, then stop and report. Do not wait.') $Label
  }
  catch {
    # an open port that is not MySQL usually dies here (read timeout / reset / non-greeting)
    Fail 1 ('connected to ' + $Host2 + ':' + $Port + ' but did not get a MySQL greeting: ' +
            $_.Exception.Message + ' - something else is listening; treat as infrastructure, stop and report.') $Label
  }
  finally {
    $client.Close()
  }
}

# ---------------------------------------------------- self-test mode (skip the gate)
if ($ProbeHost) {
  if ($ProbePort -le 0) { Fail 2 '-ProbePort is required with -ProbeHost' 'PROBE' }
  $r = Test-MySqlGreeting -Host2 $ProbeHost -Port $ProbePort -BudgetSec $TimeoutSec -Label 'PROBE'
  Write-Output ('PROBE OK ' + $ProbeHost + ':' + $ProbePort + ' mysql-protocol=' + $r.Proto +
                ' elapsedMs=' + $r.ElapsedMs + ' budgetSec=' + $TimeoutSec)
  exit 0
}

# --- 1 + 2. resolve env and run the safety gate -----------------------------------
$envInfo = Read-ScratchEnv -RepoRoot $RepoRoot
if ($envInfo.Missing.Count -gt 0) {
  Fail 2 ('scratch DB settings not found (missing: ' + ($envInfo.Missing -join ', ') +
          '); expected them in the shell environment or in ' + $envInfo.SourceFile)
}
$violations = @(Assert-ScratchDbGate -Values $envInfo.Values)
if ($violations.Count -gt 0) {
  Fail 2 ($violations -join ' | ')
}

$h = $envInfo.Values['DB_HOST']
$p = [int]$envInfo.Values['DB_PORT']

# --- 3. TCP ping + MySQL greeting read, hard 3 s budget ----------------------------
$r = Test-MySqlGreeting -Host2 $h -Port $p -BudgetSec $TimeoutSec
Write-Output ('DB-PING OK host=' + $h + ' port=' + $p +
              ' db=' + $envInfo.Values['DB_DATABASE'] + ' user=' + $envInfo.Values['DB_USERNAME'] +
              ' mysql-protocol=' + $r.Proto + ' elapsedMs=' + $r.ElapsedMs + ' budgetSec=' + $TimeoutSec)
exit 0
