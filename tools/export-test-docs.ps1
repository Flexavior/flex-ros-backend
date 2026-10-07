<#
    export-test-docs.ps1 — regenerates every MSS-CRM test-case document as Word (.docx).

    Usage:  powershell -ExecutionPolicy Bypass -File backend\tools\export-test-docs.ps1
    Output: docs\docx\*.docx  (one file per source document, plus a combined pack)

    Requires PHP 8 with the zip extension (Laragon's CLI build is used by default);
    falls back to `php` on the PATH when the Laragon path is absent.
#>

$ErrorActionPreference = 'Stop'

$php = 'C:\laragon\bin\php\php-8.3.27-Win32-vs16-x64\php.exe'
if (-not (Test-Path $php)) { $php = 'php' }

$backend  = Split-Path -Parent $PSScriptRoot
$project  = Split-Path -Parent $backend
$docsDir  = Join-Path $project 'docs'
$outDir   = Join-Path $docsDir 'docx'
$exporter = Join-Path $PSScriptRoot 'md-to-docx.php'
$stamp    = 'Version 1.0 - 2026-09-29'

$documents = @(
    @{ File = '05-Test-Cases-Unit.md';         Out = '05-MSS-CRM-Test-Cases-Unit.docx';           Title = 'MSS-CRM - Unit Test Case List' },
    @{ File = '06-Test-Cases-Functional.md';   Out = '06-MSS-CRM-Test-Cases-Functional.docx';     Title = 'MSS-CRM - Functional Test Case List' },
    @{ File = '07-Test-Cases-Integration.md';  Out = '07-MSS-CRM-Test-Cases-Integration.docx';    Title = 'MSS-CRM - Integration Test Case List' },
    @{ File = '08-Test-Cases-Traceability.md'; Out = '08-MSS-CRM-Test-Cases-Traceability.docx';   Title = 'MSS-CRM - Test Traceability Matrix' }
)

$inputs = @()
foreach ($doc in $documents) {
    $source = Join-Path $docsDir $doc.File
    if (-not (Test-Path $source)) { throw "Missing source document: $source" }

    $target = Join-Path $outDir $doc.Out
    & $php $exporter "--in=$source" "--out=$target" "--title=$($doc.Title)" "--subtitle=$stamp" '--author=MSS'
    $inputs += "--in=$source"
}

$combined = Join-Path $outDir 'MSS-CRM-Test-Cases-Complete.docx'
& $php $exporter @inputs "--out=$combined" '--title=MSS-CRM - Complete Test Case Pack' '--subtitle=Unit - Functional - Integration - Traceability' '--author=MSS'

Get-ChildItem $outDir -Filter *.docx | Sort-Object Name | ForEach-Object {
    "$($_.Name)  $([math]::Round($_.Length / 1KB, 1)) KB"
}
