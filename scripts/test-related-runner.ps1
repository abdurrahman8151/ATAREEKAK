#Requires -Version 5.0
<#
.SYNOPSIS
  test-related.ps1 runner - one scoped phpunit run under a hard wall-clock timeout.

.DESCRIPTION
  Dot-sourced by scripts/test-related.ps1; call Invoke-ScopedPhpunit. Not a standalone
  script (it defines a function and returns a result; it never exits the caller).

  Why System.Diagnostics.Process instead of Start-Process / Start-Job: in this harness the
  Start-Job worker cannot spawn a child process with redirected stdio (both Start-Process
  -NoNewWindow and a cmd /c wrapper hang at startup with CPU ~0). The .NET process API
  works in the main session, so the run stays in-process and the timeout is a plain
  WaitForExit(ms) + Kill.

  Contract:
    Invoke-ScopedPhpunit -RunDir <tree> -ArgList <argv without the php exe>
                         -LogFile <path> -TimeoutMs <wall clock>
                         [-EnvPairs @('NAME=value', ...)]

    Writes ALL child output (stdout+stderr merged) to -LogFile, then returns:
      @{ Kind = 'EXIT' | 'KILLED' | 'START-ERROR'; Code = <int>; WallMs = <int>; Message = <string> }

    Kind='EXIT'    the run finished; Code is phpunit's exit code (0 = all passed)
    Kind='KILLED'  the budget expired and the child was killed: INFRASTRUCTURE, proves
                   nothing (AGENTS.md) - re-run the same scoped selection
    Kind='START-ERROR'  the child never started (also infrastructure)

  The scratch DB env is applied to the child environment only (belt-and-braces; the forced
  generated phpunit XML is the real pin, so neither the shell nor the commit's .env can
  redirect the run).
#>

Set-StrictMode -Off

function Invoke-ScopedPhpunit {
  param(
    [Parameter(Mandatory = $true)][string]$RunDir,
    [Parameter(Mandatory = $true)][string[]]$ArgList,
    [Parameter(Mandatory = $true)][string]$LogFile,
    [Parameter(Mandatory = $true)][int]$TimeoutMs,
    [string[]]$EnvPairs = @()
  )

  $sw = [System.Diagnostics.Stopwatch]::StartNew()

  if (-not (Test-Path -LiteralPath $RunDir)) {
    return @{ Kind = 'START-ERROR'; Code = -1; WallMs = 0; Message = ('runDir missing: ' + $RunDir) }
  }
  if ($ArgList.Count -lt 2) {
    return @{ Kind = 'START-ERROR'; Code = -1; WallMs = 0;
              Message = ('argv has ' + $ArgList.Count + ' element(s) - refusing to start php with an unterminated command') }
  }

  # build a Windows command line: quote anything with spaces, escape embedded quotes
  $quoted = @()
  foreach ($a in $ArgList) {
    $s = [string]$a
    if ($s -match '[\s"]') {
      # Windows CLI quoting: double backslashes before a quote, then wrap in quotes
      $s = '"' + ($s -replace '(\\+)"', '$1$1\"') + '"'
    }
    $quoted += $s
  }
  $cmdLine = ($quoted -join ' ')

  $psi = New-Object System.Diagnostics.ProcessStartInfo
  $psi.FileName = 'php'
  $psi.Arguments = $cmdLine
  $psi.WorkingDirectory = $RunDir
  $psi.UseShellExecute = $false
  $psi.CreateNoWindow = $true
  $psi.RedirectStandardOutput = $true
  $psi.RedirectStandardError = $true
  $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
  $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8

  foreach ($pair in $EnvPairs) {
    $kv = ([string]$pair) -split '=', 2
    if ($kv.Count -eq 2 -and $kv[0]) { $psi.EnvironmentVariables[$kv[0]] = $kv[1] }
  }
  $psi.EnvironmentVariables['APP_ENV'] = 'testing'
  $psi.EnvironmentVariables['NO_COLOR'] = '1'

  $p = $null
  try {
    $p = [System.Diagnostics.Process]::Start($psi)
  } catch {
    return @{ Kind = 'START-ERROR'; Code = -1; WallMs = [int]$sw.ElapsedMilliseconds;
              Message = ('php could not start: ' + $_.Exception.Message) }
  }

  $outTask = $p.StandardOutput.ReadToEndAsync()
  $errTask = $p.StandardError.ReadToEndAsync()

  $exited = $p.WaitForExit($TimeoutMs)
  if (-not $exited) {
    try { $null = $p.Kill($true) } catch {
      try { $p.Kill() } catch { }
    }
    try { $null = $p.WaitForExit(5000) } catch { }
    $partial = ''
    try { $partial = $outTask.Result } catch { }
    try { $partial += "`n" + $errTask.Result } catch { }
    $lines = @(($partial -split "`r?`n") | Select-Object -Last 200)
    Set-Content -Path $LogFile -Value $lines -Encoding UTF8
    Add-Content -Path $LogFile -Value ('KILLED.' + $TimeoutMs) -Encoding UTF8
    try { $p.Dispose() } catch { }
    return @{ Kind = 'KILLED'; Code = -1; WallMs = [int]$sw.ElapsedMilliseconds;
              Message = ('exceeded ' + $TimeoutMs + 'ms wall clock') }
  }

  $code = 0
  try { $code = $p.ExitCode } catch { $code = -1 }
  $stdout = ''
  $stderr = ''
  try { $stdout = $outTask.Result } catch { }
  try { $stderr = $errTask.Result } catch { }
  try { $p.Dispose() } catch { }

  # Laravel writes the boot/exception banner to stderr; merge so the tail is the real story
  $all = @()
  if ($stdout) { $all += ($stdout -split "`r?`n") }
  if ($stderr) { $all += ($stderr -split "`r?`n") }
  $all = @($all | Where-Object { $_ -ne $null })
  Set-Content -Path $LogFile -Value $all -Encoding UTF8
  Add-Content -Path $LogFile -Value ('EXIT.' + $code) -Encoding UTF8

  return @{ Kind = 'EXIT'; Code = $code; WallMs = [int]$sw.ElapsedMilliseconds; Message = '' }
}
