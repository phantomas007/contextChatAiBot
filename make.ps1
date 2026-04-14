param(
    [Parameter(Position=0)]
    [string]$Command = "help",

    [string]$ENV = "local",

    [string]$CMD = "",

    [switch]$ForceBuild,

    # Bash-стиль: .\make console CMD="app:foo" (без -CMD в PowerShell)
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$RemainingArgs = @()
)

# Подхват CMD=value из хвоста аргументов
if (-not $CMD -and $RemainingArgs.Count -gt 0) {
    foreach ($token in $RemainingArgs) {
        if ($token -match '^CMD=(.+)$') {
            $CMD = $Matches[1].Trim('"', "'")
            break
        }
    }
}

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
chcp 65001 | Out-Null

# --- Colors ---
function Write-Step  { param([string]$msg) Write-Host "  > $msg" -ForegroundColor Cyan }
function Write-Ok    { param([string]$msg) Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Fail  { param([string]$msg) Write-Host "  [FAIL] $msg" -ForegroundColor Red; exit 1 }
function Write-Info  { param([string]$msg) Write-Host "  i $msg" -ForegroundColor Yellow }
function Write-Title { param([string]$msg) Write-Host "`n== $msg ==" -ForegroundColor Magenta }

# --- Environment ---
$validEnvs = @("local", "prod")
if ($ENV -notin $validEnvs) {
    Write-Fail "Unknown env: '$ENV'. Allowed: local, prod"
}

$IS_PROD    = ($ENV -eq "prod")
$APP_ENV    = if ($IS_PROD) { "prod" } else { "dev" }
$APP_DEBUG  = if ($IS_PROD) { "0"    } else { "1"   }

$EXEC_BASE = "docker compose exec -e APP_ENV=$APP_ENV -e APP_DEBUG=$APP_DEBUG -w /var/www/html/app php"

# --- Helpers ---
function Exec-Docker {
    param([string]$cmd)
    Invoke-Expression "$EXEC_BASE $cmd"
    if ($LASTEXITCODE -ne 0) { Write-Fail "Command failed: $cmd" }
}

function Read-DotEnv {
    $vars = @{}
    if (Test-Path ".env") {
        Get-Content ".env" | ForEach-Object {
            if ($_ -match "^\s*([^#][^=]*)\s*=\s*(.*)\s*$") {
                $vars[$Matches[1].Trim()] = $Matches[2].Trim()
            }
        }
    }
    return $vars
}

function Wait-ForDb {
    $dotenv = Read-DotEnv
    $pgUser = $dotenv["POSTGRES_USER"]

    Write-Step "Waiting for database..."
    $i = 0
    while ($true) {
        $null = docker compose exec -T db pg_isready -U "$pgUser" 2>&1
        if ($LASTEXITCODE -eq 0) { Write-Ok "Database ready"; break }
        $i++
        if ($i -ge 30) { Write-Fail "DB not ready in 30s" }
        Write-Host "    Waiting... ($i/30)`r" -NoNewline -ForegroundColor Yellow
        Start-Sleep -Seconds 1
    }
}

# --- Commands ---

function Cmd-Run {
    Write-Title "Run project [ENV=$ENV | APP_ENV=$APP_ENV | APP_DEBUG=$APP_DEBUG]"

    if (-not (Test-Path "app/.env.local")) {
        if (Test-Path "app/.env.local.example") {
            Copy-Item "app/.env.local.example" "app/.env.local"
            Write-Ok "Created app/.env.local from example"
        } else {
            Write-Fail "app/.env.local not found and no app/.env.local.example"
        }
    }

    $env:APP_ENV   = $APP_ENV
    $env:APP_DEBUG = $APP_DEBUG

    $phpImg = docker images -q context-php:latest 2>$null
    $nginxImg = docker images -q context-nginx:latest 2>$null
    $imagesExist = $phpImg -and $nginxImg
    if ($imagesExist -and -not $ForceBuild) {
        Write-Step "Images exist, skip build (use -ForceBuild to rebuild)"
        Write-Ok "Images up to date"
    } else {
        Write-Step "Building images..."
        $env:DOCKER_BUILDKIT = "0"
        docker compose build
        $env:DOCKER_BUILDKIT = ""
        if ($LASTEXITCODE -ne 0) { Write-Fail "docker compose build failed" }
        Write-Ok "Images up to date"
    }

    Write-Step "Starting infra [APP_ENV=$APP_ENV, APP_DEBUG=$APP_DEBUG]..."
    # Локальный Ollama в compose: раскомментируйте сервис ollama в docker-compose.yml и строку ниже:
    # docker compose up -d --force-recreate php nginx ollama
    docker compose up -d --force-recreate php nginx
    if ($LASTEXITCODE -ne 0) { Write-Fail "docker compose up failed" }
    Write-Ok "Infra started (php, nginx, db, rabbitmq)"

    Wait-ForDb

    # Clear cache before composer - post-install runs cache:clear which fails on Windows+Docker
    docker compose exec -T -w /var/www/html/app php sh -c 'rm -rf var/cache 2>/dev/null || true'

    Write-Step "Installing Composer deps"
    if ($IS_PROD) {
        Exec-Docker "composer install --no-dev --optimize-autoloader --no-interaction"
    } else {
        Exec-Docker "composer install --no-interaction"
    }
    Write-Ok "Deps installed"

    Write-Step "Running migrations"
    Exec-Docker "php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"
    Write-Ok "Migrations done"

    Write-Step "Clearing cache (Symfony + Redis)"
    docker compose exec -T -w /var/www/html/app php sh -c 'rm -rf var/cache 2>/dev/null || true'
    Exec-Docker "php bin/console cache:clear"
    Cmd-RedisClear
    Write-Ok "Cache cleared"

    if ($IS_PROD) {
        Write-Step "Warming cache (prod)"
        Exec-Docker "php bin/console cache:warmup"
        Write-Ok "Cache warmed"
    }

    Write-Step "Starting worker [supervisor + cron]..."
    docker compose up -d --force-recreate worker
    if ($LASTEXITCODE -ne 0) { Write-Fail "worker start failed" }
    Write-Ok "Worker started"

    if (-not $IS_PROD) {
        Write-Title "Linters"
        Cmd-Lint
    } else {
        Write-Info "Linters skipped in prod"
    }

    Write-Title "Done"
    Write-Ok "App: http://localhost"
}

function Cmd-Build {
    Write-Title "Build images"
    $env:APP_ENV   = $APP_ENV
    $env:APP_DEBUG = $APP_DEBUG
    $env:DOCKER_BUILDKIT = "0"
    docker compose build
    $env:DOCKER_BUILDKIT = ""
    if ($LASTEXITCODE -ne 0) { Write-Fail "build failed" }
    Write-Ok "Images built"
}

function Cmd-Stop {
    Write-Title "Stop containers"
    docker compose stop
    Write-Ok "Containers stopped"
}

function Cmd-DbReset {
    Write-Title "Reset DB (remove volume)"
    Write-Info "Clearing Redis (avoid stale group_id after DB reset)..."
    Cmd-RedisClear
    Write-Info "Stopping and removing volumes..."
    docker compose down -v
    if ($LASTEXITCODE -ne 0) { Write-Fail "down failed" }
    Write-Ok "Volume removed - DB will be recreated on next .\make run"
}

function Cmd-Restart {
    Write-Title "Restart PHP container"
    docker compose restart php
    Write-Ok "PHP restarted"
}

function Cmd-WorkerRestart {
    Write-Title "Restart workers (supervisor restart all)"
    docker compose exec worker supervisorctl -c /etc/supervisor/supervisord.conf restart all
    if ($LASTEXITCODE -ne 0) { Write-Fail "supervisorctl failed" }
    Write-Ok "Workers and crond restarted"
}

function Cmd-Migrate {
    Write-Title "Migrations [ENV=$ENV]"
    Exec-Docker "php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"
    Write-Ok "Migrations done"
}

function Cmd-CacheClear {
    Write-Title "Clear cache [ENV=$ENV]"
    Exec-Docker "php bin/console cache:clear"
    Cmd-RedisClear
    Write-Ok "Cache cleared"
}

function Cmd-RedisClear {
    docker compose exec -T redis redis-cli FLUSHDB 2>$null
}

function Cmd-Console {
    if (-not $CMD) {
        Write-Fail "Specify: .\make console -CMD 'cache:clear'"
    }
    Write-Title "bin/console $CMD"
    Exec-Docker "php bin/console $CMD"
}

function Cmd-CsCheck {
    Write-Title "PHP CS Fixer (dry-run)"
    Exec-Docker "composer cs-check"
    Write-Ok "CS OK"
}

function Cmd-CsFix {
    Write-Title "PHP CS Fixer (fix)"
    Exec-Docker "composer cs-fix"
    Write-Ok "CS fixed"
}

function Cmd-Phpstan {
    Write-Title "PHPStan"
    Exec-Docker "composer phpstan"
    Write-Ok "PHPStan OK"
}

function Cmd-Lint {
    Write-Step "Running CS Fixer..."
    $csResult = docker compose exec -e APP_ENV=$APP_ENV -e APP_DEBUG=$APP_DEBUG -w /var/www/html/app php composer cs-check 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host $csResult
        Write-Fail "CS violations. Run: .\make cs-fix"
    }
    Write-Ok "CS Fixer: OK"

    Write-Step "Running PHPStan..."
    $stanResult = docker compose exec -e APP_ENV=$APP_ENV -e APP_DEBUG=$APP_DEBUG -w /var/www/html/app php composer phpstan 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host $stanResult
        Write-Fail "PHPStan errors"
    }
    Write-Ok "PHPStan: OK"
}

function Cmd-Help {
    Write-Host ""
    Write-Host "  Usage: " -NoNewline
    Write-Host ".\make <cmd> [-ENV local|prod]" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  Env (-ENV):" -ForegroundColor Yellow
    Write-Host "    local  ->  APP_ENV=dev,  APP_DEBUG=1, linters run"
    Write-Host "    prod   ->  APP_ENV=prod, APP_DEBUG=0, cache warmup"
    Write-Host ""
    Write-Host "  Main:" -ForegroundColor Yellow
    $cmds = @(
        @{ name = "run";            desc = "Start: infra -> composer -> migrate -> cache -> worker" },
        @{ name = "build";          desc = "Build images (needs internet)" },
        @{ name = "stop";           desc = "Stop all containers" },
        @{ name = "restart";        desc = "Restart PHP container" },
        @{ name = "worker-restart"; desc = "Restart workers and crond" },
        @{ name = "db-reset";       desc = "Remove DB volume" }
    )
    foreach ($c in $cmds) {
        Write-Host "    " -NoNewline
        Write-Host ("{0,-14}" -f $c.name) -ForegroundColor Cyan -NoNewline
        Write-Host $c.desc
    }
    Write-Host ""
    Write-Host "  Symfony:" -ForegroundColor Yellow
    $cmds2 = @(
        @{ name = "migrate";      desc = "Run migrations" },
        @{ name = "cache-clear";  desc = "Clear Symfony + Redis cache" },
        @{ name = "redis-clear";  desc = "Clear Redis only (msg_save_*, locks)" },
        @{ name = "console";      desc = "Run: .\make console -CMD 'cache:clear'" }
    )
    foreach ($c in $cmds2) {
        Write-Host "    " -NoNewline
        Write-Host ("{0,-14}" -f $c.name) -ForegroundColor Cyan -NoNewline
        Write-Host $c.desc
    }
    Write-Host ""
    Write-Host "  Linters:" -ForegroundColor Yellow
    $cmds3 = @(
        @{ name = "lint";         desc = "All linters (cs-check + phpstan)" },
        @{ name = "cs-check";     desc = "Check style (dry-run)" },
        @{ name = "cs-fix";       desc = "Fix style" },
        @{ name = "phpstan";      desc = "Static analysis" }
    )
    foreach ($c in $cmds3) {
        Write-Host "    " -NoNewline
        Write-Host ("{0,-14}" -f $c.name) -ForegroundColor Cyan -NoNewline
        Write-Host $c.desc
    }
    Write-Host ""
    Write-Host "  Examples:" -ForegroundColor Yellow
    Write-Host "    " -NoNewline; Write-Host ".\make build" -ForegroundColor Cyan -NoNewline; Write-Host "            # build images"
    Write-Host "    " -NoNewline; Write-Host ".\make run" -ForegroundColor Cyan -NoNewline; Write-Host "               # local"
    Write-Host "    " -NoNewline; Write-Host ".\make run -ENV prod" -ForegroundColor Cyan -NoNewline; Write-Host "        # prod"
    Write-Host "    " -NoNewline; Write-Host ".\make migrate -ENV prod" -ForegroundColor Cyan -NoNewline; Write-Host "    # migrations in prod"
    Write-Host ""
}

# --- Dispatcher ---
switch ($Command) {
    "run"            { Cmd-Run }
    "build"          { Cmd-Build }
    "stop"           { Cmd-Stop }
    "restart"        { Cmd-Restart }
    "worker-restart" { Cmd-WorkerRestart }
    "db-reset"       { Cmd-DbReset }
    "migrate"        { Cmd-Migrate }
    "cache-clear"    { Cmd-CacheClear }
    "redis-clear"    { Cmd-RedisClear; Write-Ok "Redis cleared" }
    "console"        { Cmd-Console }
    "cs-check"       { Cmd-CsCheck }
    "cs-fix"         { Cmd-CsFix }
    "phpstan"        { Cmd-Phpstan }
    "lint"           { Cmd-Lint }
    "help"           { Cmd-Help }
    default {
        Write-Host "  [x] Unknown command: '$Command'" -ForegroundColor Red
        Write-Host "  Run " -NoNewline; Write-Host ".\make help" -ForegroundColor Cyan -NoNewline; Write-Host " for list"
        exit 1
    }
}
