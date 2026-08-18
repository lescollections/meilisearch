#!/bin/bash
# Entrée du conteneur applicatif du harnais.
#
# Dérivé de celui du greffon lescollections, sans ce qui n'a pas cours ici (réglages de
# locataire, instantanés de quota) et avec ce qui manquait : les liens symboliques du greffon,
# et l'attente de Meilisearch.
set -euo pipefail

log() { printf '\033[36m[dev]\033[0m %s\n' "$*"; }

# ── Attendre MariaDB ──────────────────────────────────────────────────────────────────────
log "attente de ${CA_DB_HOST:-db}…"
for _ in $(seq 1 120); do
    if mariadb-admin ping \
        --host="${CA_DB_HOST:-db}" \
        --port="${CA_DB_PORT:-3306}" \
        --user="${CA_DB_USER:-collectiveaccess}" \
        --password="${CA_DB_PASSWORD:-collectiveaccess}" \
        --silent >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

# ── Attendre Meilisearch ──────────────────────────────────────────────────────────────────
# Sans blocage : le harnais doit démarrer même moteur éteint. C'est un cas à éprouver, pas un
# accident — l'application doit rester utilisable et le dire dans app/log/meilisearch.log.
MS_URL="${CA_MEILISEARCH_URL:-http://meilisearch:7700}"
log "attente de ${MS_URL}…"
for _ in $(seq 1 60); do
    if curl -fsS "${MS_URL}/health" >/dev/null 2>&1; then
        log "Meilisearch répond"
        break
    fi
    sleep 1
done

# ── Droits d'écriture ─────────────────────────────────────────────────────────────────────
chown -R www-data:www-data \
    /var/www/html/app/tmp \
    /var/www/html/app/log \
    /var/www/html/app/fonts \
    /var/www/html/media \
    /var/www/html/uploads 2>/dev/null || true

# ── Le greffon ────────────────────────────────────────────────────────────────────────────
# Posé à chaque démarrage : les liens vivent dans l'image, pas dans un volume.
dev-link-plugin

# ── La couche locale d'app.conf ───────────────────────────────────────────────────────────
# C'est là que vit `search_engine_plugin`. NE PAS écrire dans app/conf/local/app_<nom>.conf :
# cette couche est composée sur le papier par Configuration.php, mais Configuration::load() —
# la voie qu'emprunte tout CollectiveAccess — ne la charge pas. Le symptôme est un réglage qui
# « ne prend pas », sans le moindre message.
cp /opt/meilisearch/local-app.conf.tmpl /var/www/html/app/conf/local/app.conf
chown www-data:www-data /var/www/html/app/conf/local/app.conf

# ── Installer si besoin ───────────────────────────────────────────────────────────────────
if [ "${CA_AUTO_INSTALL:-1}" = "1" ]; then
    dev-install
fi

log "Providence disponible sur http://localhost:${CA_HOST_PORT:-8089}/gestion/index.php"
exec "$@"
