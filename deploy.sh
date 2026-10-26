#!/usr/bin/env bash
# Publica no servidor (Hostinger) a versão atual da main. Executado pelo GitHub Actions a cada push na main,
# depois que os testes passam; também pode ser rodado à mão no servidor: `git show origin/main:deploy.sh | bash`.
#
# Ordem: modo manutenção → código da main → dependências (só se o composer.lock mudou) →
# backup do banco (só se houver migration pendente) → migrations → limpeza de cache → site no ar.
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/domains/novaconquista.org/public_html/yuv-metrics}"
PHP="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
COMPOSER="${COMPOSER_BIN:-/usr/local/bin/composer}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/yuv-metrics}"
BACKUPS_KEPT=15

cd "$APP_DIR"
git fetch --quiet origin main
OLD=$(git rev-parse HEAD)
NEW=$(git rev-parse origin/main)
if [ "$OLD" = "$NEW" ]; then
    echo "Servidor já está na versão ${NEW:0:7}; nada a publicar."
    exit 0
fi
echo "Publicando ${OLD:0:7} → ${NEW:0:7}"

"$PHP" artisan down --retry=15 >/dev/null || true
trap '"$PHP" artisan up >/dev/null || true' EXIT

git reset --hard --quiet "$NEW"

changed=$(git diff --name-only "$OLD" "$NEW")
if grep -qx 'composer.lock' <<< "$changed"; then
    echo "composer.lock mudou: instalando dependências"
    "$PHP" "$COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
fi

# --pending pode sair com código de erro quando há pendências; por isso a saída é capturada antes do teste.
pending=$("$PHP" artisan migrate:status --pending --no-ansi || true)
if grep -q 'Pending' <<< "$pending"; then
    mkdir -p "$BACKUP_DIR"
    defaults=$(mktemp)
    chmod 600 "$defaults"
    # Credenciais lidas do .env pelo próprio Dotenv do Laravel; ficam só no arquivo temporário do mysqldump.
    "$PHP" -r 'require "vendor/autoload.php";
        $env = Dotenv\Dotenv::createArrayBacked(getcwd())->load();
        printf("[client]\nhost=%s\nport=%s\nuser=%s\npassword=\"%s\"\n", $env["DB_HOST"] ?? "localhost", $env["DB_PORT"] ?? "3306",
            $env["DB_USERNAME"] ?? "", addcslashes($env["DB_PASSWORD"] ?? "", "\\\""));' > "$defaults"
    database=$("$PHP" -r 'require "vendor/autoload.php"; echo Dotenv\Dotenv::createArrayBacked(getcwd())->load()["DB_DATABASE"] ?? "";')
    backup="$BACKUP_DIR/yuv-metrics-$(date +%Y%m%d-%H%M%S)-${OLD:0:7}.sql.gz"
    mysqldump --defaults-extra-file="$defaults" --single-transaction --no-tablespaces "$database" | gzip > "$backup"
    rm -f "$defaults"
    echo "Backup do banco: $backup"
    ls -1t "$BACKUP_DIR"/*.sql.gz | tail -n +$((BACKUPS_KEPT + 1)) | xargs -r rm -f
    "$PHP" artisan migrate --force --no-interaction
fi

"$PHP" artisan optimize:clear >/dev/null
echo "Versão ${NEW:0:7} no ar."
