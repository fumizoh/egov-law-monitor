# No gcloud, Git writes, network or real deployment. Run in PS 5.1 and PS 7.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$repository = Split-Path $PSScriptRoot -Parent
$deployScript = Join-Path $repository 'scripts\deploy-cloud-run.ps1'
$checkScript = Join-Path $repository 'scripts\check-cloud-run.ps1'
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('egov-deploy-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $testRoot | Out-Null
$global:cloudTest_checks = 0
function Assert-Test($Condition, $Message) {
    if (-not $Condition) { throw "TEST FAILED: $Message" }
    $global:cloudTest_checks++
}
function Reset-Fixture {
    $global:cloudTest_caseId = [guid]::NewGuid().ToString('N')
    $global:cloudTest_mockRepo = Join-Path $testRoot $global:cloudTest_caseId
    $global:cloudTest_records = Join-Path $testRoot ($global:cloudTest_caseId + '-records')
    New-Item -ItemType Directory -Path (Join-Path $global:cloudTest_mockRepo 'src') -Force | Out-Null
    foreach ($name in @('Dockerfile', 'requirements.txt', 'main.py')) { Set-Content -LiteralPath (Join-Path $global:cloudTest_mockRepo $name) -Value '# fixture' }
    Set-Content -LiteralPath (Join-Path $global:cloudTest_mockRepo 'src\config.py') -Value 'PROJECT_ID = "test-project"'
    Set-Content -LiteralPath (Join-Path $global:cloudTest_mockRepo '.env') -Value 'TOKEN=SECRET_SENTINEL'
    $global:cloudTest_mode = ''; $global:cloudTest_calls = @(); $global:cloudTest_healthCalls = 0; $global:cloudTest_archiveParent = $null
    $global:cloudTest_oldName = 'test-service-revision-old'
    $global:cloudTest_service = @{
        metadata = @{ annotations = @{ 'run.googleapis.com/ingress' = 'all' } }
        status = @{ latestReadyRevisionName = $global:cloudTest_oldName; latestCreatedRevisionName = $global:cloudTest_oldName
            traffic = @(@{ revisionName = $global:cloudTest_oldName; percent = 100 }) }
    }
    $global:cloudTest_old = @{
        metadata = @{ annotations = @{ 'run.googleapis.com/startup-cpu-boost' = 'true' } }
        spec = @{ serviceAccountName = 'runtime@test.iam.gserviceaccount.com'; timeoutSeconds = 120; containerConcurrency = 10
            volumes = @(@{ name = 'secretvol'; secret = @{ secretName = 'mounted-secret'; items = @(@{ key = '1'; path = 'key' }) } })
            containers = @(@{ name = 'app'; image = 'old-image'; env = @(
                @{ name = 'PLAIN'; value = 'CONFIG_SECRET_SENTINEL' },
                @{ name = 'SECRET'; valueFrom = @{ secretKeyRef = @{ name = 'secret-name'; key = '1' } } }
            ); resources = @{ limits = @{ memory = '512Mi'; cpu = '1' } } }) }
    }
    $global:cloudTest_new = $null
}
function git {
    $global:LASTEXITCODE = 0
    $a = @($args)
    switch ($a[0]) {
        'rev-parse' {
            if ($a[1] -eq '--show-toplevel') { return $global:cloudTest_mockRepo }
            if ($global:cloudTest_mode -eq 'mismatch' -and $a[1] -eq 'origin/main') { return ('b' * 40) }
            return ('a' * 40)
        }
        'status' { if ($global:cloudTest_mode -eq 'dirty') { return '?? untracked.txt' }; return }
        'show' { return 'PROJECT_ID = "test-project"' }
        'archive' {
            Assert-Test ($a[3] -eq ('a' * 40)) 'archive freezes checked main SHA'
            Assert-Test (($a[4..7] -join ',') -eq 'Dockerfile,requirements.txt,main.py,src' -and $a.Count -eq 8) 'archive includes only the four approved paths'
            $zip = $a[2].Substring('--output='.Length)
            $global:cloudTest_archiveParent = Split-Path $zip -Parent
            if ($global:cloudTest_mode -eq 'archive-fail') { $global:LASTEXITCODE = 19; return 'PRIVATE_SENTINEL' }
            if ($global:cloudTest_mode -eq 'credential') { Set-Content -LiteralPath (Join-Path $global:cloudTest_mockRepo 'src\credentials.json') -Value '{"private_key":"PRIVATE_SENTINEL"}' }
            $paths = @($a[4..7] | ForEach-Object { Join-Path $global:cloudTest_mockRepo $_ })
            Compress-Archive -Path $paths -DestinationPath $zip
            return
        }
        default { throw 'Unexpected Git operation in offline test' }
    }
}
function gcloud {
    $global:LASTEXITCODE = 0
    $a = @($args); $global:cloudTest_calls += ,$a
    if ($global:cloudTest_mode -eq 'read-fail') { $global:LASTEXITCODE = 23; return 'CONFIG_SECRET_SENTINEL' }
    if ($a[0] -eq 'auth') { return 'TOKEN_SECRET_SENTINEL' }
    if ($a[1] -eq 'deploy') {
        Assert-Test ($a -contains '--no-traffic') 'deployment never promotes traffic'
        $source = ($a | Where-Object { $_ -like '--source=*' }).Substring(9)
        Assert-Test (-not (Test-Path -LiteralPath (Join-Path $source '.env'))) 'local .env excluded from source upload'
        Assert-Test (-not ($a -match '^--(set|clear|update|remove)-(env|secret)|^--service-account|^--allow-unauthenticated')) 'deployment does not change protected settings'
        if ($global:cloudTest_mode -eq 'deploy-fail') { $global:LASTEXITCODE = 17; return 'CONFIG_SECRET_SENTINEL' }
        $tag = ($a | Where-Object { $_ -like '--tag=*' }).Substring(6)
        $suffix = ($a | Where-Object { $_ -like '--revision-suffix=*' }).Substring(18)
        $name = $a[2] + '-' + $suffix
        $global:cloudTest_new = ($global:cloudTest_old | ConvertTo-Json -Depth 30 | ConvertFrom-Json)
        $global:cloudTest_new.spec.containers[0].image = 'new-image'
        $global:cloudTest_service.status.latestCreatedRevisionName = $name
        $global:cloudTest_service.status.latestReadyRevisionName = $name
        $global:cloudTest_service.status.traffic += @{ revisionName = $name; tag = $tag; url = "https://$tag---test.a.run.app" }
        if ($global:cloudTest_mode -eq 'changed-env') { $global:cloudTest_new.spec.containers[0].env[0].value = 'CHANGED_SECRET_SENTINEL' }
        if ($global:cloudTest_mode -eq 'changed-traffic') { $global:cloudTest_service.status.traffic[0].revisionName = $name }
        return 'deployment completed'
    }
    if ($a[2] -eq 'get-iam-policy') { return '{"etag":"ignored","bindings":[{"role":"roles/run.invoker","members":["user:test@example.test"]}]}' }
    if ($a[1] -eq 'services' -and $a[2] -eq 'describe') { return ($global:cloudTest_service | ConvertTo-Json -Depth 30) }
    if ($a[1] -eq 'revisions') {
        if ($a[3] -eq $global:cloudTest_oldName) { return ($global:cloudTest_old | ConvertTo-Json -Depth 30) }
        return ($global:cloudTest_new | ConvertTo-Json -Depth 30)
    }
    throw 'Unexpected gcloud operation in offline test'
}
function Invoke-RestMethod {
    param([uri]$Uri, $Method, $Headers, $TimeoutSec, $MaximumRedirection)
    $global:cloudTest_healthCalls++
    Assert-Test ($Method -eq 'Get' -and $Uri.AbsoluteUri -like 'https://check-*---test.a.run.app/') 'only tagged GET / is invoked'
    Assert-Test ($Headers.Authorization -eq 'Bearer TOKEN_SECRET_SENTINEL' -and $MaximumRedirection -eq 0) 'ID token is used without redirects'
    if ($global:cloudTest_mode -eq 'health-fail') { throw 'TOKEN_SECRET_SENTINEL' }
    return @{ status = 'ok'; service = 'e-Gov Law Monitor AI Summary API' }
}
try {
    Reset-Fixture
    $log = @(& $deployScript -RepositoryPath $global:cloudTest_mockRepo -RecordRoot $global:cloudTest_records -Service 'test-service' 6>&1) -join "`n"
    $recordDir = (Get-ChildItem -LiteralPath $global:cloudTest_records -Directory)[0].FullName
    $recordText = Get-Content -LiteralPath (Join-Path $recordDir 'deployment.json') -Raw
    Assert-Test ($recordText -notmatch 'SECRET_SENTINEL') 'record contains no raw settings or credentials'
    $checkLog = @(& $checkScript -RecordDirectory $recordDir 6>&1) -join "`n"
    Assert-Test ($global:cloudTest_healthCalls -eq 1 -and $checkLog -match 'PASS') 'standalone check succeeds with image-only difference'
    Assert-Test (($log + $checkLog) -notmatch 'SECRET_SENTINEL') 'logs contain no secrets/tokens'
    $global:cloudTest_mode = 'health-fail'; $caught = $false
    try { $null = @(& $checkScript -RecordDirectory $recordDir 6>&1) }
    catch { $caught = $true; Assert-Test ($_.Exception.Message -notmatch 'SECRET_SENTINEL') 'health error is redacted' }
    Assert-Test $caught 'stop on failed health invocation'
    $global:cloudTest_mode = ''
    Assert-Test (-not ($global:cloudTest_calls | Where-Object { $_ -contains 'update-traffic' })) 'no traffic update commands exist'
    $sourceArg = @($global:cloudTest_calls | Where-Object { $_ -contains 'deploy' })[0] | Where-Object { $_ -like '--source=*' }
    $sourceParent = Split-Path ($sourceArg.Substring(9)) -Parent
    Assert-Test (-not (Test-Path -LiteralPath $sourceParent)) 'temporary archive and source cleaned after success'

    # Actual comparison helpers detect protected changes, never print their values.
    . $checkScript
    $baseService = $global:cloudTest_service | ConvertTo-Json -Depth 30 | ConvertFrom-Json
    $baseOld = $global:cloudTest_old | ConvertTo-Json -Depth 30 | ConvertFrom-Json
    $policy = [pscustomobject]@{ bindings = @() }
    $expected = Get-CloudRunFingerprints $baseService $baseOld $policy
    foreach ($fault in @('env', 'secret', 'mounted-secret', 'account', 'memory', 'timeout', 'ingress', 'iam')) {
        $rev = $global:cloudTest_old | ConvertTo-Json -Depth 30 | ConvertFrom-Json
        $svc = $global:cloudTest_service | ConvertTo-Json -Depth 30 | ConvertFrom-Json
        $pol = [pscustomobject]@{ bindings = @() }
        switch ($fault) {
            'env' { $rev.spec.containers[0].env[0].value = 'SENSITIVE' }
            'secret' { $rev.spec.containers[0].env[1].valueFrom.secretKeyRef.key = '2' }
            'mounted-secret' { $rev.spec.volumes[0].secret.items[0].key = '2' }
            'account' { $rev.spec.serviceAccountName = 'different' }
            'memory' { $rev.spec.containers[0].resources.limits.memory = '1Gi' }
            'timeout' { $rev.spec.timeoutSeconds = 999 }
            'ingress' { $svc.metadata.annotations.'run.googleapis.com/ingress' = 'internal' }
            'iam' { $pol.bindings = @('changed') }
        }
        $caught = $false
        try { Assert-CloudRunFingerprints $expected (Get-CloudRunFingerprints $svc $rev $pol) } catch { $caught = $true; Assert-Test ($_.Exception.Message -notmatch 'SENSITIVE') 'mismatch message is redacted' }
        Assert-Test $caught "detect $fault difference"
    }
    foreach ($fault in @('dirty', 'mismatch', 'credential', 'archive-fail', 'read-fail', 'deploy-fail', 'changed-env', 'changed-traffic')) {
        Reset-Fixture; $global:cloudTest_mode = $fault; $caught = $false
        try { $null = @(& $deployScript -RepositoryPath $global:cloudTest_mockRepo -RecordRoot $global:cloudTest_records -Service 'test-service' 6>&1) }
        catch { $caught = $true; Assert-Test ($_.Exception.Message -notmatch 'SECRET_SENTINEL|PRIVATE_SENTINEL') 'failure output is redacted' }
        Assert-Test $caught "stop on $fault"
        if ($fault -in @('dirty', 'mismatch', 'credential', 'archive-fail', 'read-fail')) { Assert-Test (-not ($global:cloudTest_calls | Where-Object { $_ -contains 'deploy' })) "no deployment on $fault" }
        if ($global:cloudTest_archiveParent) { Assert-Test (-not (Test-Path -LiteralPath $global:cloudTest_archiveParent)) 'temporary files cleaned on all archive/deploy failures' }
        foreach ($deployment in @($global:cloudTest_calls | Where-Object { $_ -contains 'deploy' })) {
            $src = ($deployment | Where-Object { $_ -like '--source=*' }).Substring(9)
            Assert-Test (-not (Test-Path -LiteralPath (Split-Path $src -Parent))) 'cleanup after deployment failure'
        }
    }
    Write-Host "PASS: $global:cloudTest_checks offline deployment checks; no Git writes, gcloud or network."
} finally {
    $resolved = [IO.Path]::GetFullPath($testRoot)
    if ([IO.Path]::GetDirectoryName($resolved) -ne [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/') -or [IO.Path]::GetFileName($resolved) -notmatch '^egov-deploy-test-[a-f0-9]{32}$') { throw 'Unsafe test cleanup target.' }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
