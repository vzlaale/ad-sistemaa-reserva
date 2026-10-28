param(
    [Parameter(Mandatory = $true)][string[]]$BaselineMonthlyFiles,
    [Parameter(Mandatory = $true)][string]$BaselineDailyDir,
    [Parameter(Mandatory = $true)][string[]]$CurrentMonthlyFiles,
    [Parameter(Mandatory = $true)][string]$CurrentDailyDir
)

$ErrorActionPreference = 'Stop'
$month = '2026-11'
$days = @(1, 5, 10, 20, 25)

function Json($value) {
    ConvertTo-Json -InputObject $value -Compress -Depth 50
}

function Case-Key($report) {
    $professional = if ($null -eq $report.professional_id) { 'null' } else { [string]$report.professional_id }
    '{0}/{1}/{2}' -f $report.form_id, $report.service_id, $professional
}

function Read-Report([string]$path, [string]$kind, [string]$version) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw "Falta el reporte: $path" }
    $report = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($report.kind -ne $kind -or $report.month -ne $month -or $report.error -or
        $report.data_changed -ne $false -or (Json $report.initial_context) -ne (Json $report.final_context)) {
        throw "Reporte inválido o con historial modificado: $path"
    }
    $expectedCount = if ($kind -eq 'month') { 20 } else { 1 }
    $expectedAction = if ($kind -eq 'month') { 'ov_get_month_availability' } else { 'ov_get_available_slots' }
    if (@($report.samples).Count -ne $expectedCount) { throw "Cantidad de muestras incorrecta: $path" }
    foreach ($sample in $report.samples) {
        if ($sample.metrics.plugin_version -ne $version -or $sample.metrics.action -ne $expectedAction) {
            throw "Versión o ruta incorrecta: $path"
        }
        if ($kind -eq 'month' -and
            (-not $sample.metrics.date_add_seen -or -not $sample.metrics.date_add_explain -or
                @($sample.metrics.date_add_explain).Count -eq 0)) {
            throw "Falta EXPLAIN de DATE_ADD: $path"
        }
    }
    if ($kind -eq 'month') {
        $reference = Json $report.samples[0].available_dates
        foreach ($sample in $report.samples) {
            if ((Json $sample.available_dates) -ne $reference) { throw "Fechas internas distintas: $path" }
        }
    } elseif ($report.date -notin @($days | ForEach-Object { '{0}-{1:00}' -f $month, $_ })) {
        throw "Fecha diaria inesperada: $path"
    }
    return $report
}

function Load-Set([string[]]$monthlyFiles, [string]$dailyDir, [string]$version) {
    if (@($monthlyFiles).Count -ne 4) { throw "Se requieren cuatro reportes mensuales: $version" }
    if (-not (Test-Path -LiteralPath $dailyDir -PathType Container)) { throw "Falta la carpeta diaria: $dailyDir" }
    $dailyFiles = @(Get-ChildItem -LiteralPath $dailyDir -File -Filter '*.json')
    if ($dailyFiles.Count -ne 20) { throw "Se requieren veinte reportes diarios: $version" }
    $monthly = @{}
    $daily = @{}
    $contexts = @{}
    $warnings = @()

    foreach ($path in $monthlyFiles) {
        $report = Read-Report $path 'month' $version
        $key = Case-Key $report
        if ($monthly.ContainsKey($key)) { throw "Caso mensual duplicado: $key" }
        $monthly[$key] = $report
        $formKey = '{0}/{1}' -f $report.form_id, $report.service_id
        $context = Json $report.initial_context
        if ($contexts.ContainsKey($formKey) -and $contexts[$formKey] -ne $context) {
            throw "Contexto distinto entre casos del formulario $formKey"
        }
        $contexts[$formKey] = $context
    }
    $solo = @($monthly.Values | Where-Object { $null -eq $_.professional_id })
    $any = @($monthly.Values | Where-Object { $null -ne $_.professional_id -and [int]$_.professional_id -eq 0 })
    $specific = @($monthly.Values | Where-Object { $null -ne $_.professional_id -and [int]$_.professional_id -gt 0 })
    if ($solo.Count -ne 1 -or $any.Count -ne 1 -or $specific.Count -ne 2 -or
        $solo[0].form_id -eq $any[0].form_id -or
        @($specific | Where-Object { $_.form_id -ne $any[0].form_id -or $_.service_id -ne $any[0].service_id }).Count -ne 0) {
        throw "Faltan los modos sin profesional, dos profesionales concretos o cualquiera: $version"
    }
    foreach ($file in $dailyFiles) {
        $report = Read-Report $file.FullName 'day' $version
        $caseKey = Case-Key $report
        $key = '{0}|{1}' -f $caseKey, $report.date
        if (-not $monthly.ContainsKey($caseKey) -or $daily.ContainsKey($key)) {
            throw "Caso diario inesperado o duplicado: $key"
        }
        $formKey = '{0}/{1}' -f $report.form_id, $report.service_id
        if ((Json $report.initial_context) -ne $contexts[$formKey]) {
            throw "El historial diario difiere del mensual: $($file.Name)"
        }
        $daily[$key] = $report
        foreach ($slot in @($report.samples[0].available_slots)) {
            if ($null -ne $slot.capacity -and $null -ne $slot.used -and $null -ne $slot.remaining -and
                [int]$slot.capacity - [int]$slot.used -ne [int]$slot.remaining) {
                $warnings += "$version $key $($slot.time): capacity-used difiere de remaining"
            }
        }
    }
    foreach ($caseKey in $monthly.Keys) {
        foreach ($day in $days) {
            $date = '{0}-{1:00}' -f $month, $day
            if (-not $daily.ContainsKey("$caseKey|$date")) { throw "Falta $caseKey el $date en $version" }
        }
    }
    return @{ monthly = $monthly; daily = $daily; contexts = $contexts; warnings = $warnings }
}

$baseline = Load-Set $BaselineMonthlyFiles $BaselineDailyDir '1.8.3'
$current = Load-Set $CurrentMonthlyFiles $CurrentDailyDir '1.8.7'
if ((Json @($baseline.monthly.Keys | Sort-Object)) -ne (Json @($current.monthly.Keys | Sort-Object))) {
    throw 'Los IDs de formulario, servicio o profesional cambiaron. Se necesita el mismo fixture.'
}
foreach ($formKey in $baseline.contexts.Keys) {
    if (-not $current.contexts.ContainsKey($formKey) -or
        $baseline.contexts[$formKey] -ne $current.contexts[$formKey]) {
        throw "El historial cambió entre versiones para $formKey"
    }
}

$summary = @()
foreach ($key in @($baseline.monthly.Keys | Sort-Object)) {
    $old = $baseline.monthly[$key]
    $new = $current.monthly[$key]
    if ((Json $old.samples[0].available_dates) -ne (Json $new.samples[0].available_dates)) {
        throw "Fechas mensuales distintas para $key"
    }
    $oldQueries = [double]$old.summary.sql_queries.median
    $newQueries = [double]$new.summary.sql_queries.median
    if ($newQueries -ge $oldQueries) {
        throw "Las consultas mensuales no bajaron para $key ($oldQueries -> $newQueries)"
    }
    $oldPlan = $old.samples[0].metrics.date_add_explain[0]
    $newPlan = $new.samples[0].metrics.date_add_explain[0]
    $summary += [pscustomobject]@{
        case = $key
        dates = @($new.samples[0].available_dates).Count
        baseline_queries_median = $oldQueries
        current_queries_median = $newQueries
        baseline_latency_median_ms = $old.summary.elapsed_ms.median
        baseline_latency_p95_ms = $old.summary.elapsed_ms.p95
        current_latency_median_ms = $new.summary.elapsed_ms.median
        current_latency_p95_ms = $new.summary.elapsed_ms.p95
        baseline_date_add_type = $oldPlan.type
        baseline_date_add_key = $oldPlan.key
        baseline_date_add_rows = $oldPlan.rows
        baseline_date_add_extra = $oldPlan.Extra
        current_date_add_type = $newPlan.type
        current_date_add_key = $newPlan.key
        current_date_add_rows = $newPlan.rows
        current_date_add_extra = $newPlan.Extra
    }
}
foreach ($key in $baseline.daily.Keys) {
    if ((Json $baseline.daily[$key].samples[0].available_slots) -ne
        (Json $current.daily[$key].samples[0].available_slots)) {
        throw "Horarios o cupos distintos para $key"
    }
}

[pscustomobject]@{
    result = 'PARITY_AND_SQL_PASS_EXPLAIN_REVIEW_REQUIRED'
    month = $month
    fixture_forms = @($baseline.contexts.Keys | Sort-Object)
    history_by_form = @($baseline.monthly.Values | Group-Object form_id | ForEach-Object {
        $_.Group[0].initial_context
    })
    monthly_cases = $summary
    daily_pairs_equal = $baseline.daily.Count
    capacity_warnings = @($baseline.warnings + $current.warnings)
} | ConvertTo-Json -Depth 20
