#Requires -Version 5.0
<#
.SYNOPSIS
  Shared loader for the throwaway scratch-MySQL settings (AGENTS.local.md).

.DESCRIPTION
  Used by scripts/db-ping.ps1 and scripts/test-related.ps1 so the "which database am I
  allowed to touch" logic exists exactly once (high cohesion, single seam).

  Resolution order per variable: the current shell environment wins; missing values are
  filled from the $env:NAME='value' assignments found in AGENTS.local.md (gitignored -
  no credentials live in the scripts). Passwords never leave the local process and are
  never printed by callers.

  Assert-ScratchDbGate implements the safety rule from AGENTS.local.md: if DB_HOST is
  not 127.0.0.1 or DB_PORT is not 3399 (or the replica pair, when present, points
  anywhere else), that is a hard stop - a phpunit/RefreshDatabase run against another
  host would drop a real database.
#>

Set-StrictMode -Off

$ScratchHost = '127.0.0.1'
$ScratchPort = '3399'
$RequiredScratchVars = @('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')
$OptionalScratchVars = @('DB_REPLICA_HOST', 'DB_REPLICA_PORT')

function Read-ScratchEnv {
  <# Reads the scratch DB settings: shell environment first, AGENTS.local.md fills gaps.
     Returns @{ Values = hashtable; Missing = array; SourceFile = string; HadFile = bool } #>
  param(
    [string]$RepoRoot = '',
    [string]$LocalFile = ''
  )
  if (-not $RepoRoot) {
    if ($PSScriptRoot) { $RepoRoot = Split-Path -Parent $PSScriptRoot } else { $RepoRoot = (Get-Location).Path }
  }
  if (-not $LocalFile) { $LocalFile = Join-Path $RepoRoot 'AGENTS.local.md' }

  $loaded = @{}
  $hadFile = (Test-Path -LiteralPath $LocalFile)
  if ($hadFile) {
    $text = ''
    try { $text = Get-Content -Raw -LiteralPath $LocalFile -Encoding UTF8 } catch { $text = '' }
    foreach ($m in [regex]::Matches($text, "\`$env:([A-Za-z0-9_]+)\s*=\s*'([^']*)'")) {
      $loaded[$m.Groups[1].Value] = $m.Groups[2].Value
    }
  }

  $values = @{}
  $missing = @()
  foreach ($name in ($RequiredScratchVars + $OptionalScratchVars)) {
    $v = [Environment]::GetEnvironmentVariable($name)
    if ([string]::IsNullOrEmpty($v) -and $loaded.ContainsKey($name)) { $v = $loaded[$name] }
    if ([string]::IsNullOrEmpty($v)) {
      if ($RequiredScratchVars -contains $name) { $missing += $name }
    } else {
      $values[$name] = $v
    }
  }
  return @{
    Values     = $values
    Missing    = $missing
    SourceFile = $LocalFile
    HadFile    = $hadFile
  }
}

function Assert-ScratchDbGate {
  <# Safety gate. Returns an array of violation strings (empty array = OK). #>
  param([hashtable]$Values)
  $violations = @()
  $h = $Values['DB_HOST']
  $p = $Values['DB_PORT']
  if ($h -ne $ScratchHost) {
    $violations += ("DB_HOST is '" + $h + "' - MUST be 127.0.0.1. Never run tests against the .env/Aiven target; RefreshDatabase would drop those tables.")
  }
  if ($p -ne $ScratchPort) {
    $violations += ("DB_PORT is '" + $p + "' - MUST be 3399 (scratch MySQL).")
  }
  foreach ($name in $OptionalScratchVars) {
    if ($Values.ContainsKey($name)) {
      $want = $ScratchHost
      if ($name -eq 'DB_REPLICA_PORT') { $want = $ScratchPort }
      if ($Values[$name] -ne $want) {
        $violations += ($name + " is '" + $Values[$name] + "' - MUST be " + $want + ' (config/database.php reads the replica host first).')
      }
    }
  }
  return $violations
}

function ConvertTo-ScratchPhpunitXml {
  <# Rewrites a commit's phpunit.xml so every DB env is pinned to the scratch values with
     force="true". PHPUnit's PhpHandler applies force="true" unconditionally (putenv +
     $_ENV), so neither the shell nor the commit's .env (which points at Aiven!) can
     redirect the run - and RefreshDatabase drops every table, so this pin is the safety
     property the AGENTS.local.md rule exists for. APP_ENV is pinned to testing as well.
     bootstrap is made absolute and points at $RunDir's own autoloader (PHPUnit resolves
     relative bootstrap paths against the CONFIG directory, and our generated config lives
     in TEMP or the sandbox). The file on disk is never modified: this returns text. #>
  param(
    [string]$Xml,
    [hashtable]$Values,
    [string]$RunDir = ''
  )
  # make idempotent: drop any pre-existing force attribute, we re-add our own
  $Xml = [regex]::Replace($Xml, '\s+force="[^"]*"', '')

  $keys = @('DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_REPLICA_HOST', 'DB_REPLICA_PORT')
  foreach ($k in ($keys + @('APP_ENV'))) {
    if ($k -eq 'APP_ENV') { $v = 'testing' } else { $v = $Values[$k] }
    if ([string]::IsNullOrEmpty($v)) { continue }
    $pattern = '(<env\s+name="' + [regex]::Escape($k) + '"\s+value=")[^"]*(")'
    if ($Xml -match ('<env\s+name="' + [regex]::Escape($k) + '"')) {
      # MatchEvaluator keeps the value literal (no $1/$& surprises from passwords)
      $eval = { param($mm) $mm.Groups[1].Value + $v + $mm.Groups[2].Value + ' force="true"' }.GetNewClosure()
      $Xml = [regex]::Replace($Xml, $pattern, $eval)
    } else {
      $Xml = $Xml -replace '<php>', ('<php><env name="' + $k + '" value="' + $v + '" force="true"/>')
    }
  }
  # commit had no <php> block at all: inject a full scratch block before </phpunit>
  if ($Xml -notmatch '<env\s+name="DB_HOST"') {
    $block = '<php>'
    foreach ($k in $keys) {
      $v = $Values[$k]
      if ([string]::IsNullOrEmpty($v)) { continue }
      $block += ('<env name="' + $k + '" value="' + $v + '" force="true"/>')
    }
    $block += '<env name="APP_ENV" value="testing" force="true"/></php>'
    $Xml = $Xml -replace '</phpunit>', ($block + '</phpunit>')
  }

  if ($RunDir) {
    $autoload = ((Join-Path $RunDir 'vendor/autoload.php') -replace '\\', '/')
    if ($Xml -match 'bootstrap="') {
      $Xml = [regex]::Replace($Xml, 'bootstrap="[^"]*"', ('bootstrap="' + $autoload + '"'))
    } else {
      $Xml = $Xml -replace '<phpunit ', ('<phpunit bootstrap="' + $autoload + '" ')
    }
  }

  # <source>/<coverage> paths are relative to the CONFIG directory and only matter for
  # coverage, which is always off here (--no-coverage). Dropping them keeps the generated
  # config location-independent; PHPUnit warns if the source directory cannot be resolved.
  $Xml = [regex]::Replace($Xml, '(?is)<source\s*>.*?</source\s*>', '')
  $Xml = [regex]::Replace($Xml, '(?is)<coverage\s*>.*?</coverage\s*>', '')

  return $Xml
}

function Write-ScratchPhpunitConfig {
  <# Generates the scratch-pinned config into $OutDir for a run in $RunDir and returns its
     path. The repo's phpunit.xml is never modified. #>
  param(
    [string]$SourceXmlPath,
    [hashtable]$Values,
    [string]$OutDir,
    [string]$RunDir,
    [string]$Tag = 'run'
  )
  $xml = ConvertTo-ScratchPhpunitXml -Xml (Get-Content -Raw -LiteralPath $SourceXmlPath -Encoding UTF8) `
        -Values $Values -RunDir $RunDir
  $xmlPath = Join-Path $OutDir ('phpunit.scratch.' + $Tag + '.xml')
  [System.IO.File]::WriteAllText($xmlPath, $xml, (New-Object System.Text.UTF8Encoding($false)))
  return $xmlPath
}
