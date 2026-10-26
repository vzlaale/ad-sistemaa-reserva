param(
    [Parameter(Mandatory = $true)][string]$Baseline,
    [Parameter(Mandatory = $true)][string]$Current,
    [switch]$RequireDateAdd
)

$ErrorActionPreference = 'Stop'

function Read-Report([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "No existe el reporte: $Path"
    }
    $report = Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($null -ne $report.error -and $report.error -ne '') {
        throw "Serie interrumpida: $Path ($($report.error))"
    }
    if ($report.data_changed -ne $false) {
        throw "Cambió el contexto de reservas: $Path"
    }
    if (@($report.samples).Count -lt 1) {
        throw "Serie sin muestras: $Path"
    }
    $before = ConvertTo-Json -InputObject $report.initial_context -Compress -Depth 20
    $after = ConvertTo-Json -InputObject $report.final_context -Compress -Depth 20
    if ($before -ne $after) {
        throw "El contexto inicial y final difiere: $Path"
    }
    return $report
}

function Canonical-Json($Value) {
    return ConvertTo-Json -InputObject $Value -Compress -Depth 20
}

$old = Read-Report $Baseline
$new = Read-Report $Current
if ($old.kind -ne $new.kind -or $old.kind -notin @('month', 'day')) {
    throw 'Los reportes deben ser ambos mensuales o ambos diarios.'
}
foreach ($property in @('form_id', 'service_id', 'month', 'professional_id', 'date')) {
    if ($old.$property -ne $new.$property) {
        throw "Difiere el parámetro $property entre versiones."
    }
}
if ((Canonical-Json $old.initial_context) -ne (Canonical-Json $new.initial_context)) {
    throw 'El contexto de reservas difiere entre versiones.'
}
if (@($old.samples).Count -ne @($new.samples).Count) {
    throw 'Las series tienen distinto número de muestras.'
}
if ($old.kind -eq 'month' -and @($old.samples).Count -ne 20) {
    throw 'La comparación mensual requiere 20 muestras por versión.'
}

$field = if ($old.kind -eq 'month') { 'available_dates' } else { 'available_slots' }
$reference = Canonical-Json $old.samples[0].$field
foreach ($sample in @($old.samples) + @($new.samples)) {
    if ((Canonical-Json $sample.$field) -ne $reference) {
        throw "Difieren $field entre muestras o versiones."
    }
}

$dateAddOld = @($old.samples | Where-Object { $_.metrics.date_add_explain -and @($_.metrics.date_add_explain).Count -gt 0 }).Count -gt 0
$dateAddNew = @($new.samples | Where-Object { $_.metrics.date_add_explain -and @($_.metrics.date_add_explain).Count -gt 0 }).Count -gt 0
if ($RequireDateAdd -and (-not $dateAddOld -or -not $dateAddNew)) {
    throw 'Falta EXPLAIN de DATE_ADD en alguna versión.'
}

if ($old.kind -eq 'month') {
    $oldQueries = [double]$old.summary.sql_queries.median
    $newQueries = [double]$new.summary.sql_queries.median
    if ($newQueries -ge $oldQueries) {
        throw 'La mediana de consultas SQL no disminuyó.'
    }
}

[pscustomobject]@{
    result = 'PASS'
    kind = $old.kind
    baseline_version = $old.samples[0].metrics.plugin_version
    current_version = $new.samples[0].metrics.plugin_version
    form_id = $old.form_id
    service_id = $old.service_id
    professional_id = $old.professional_id
    month = $old.month
    date = $old.date
    samples_per_version = @($old.samples).Count
    baseline_sql_median = if ($old.kind -eq 'month') { $old.summary.sql_queries.median } else { $null }
    current_sql_median = if ($old.kind -eq 'month') { $new.summary.sql_queries.median } else { $null }
    baseline_elapsed_median_ms = if ($old.kind -eq 'month') { $old.summary.elapsed_ms.median } else { $null }
    current_elapsed_median_ms = if ($old.kind -eq 'month') { $new.summary.elapsed_ms.median } else { $null }
    baseline_elapsed_p95_ms = if ($old.kind -eq 'month') { $old.summary.elapsed_ms.p95 } else { $null }
    current_elapsed_p95_ms = if ($old.kind -eq 'month') { $new.summary.elapsed_ms.p95 } else { $null }
    date_add_explain_baseline = $dateAddOld
    date_add_explain_current = $dateAddNew
} | Format-List
