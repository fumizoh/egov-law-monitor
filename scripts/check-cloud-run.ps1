# Compatible with Windows PowerShell 5.1 and PowerShell 7. No traffic changes.
[CmdletBinding()]
param([string]$RecordDirectory)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-DeploymentCommand {
    param([string]$Command, [string[]]$Arguments, [string]$Operation)
    $previousPreference = $ErrorActionPreference
    try {
        Get-Command $Command -ErrorAction Stop | Out-Null
        # PS 5.1 can otherwise throw a NativeCommandError containing raw stderr.
        $ErrorActionPreference = 'Continue'
        $output = & $Command @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } catch { throw "$Operation could not be executed. Raw error details were suppressed." }
    finally { $ErrorActionPreference = $previousPreference }
    if ($exitCode -ne 0) {
        # Never echo raw CLI errors: they can contain configuration or credentials.
        throw "$Operation failed (exit $exitCode). Inspect the CLI authentication/permissions and Cloud Build/Run console logs. Raw output was suppressed."
    }
    return ($output -join "`n")
}

function Get-DeploymentProperty {
    param($Object, [string]$Name, $Default = $null)
    if ($null -eq $Object) { return $Default }
    if ($Object -is [System.Collections.IDictionary]) {
        if ($Object.Contains($Name)) { return $Object[$Name] }
    } elseif ($Object.PSObject.Properties[$Name]) { return $Object.$Name }
    return $Default
}

function ConvertTo-DeploymentCanonicalJson {
    param($Value)
    if ($null -eq $Value) { return 'null' }
    if ($Value -is [System.Collections.IDictionary] -or $Value -is [pscustomobject]) {
        $names = if ($Value -is [System.Collections.IDictionary]) { @($Value.Keys) } else { @($Value.PSObject.Properties.Name) }
        $parts = foreach ($name in ($names | Sort-Object -CaseSensitive)) {
            $key = ConvertTo-Json -InputObject ([string]$name) -Compress
            $item = ConvertTo-DeploymentCanonicalJson (Get-DeploymentProperty $Value $name)
            "${key}:$item"
        }
        return '{' + ($parts -join ',') + '}'
    }
    if ($Value -is [System.Collections.IEnumerable] -and $Value -isnot [string]) {
        $parts = foreach ($item in $Value) { ConvertTo-DeploymentCanonicalJson $item }
        return '[' + ($parts -join ',') + ']'
    }
    return (ConvertTo-Json -InputObject $Value -Compress -Depth 100)
}

function Get-DeploymentHash {
    param($Value)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-DeploymentCanonicalJson $Value))
        return ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-', '').ToLowerInvariant()
    } finally { $sha.Dispose() }
}

function Get-CloudRunDocument {
    param([string]$Project, [string]$Region, [string]$Resource, [string]$Name)
    $argsList = @('run', $Resource, 'describe', $Name, "--project=$Project", "--region=$Region", '--format=json')
    $json = Invoke-DeploymentCommand 'gcloud' $argsList "Read Cloud Run $Resource"
    try { return ($json | ConvertFrom-Json) } catch { throw "Invalid Cloud Run $Resource JSON. No raw values were logged." }
}

function Get-CloudRunPolicy {
    param([string]$Project, [string]$Region, [string]$Service)
    $json = Invoke-DeploymentCommand 'gcloud' @('run', 'services', 'get-iam-policy', $Service, "--project=$Project", "--region=$Region", '--format=json') 'Read Cloud Run IAM'
    try {
        $policy = $json | ConvertFrom-Json
        # ETag is not a permission. Compare effective roles/members/conditions.
        $policy.PSObject.Properties.Remove('etag')
        foreach ($binding in @(Get-DeploymentProperty $policy 'bindings' @())) {
            $binding.members = @($binding.members | Sort-Object -CaseSensitive)
        }
        if ($policy.PSObject.Properties['bindings']) { $policy.bindings = @($policy.bindings | Sort-Object { ConvertTo-DeploymentCanonicalJson $_ }) }
        return $policy
    } catch { throw 'Invalid IAM response. No raw values were logged.' }
}

function Get-CloudRunTraffic {
    param($Service)
    $routes = @(Get-DeploymentProperty $Service.status 'traffic' @())
    $result = @($routes | Where-Object { (Get-DeploymentProperty $_ 'percent' 0) -gt 0 } | ForEach-Object {
        $name = Get-DeploymentProperty $_ 'revisionName' ''
        if (-not $name) { throw 'A serving traffic target has no resolved revision name.' }
        [pscustomobject]@{ revision = $name; percent = [int]$_.percent }
    } | Group-Object revision | ForEach-Object {
        [pscustomobject]@{ revision = $_.Name; percent = [int](($_.Group | Measure-Object percent -Sum).Sum) }
    } | Sort-Object revision)
    if (($result | Measure-Object percent -Sum).Sum -ne 100) { throw 'Traffic does not total 100%. Stop and inspect Cloud Run.' }
    return ,$result
}

function Get-CloudRunAnnotations {
    param($Metadata)
    $result = @{}
    $annotations = Get-DeploymentProperty $Metadata 'annotations' @{}
    $names = if ($annotations -is [System.Collections.IDictionary]) { @($annotations.Keys) } else { @($annotations.PSObject.Properties.Name) }
    foreach ($name in $names) {
        # Only deployment bookkeeping is ignored. Ingress/IAM-check/networking remain compared.
        if ($name -match '^(run\.googleapis\.com/(client-name|client-version|operation-id|urls|ingress-status|build-.*)|serving\.knative\.dev/(creator|lastModifier))$') { continue }
        $result[$name] = Get-DeploymentProperty $annotations $name
    }
    return $result
}

function Get-CloudRunFingerprints {
    param($Service, $Revision, $Policy)
    # Work on an in-memory copy; never persist raw env/Secret/configuration values.
    $spec = ($Revision.spec | ConvertTo-Json -Depth 100) | ConvertFrom-Json
    $environments = @()
    foreach ($container in $spec.containers) {
        $environments += ,@(Get-DeploymentProperty $container 'env' @() | Sort-Object name)
        $container.PSObject.Properties.Remove('env')
        $container.PSObject.Properties.Remove('image')
    }
    $account = Get-DeploymentProperty $spec 'serviceAccountName' ''
    $spec.PSObject.Properties.Remove('serviceAccountName')
    return [ordered]@{
        EnvironmentAndSecrets = Get-DeploymentHash $environments
        ServiceAccount = Get-DeploymentHash $account
        RuntimeSpec = Get-DeploymentHash $spec
        RevisionAnnotations = Get-DeploymentHash (Get-CloudRunAnnotations $Revision.metadata)
        ServiceAnnotations = Get-DeploymentHash (Get-CloudRunAnnotations $Service.metadata)
        IAM = Get-DeploymentHash $Policy
    }
}

function Assert-CloudRunFingerprints {
    param($Expected, $Actual)
    $names = @('EnvironmentAndSecrets', 'ServiceAccount', 'RuntimeSpec', 'RevisionAnnotations', 'ServiceAnnotations', 'IAM')
    $different = @($names | Where-Object { (Get-DeploymentProperty $Expected $_) -ne (Get-DeploymentProperty $Actual $_) })
    if ($different.Count) { throw ('Configuration mismatch: ' + ($different -join ', ') + '. Values were not logged; inspect Cloud Run securely. Traffic was not changed by this check.') }
}

function Invoke-CloudRunCheck {
    param([string]$Directory)
    try { $record = Get-Content -LiteralPath (Join-Path $Directory 'deployment.json') -Raw | ConvertFrom-Json }
    catch { throw 'Cannot read deployment.json. Check the record directory; record contents were not logged.' }
    if ($record.version -ne 1 -or $record.newRevision -notmatch '^[a-z0-9-]+$' -or $record.tag -notmatch '^[a-z][a-z0-9-]*$') { throw 'Invalid deployment record.' }
    $service = Get-CloudRunDocument $record.project $record.region 'services' $record.service
    $old = Get-CloudRunDocument $record.project $record.region 'revisions' $record.oldRevision
    $new = Get-CloudRunDocument $record.project $record.region 'revisions' $record.newRevision
    $policy = Get-CloudRunPolicy $record.project $record.region $record.service
    Assert-CloudRunFingerprints $record.fingerprints (Get-CloudRunFingerprints $service $old $policy)
    Assert-CloudRunFingerprints $record.fingerprints (Get-CloudRunFingerprints $service $new $policy)
    $traffic = Get-CloudRunTraffic $service
    Write-Host 'Current service traffic:'
    foreach ($route in $traffic) { Write-Host ("  {0}: {1}%" -f $route.revision, $route.percent) }
    if ((Get-DeploymentHash $traffic) -ne (Get-DeploymentHash $record.traffic)) { throw 'Traffic differs from the pre-deployment record. No automatic correction was performed.' }
    $tagged = @($service.status.traffic | Where-Object { (Get-DeploymentProperty $_ 'tag' '') -eq $record.tag -and $_.revisionName -eq $record.newRevision })
    if ($tagged.Count -ne 1) { throw 'The expected tag does not identify the new revision.' }
    $url = [uri]$tagged[0].url
    # Never send a token to a URL taken from an arbitrary/untrusted record.
    if ($url.Scheme -ne 'https' -or $url.Host -notmatch '^[a-z0-9-]+\.(?:[a-z0-9-]+\.)?run\.app$' -or $url.UserInfo -or $url.AbsolutePath -ne '/' -or $url.Query -or $url.Fragment -or $url.Port -ne 443) { throw 'Unexpected tagged Cloud Run URL.' }
    $token = Invoke-DeploymentCommand 'gcloud' @('auth', 'print-identity-token') 'Obtain a developer ID token'
    try {
        Write-Host 'Checking GET / on the tagged revision (no generation request).'
        $health = Invoke-RestMethod -Uri $url.AbsoluteUri -Method Get -Headers @{ Authorization = "Bearer $token" } -TimeoutSec 60 -MaximumRedirection 0
        if ((Get-DeploymentProperty $health 'status') -ne 'ok' -or (Get-DeploymentProperty $health 'service') -ne 'e-Gov Law Monitor AI Summary API') { throw 'Unexpected health response.' }
    } catch { throw 'Tagged GET / health check failed. Check invocation permission, ingress and revision logs. Response/error details were suppressed.' }
    finally { $token = $null }
    Write-Host 'PASS: runtime settings/IAM match, service traffic is unchanged, tagged GET / is healthy.'
}

# Deploy dot-sources the read-only helpers; normal invocation performs verification.
if ($MyInvocation.InvocationName -ne '.') {
    if (-not $RecordDirectory) { throw 'Specify -RecordDirectory from deploy-cloud-run.ps1.' }
    Invoke-CloudRunCheck $RecordDirectory
}
