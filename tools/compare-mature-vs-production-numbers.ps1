param(
  [string]$MatureRoot = 'D:\File Kantor Ibu Dewantari\Laporan Notaris',
  [string]$ProductionJson = 'production-numbers-2016-2021.json',
  [string]$OutputDir = 'reports'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression.FileSystem

$monthMap = @{
  'JANUARI' = '01'; 'FEBRUARI' = '02'; 'MARET' = '03'; 'APRIL' = '04'
  'MEI' = '05'; 'JUNI' = '06'; 'JULI' = '07'; 'AGUSTUS' = '08'
  'SEPTEMBER' = '09'; 'OKTOBER' = '10'; 'NOVEMBER' = '11'; 'DESEMBER' = '12'
}

function Normalize-Number([object]$value) {
  if ($null -eq $value) { return '' }
  $text = ([string]$value).Trim()
  if ($text -eq '') { return '' }
  $match = [regex]::Match($text, '\d+')
  if (-not $match.Success) { return '' }
  return ([int]$match.Value).ToString()
}

function Get-YearMonthFromText([string]$text) {
  $upper = $text.ToUpperInvariant()
  $yearMatch = [regex]::Match($upper, '(20\d{2})')
  if (-not $yearMatch.Success) { return $null }
  $year = $yearMatch.Groups[1].Value
  foreach ($name in $monthMap.Keys) {
    if ($upper.Contains($name)) {
      return "$year-$($monthMap[$name])"
    }
  }
  return $null
}

function Get-ModuleFromPath([string]$path) {
  $upper = $path.ToUpperInvariant()
  if ($upper.Contains('LEGALISASI')) { return 'Legalisasi' }
  if ($upper.Contains('WARMERKING')) { return 'Warmerking' }
  if ($upper.Contains('PPAT')) { return 'PPAT' }
  if ($upper.Contains('NOTARIS')) { return 'Notaris' }
  return $null
}

function Get-SharedStrings($zip) {
  $entry = $zip.Entries | Where-Object FullName -eq 'xl/sharedStrings.xml' | Select-Object -First 1
  $shared = @()
  if (-not $entry) { return $shared }
  $reader = [IO.StreamReader]::new($entry.Open())
  try {
    [xml]$xml = $reader.ReadToEnd()
  } finally {
    $reader.Dispose()
  }
  foreach ($si in $xml.sst.si) {
    $shared += [string]$si.InnerText
  }
  return $shared
}

function Read-XlsxSheetRows([string]$file, [string]$sheetPath) {
  $zip = [System.IO.Compression.ZipFile]::OpenRead($file)
  try {
    $shared = Get-SharedStrings $zip
    $entry = $zip.Entries | Where-Object FullName -eq $sheetPath | Select-Object -First 1
    if (-not $entry) { return @() }
    $reader = [IO.StreamReader]::new($entry.Open())
    try {
      [xml]$xml = $reader.ReadToEnd()
    } finally {
      $reader.Dispose()
    }
    $rows = @()
    foreach ($row in $xml.worksheet.sheetData.row) {
      $record = [ordered]@{ Row = [int]$row.r }
      $cellProperty = $row.PSObject.Properties['c']
      if ($null -eq $cellProperty -or $null -eq $cellProperty.Value) { continue }
      foreach ($cell in $cellProperty.Value) {
        $ref = [string]$cell.GetAttribute('r')
        $column = ([regex]::Match($ref, '^[A-Z]+')).Value
        $valueProperty = $cell.PSObject.Properties['v']
        $value = if ($null -ne $valueProperty -and $null -ne $valueProperty.Value) { [string]$valueProperty.Value } else { '' }
        if ($cell.GetAttribute('t') -eq 's' -and $value -ne '') {
          $value = $shared[[int]$value]
        }
        $record[$column] = $value
      }
      $rows += [pscustomobject]$record
    }
    return $rows
  } finally {
    $zip.Dispose()
  }
}

function Get-WorkbookSheets([string]$file) {
  $zip = [System.IO.Compression.ZipFile]::OpenRead($file)
  try {
    $wbEntry = $zip.Entries | Where-Object FullName -eq 'xl/workbook.xml' | Select-Object -First 1
    $relEntry = $zip.Entries | Where-Object FullName -eq 'xl/_rels/workbook.xml.rels' | Select-Object -First 1
    if (-not $wbEntry -or -not $relEntry) { return @() }
    $wbReader = [IO.StreamReader]::new($wbEntry.Open())
    $relReader = [IO.StreamReader]::new($relEntry.Open())
    try {
      [xml]$wb = $wbReader.ReadToEnd()
      [xml]$rels = $relReader.ReadToEnd()
    } finally {
      $wbReader.Dispose()
      $relReader.Dispose()
    }
    $relMap = @{}
    foreach ($rel in $rels.Relationships.Relationship) {
      $relMap[[string]$rel.Id] = 'xl/' + ([string]$rel.Target).TrimStart('/')
    }
    $sheets = @()
    foreach ($sheet in $wb.workbook.sheets.sheet) {
      $rid = $sheet.GetAttribute('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
      $sheets += [pscustomobject]@{
        Name = [string]$sheet.name
        Path = $relMap[$rid]
      }
    }
    return $sheets
  } finally {
    $zip.Dispose()
  }
}

function Add-MatureRow([System.Collections.Generic.List[object]]$rows, [string]$module, [string]$period, [object]$nomor, [string]$source) {
  $normalized = Normalize-Number $nomor
  if ($normalized -eq '' -or -not $period) { return }
  $rows.Add([pscustomobject]@{
    source = 'mature'
    module = $module
    period = $period
    nomor = [string]$nomor
    normalized_nomor = $normalized
    record_id = ''
    file = $source
  })
}

function Get-RowValue([object]$row, [string]$column) {
  $property = $row.PSObject.Properties[$column]
  if ($null -eq $property) { return $null }
  return $property.Value
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$matureRows = [System.Collections.Generic.List[object]]::new()
$xlsxFiles = Get-ChildItem -LiteralPath $MatureRoot -Recurse -File -Filter *.xlsx |
  Where-Object { $_.FullName -match '(2016|2017|2018|2019|2020|2021)' }

foreach ($file in $xlsxFiles) {
  $module = Get-ModuleFromPath $file.FullName
  if (-not $module) { continue }

  if ($module -eq 'PPAT') {
    foreach ($sheet in Get-WorkbookSheets $file.FullName) {
      $period = Get-YearMonthFromText $sheet.Name
      if (-not $period) { continue }
      foreach ($row in Read-XlsxSheetRows $file.FullName $sheet.Path) {
        if ($row.Row -lt 18) { continue }
        $number = Get-RowValue $row 'A'
        $dateValue = Get-RowValue $row 'C'
        if ((Normalize-Number $number) -ne '' -and (Normalize-Number $dateValue) -ne '') {
          Add-MatureRow $matureRows $module $period $number "$($file.FullName) [$($sheet.Name)]"
        }
      }
    }
    continue
  }

  $periodFromFile = Get-YearMonthFromText $file.FullName
  if (-not $periodFromFile) { continue }
  $rows = Read-XlsxSheetRows $file.FullName 'xl/worksheets/sheet1.xml'
  foreach ($row in $rows) {
    if ($row.Row -lt 2) { continue }
    $number = if ($module -eq 'Notaris') {
      Get-RowValue $row 'B'
    } else {
      Get-RowValue $row 'A'
    }
    Add-MatureRow $matureRows $module $periodFromFile $number $file.FullName
  }
}

$productionRaw = Get-Content -LiteralPath $ProductionJson -Raw | ConvertFrom-Json
$productionRows = foreach ($row in $productionRaw) {
  $date = [datetime]$row.tanggal
  [pscustomobject]@{
    source = 'production'
    module = [string]$row.module
    period = $date.ToString('yyyy-MM')
    nomor = [string]$row.nomor
    normalized_nomor = Normalize-Number $row.nomor
    record_id = [string]$row.record_id
    file = ''
  }
}

$matureRows = @($matureRows | Where-Object { $_.normalized_nomor -ne '' })
$productionRows = @($productionRows | Where-Object { $_.normalized_nomor -ne '' })

$matureKeys = @{}
foreach ($row in $matureRows) {
  $key = "$($row.module)|$($row.period)|$($row.normalized_nomor)"
  if (-not $matureKeys.ContainsKey($key)) { $matureKeys[$key] = @() }
  $matureKeys[$key] += $row
}

$productionKeys = @{}
foreach ($row in $productionRows) {
  $key = "$($row.module)|$($row.period)|$($row.normalized_nomor)"
  if (-not $productionKeys.ContainsKey($key)) { $productionKeys[$key] = @() }
  $productionKeys[$key] += $row
}

$anomalies = [System.Collections.Generic.List[object]]::new()

foreach ($key in $productionKeys.Keys) {
  if (-not $matureKeys.ContainsKey($key)) {
    foreach ($row in $productionKeys[$key]) {
      $anomalies.Add([pscustomobject]@{
        type = 'ADA_DI_PRODUCTION_TIDAK_ADA_DI_DATA_MATANG'
        module = $row.module
        period = $row.period
        nomor = $row.normalized_nomor
        production_record_ids = $row.record_id
        production_raw_numbers = $row.nomor
        mature_raw_numbers = ''
        mature_files = ''
      })
    }
  }
}

foreach ($key in $matureKeys.Keys) {
  if (-not $productionKeys.ContainsKey($key)) {
    $sample = $matureKeys[$key][0]
    $anomalies.Add([pscustomobject]@{
      type = 'ADA_DI_DATA_MATANG_TIDAK_ADA_DI_PRODUCTION'
      module = $sample.module
      period = $sample.period
      nomor = $sample.normalized_nomor
      production_record_ids = ''
      production_raw_numbers = ''
      mature_raw_numbers = (($matureKeys[$key] | ForEach-Object nomor | Select-Object -Unique) -join ', ')
      mature_files = (($matureKeys[$key] | ForEach-Object file | Select-Object -Unique) -join ' | ')
    })
  }
}

foreach ($key in $productionKeys.Keys) {
  if ($productionKeys[$key].Count -gt 1) {
    $sample = $productionKeys[$key][0]
    $anomalies.Add([pscustomobject]@{
      type = 'DUPLIKAT_DI_PRODUCTION'
      module = $sample.module
      period = $sample.period
      nomor = $sample.normalized_nomor
      production_record_ids = (($productionKeys[$key] | ForEach-Object record_id) -join ', ')
      production_raw_numbers = (($productionKeys[$key] | ForEach-Object nomor | Select-Object -Unique) -join ', ')
      mature_raw_numbers = ''
      mature_files = ''
    })
  }
}

foreach ($key in $matureKeys.Keys) {
  if ($matureKeys[$key].Count -gt 1) {
    $sample = $matureKeys[$key][0]
    $anomalies.Add([pscustomobject]@{
      type = 'DUPLIKAT_DI_DATA_MATANG'
      module = $sample.module
      period = $sample.period
      nomor = $sample.normalized_nomor
      production_record_ids = ''
      production_raw_numbers = ''
      mature_raw_numbers = (($matureKeys[$key] | ForEach-Object nomor | Select-Object -Unique) -join ', ')
      mature_files = (($matureKeys[$key] | ForEach-Object file | Select-Object -Unique) -join ' | ')
    })
  }
}

$summary = $anomalies |
  Group-Object type, module |
  ForEach-Object {
    [pscustomobject]@{
      type = $_.Group[0].type
      module = $_.Group[0].module
      count = $_.Count
    }
  } |
  Sort-Object type, module

$detailPath = Join-Path $OutputDir 'number-anomalies-2016-2021-detail.csv'
$summaryPath = Join-Path $OutputDir 'number-anomalies-2016-2021-summary.csv'
$matchedPath = Join-Path $OutputDir 'number-compare-2016-2021-all-rows.csv'

$anomalies | Sort-Object module, period, {[int]$_.nomor}, type | Export-Csv -NoTypeInformation -Encoding UTF8 $detailPath
$summary | Export-Csv -NoTypeInformation -Encoding UTF8 $summaryPath
@($matureRows + $productionRows) | Sort-Object module, period, {[int]$_.normalized_nomor}, source | Export-Csv -NoTypeInformation -Encoding UTF8 $matchedPath

[pscustomobject]@{
  mature_rows = $matureRows.Count
  production_rows = $productionRows.Count
  anomaly_rows = $anomalies.Count
  detail = (Resolve-Path $detailPath).Path
  summary = (Resolve-Path $summaryPath).Path
  all_rows = (Resolve-Path $matchedPath).Path
}
