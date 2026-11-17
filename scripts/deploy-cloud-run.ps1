# Creates a no-traffic revision only. Never promotes or rolls back traffic.
[CmdletBinding()]
param(
    [string]$RepositoryPath = (Split-Path $PSScriptRoot -Parent),
    [string]$Service = 'egov-law-monitor-summary',
    [string]$Region = 'asia-northeast1',
    [string]$RecordRoot = (Join-Path $env:LOCALAPPDATA 'egov-law-monitor\deployments')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'check-cloud-run.ps1')

$work = $null
Push-Location -LiteralPath $RepositoryPath
try {
    $root = Invoke-DeploymentCommand 'git' @('rev-parse', '--show-toplevel') 'Locate repository'
    if ([IO.Path]::GetFullPath($root) -ne [IO.Path]::GetFullPath((Get-Location).Path)) { throw 'RepositoryPath must be the repository root.' }
    $dirty = Invoke-DeploymentCommand 'git' @('status', '--porcelain', '--untracked-files=all') 'Check worktree'
    if ($dirty) { throw 'Worktree is not clean (including untracked files). Commit/review changes before deployment.' }
    $main = Invoke-DeploymentCommand 'git' @('rev-parse', 'main') 'Read main'
    $remote = Invoke-DeploymentCommand 'git' @('rev-parse', 'origin/main') 'Read origin/main'
    if ($main -ne $remote) { throw 'main and origin/main differ. Fetch/synchronize manually; this script never merges or resets.' }
    $config = Invoke-DeploymentCommand 'git' @('show', 'main:src/config.py') 'Read deployment project'
    if ($config -notmatch '(?m)^PROJECT_ID\s*=\s*"([a-z][a-z0-9-]+)"\s*$') { throw 'Cannot read PROJECT_ID from main:src/config.py.' }
    $project = $Matches[1]
    $serviceBefore = Get-CloudRunDocument $project $Region 'services' $Service
    $oldRevision = $serviceBefore.status.latestReadyRevisionName
    if ($serviceBefore.status.latestCreatedRevisionName -ne $oldRevision) { throw 'A pending/failed newer revision exists. Inspect it before deploying.' }
    $traffic = Get-CloudRunTraffic $serviceBefore
    if (-not @($traffic | Where-Object { $_.revision -eq $oldRevision }).Count) { throw 'Latest service template is not serving traffic. Inspect template/serving revision settings first.' }
    $revisionBefore = Get-CloudRunDocument $project $Region 'revisions' $oldRevision
    $policyBefore = Get-CloudRunPolicy $project $Region $Service
    $fingerprints = Get-CloudRunFingerprints $serviceBefore $revisionBefore $policyBefore

    $id = [guid]::NewGuid().ToString('N')
    $tag = 'check-' + $id.Substring(0, 12)
    $suffix = 'deploy-' + $id.Substring(0, 12)
    $newRevision = "$Service-$suffix"
    if ($newRevision.Length -gt 63) { throw 'Service name is too long for the generated revision suffix.' }
    $work = Join-Path ([IO.Path]::GetTempPath()) ("egov-cloud-run-$id")
    New-Item -ItemType Directory -Path $work | Out-Null
    $zip = Join-Path $work 'source.zip'
    $source = Join-Path $work 'source'
    # Freeze the checked main commit, even if another process updates main later.
    Invoke-DeploymentCommand 'git' @('archive', '--format=zip', "--output=$zip", $main, 'Dockerfile', 'requirements.txt', 'main.py', 'src') 'Archive main' | Out-Null
    Expand-Archive -LiteralPath $zip -DestinationPath $source
    foreach ($file in Get-ChildItem -LiteralPath $source -Recurse -File -Force) {
        if ($file.Name -match '(^\.env($|\.)|\.(pem|key|p12|pfx)$|credentials.*\.json$|service[-_]account.*\.json$)' -or
            (Get-Content -LiteralPath $file.FullName -Raw) -match '(-----BEGIN [A-Z ]*PRIVATE KEY-----|"private_key"\s*:|"type"\s*:\s*"service_account")') {
            throw 'Potential credential material found in archived source. Upload cancelled; no file contents were logged.'
        }
    }
    $recordDir = Join-Path ([IO.Path]::GetFullPath($RecordRoot)) $id
    # Records must not enter the repository/build context.
    if ($recordDir.StartsWith(([IO.Path]::GetFullPath($root).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar), [StringComparison]::OrdinalIgnoreCase)) { throw 'RecordRoot must be outside the repository.' }
    New-Item -ItemType Directory -Path $recordDir | Out-Null
    $record = [ordered]@{
        version = 1; createdAtUtc = [DateTime]::UtcNow.ToString('o'); commit = $main
        project = $project; region = $Region; service = $Service
        oldRevision = $oldRevision; newRevision = $newRevision; tag = $tag
        traffic = $traffic; fingerprints = $fingerprints
    }
    $record | ConvertTo-Json -Depth 20 | Set-Content -LiteralPath (Join-Path $recordDir 'deployment.json') -Encoding UTF8
    Write-Host "Deployment record (no raw settings): $recordDir"
    Write-Host 'Building a new revision with --no-traffic. Do not approve requests to change APIs/IAM.'
    Invoke-DeploymentCommand 'gcloud' @('run', 'deploy', $Service, "--project=$project", "--region=$Region", "--source=$source", '--no-traffic', "--tag=$tag", "--revision-suffix=$suffix") 'No-traffic deployment' | Out-Null

    # Verification is deliberately read-only; health invocation is a separate script.
    $after = Get-CloudRunDocument $project $Region 'services' $Service
    $new = Get-CloudRunDocument $project $Region 'revisions' $newRevision
    $policyAfter = Get-CloudRunPolicy $project $Region $Service
    Assert-CloudRunFingerprints $fingerprints (Get-CloudRunFingerprints $after $new $policyAfter)
    if ((Get-DeploymentHash (Get-CloudRunTraffic $after)) -ne (Get-DeploymentHash $traffic)) { throw 'Serving traffic changed. Stop and inspect Cloud Run; this script will not change it automatically.' }
    Write-Host "PASS: new revision $newRevision created; previous traffic maintained."
    Write-Host "Next: .\scripts\check-cloud-run.ps1 -RecordDirectory '$recordDir'"
} finally {
    Pop-Location
    if ($work -and (Test-Path -LiteralPath $work)) {
        $resolved = [IO.Path]::GetFullPath($work)
        $tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/')
        if ([IO.Path]::GetDirectoryName($resolved) -ne $tempRoot -or [IO.Path]::GetFileName($resolved) -notmatch '^egov-cloud-run-[a-f0-9]{32}$' -or
            ((Get-Item -LiteralPath $resolved -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Unsafe temporary cleanup target; cleanup refused.' }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
}
