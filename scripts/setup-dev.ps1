param([switch]$NoBuild)
$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
Set-Location $repoRoot
if (-not (Test-Path -LiteralPath '.env')) {
    $appBytes = [byte[]]::new(32)
    $dbBytes = [byte[]]::new(24)
    $passwordBytes = [byte[]]::new(18)
    [Security.Cryptography.RandomNumberGenerator]::Fill($appBytes)
    [Security.Cryptography.RandomNumberGenerator]::Fill($dbBytes)
    [Security.Cryptography.RandomNumberGenerator]::Fill($passwordBytes)
    $template = Get-Content -Raw '.env.example'
    $template = $template -replace '(?m)^APP_KEY=.*$', ('APP_KEY=base64:' + [Convert]::ToBase64String($appBytes))
    $template = $template -replace '(?m)^DB_PASSWORD=.*$', ('DB_PASSWORD=' + [Convert]::ToHexString($dbBytes))
    $template = $template -replace '(?m)^DEMO_PASSWORD=.*$', ('DEMO_PASSWORD=R3!' + [Convert]::ToHexString($passwordBytes))
    Set-Content -LiteralPath '.env' -Value $template -Encoding utf8NoBOM
}
if (-not $NoBuild) {
    docker compose build
    if ($LASTEXITCODE -ne 0) { throw 'Container build failed' }
}
docker compose up -d
if ($LASTEXITCODE -ne 0) { throw 'Container startup failed' }
docker compose exec -T backend php artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'Database migration failed' }
docker compose exec -T backend php artisan db:seed --force
if ($LASTEXITCODE -ne 0) { throw 'Database seed failed' }
Write-Host 'dev: http://localhost:3180. Demo password is in the local .env file (DEMO_PASSWORD).'
