#!/bin/bash
set -euo pipefail

# ── Khamareo Backend Deployment ─────────────────────────────
# Usage: ./deploy.sh [first-run]

COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"
BACKUP_DIR="backups"

# Charger les variables d'environnement
set -a
source "$ENV_FILE"
set +a

echo "=== Khamareo Deploy $(date '+%Y-%m-%d %H:%M:%S') ==="

# Pre-flight checks
if [ ! -f ".env.prod" ]; then
    echo "ERREUR: .env.prod non trouvé."
    echo "Copier .env.prod.template → .env.prod et remplir les secrets."
    exit 1
fi

if [ ! -f "config/jwt/private.pem" ] && [ -z "${JWT_SECRET_KEY_BASE64:-}" ]; then
    echo "ERREUR: Clés JWT non trouvées."
    echo "Générer avec :"
    echo "  openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096"
    echo "  openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout"
    exit 1
fi

# Backup database before deployment (if running)
if docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" ps db --status running -q 2>/dev/null | grep -q .; then
    echo ">>> Backup de la base de données..."
    mkdir -p "$BACKUP_DIR"
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T db \
        pg_dump -U khamareo -Fc khamareo \
        > "$BACKUP_DIR/pre-deploy-$(date +%Y%m%d_%H%M%S).dump"
    echo "    Backup terminé."
else
    echo ">>> DB non démarrée, backup ignoré."
fi

# Pull latest code
echo ">>> Pull du code..."
git pull origin main

# Build and restart
echo ">>> Build des images..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" build

echo ">>> Démarrage des services..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" up -d

# Wait for containers
echo ">>> Attente des conteneurs..."
sleep 5

# First run: create schema instead of running migrations
if [ "${1:-}" = "first-run" ]; then
    echo ">>> Premier déploiement : création du schéma..."
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T php \
        php bin/console doctrine:schema:create --no-interaction

    echo ">>> Marquage des migrations comme exécutées..."
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T php \
        php bin/console doctrine:migrations:sync-metadata-storage --no-interaction
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T php \
        php bin/console doctrine:migrations:version --add --all --no-interaction
else
    echo ">>> Migrations..."
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T php \
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Fix permissions on var/ before cache clear
echo ">>> Permissions var/..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T php \
    chown -R www-data:www-data /var/www/html/var

# Clear cache
echo ">>> Cache..."
docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T -u www-data php \
    php bin/console cache:clear --env=prod --no-debug

if [ "${1:-}" = "first-run" ]; then
    echo ">>> Création du compte admin..."
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T php \
        php bin/console app:create-admin \
            "${ADMIN_EMAIL:-assi.sylvia@khamareo.com}" \
            "${ADMIN_PASSWORD:-Gokoetsu2026*}" \
            "Sylvia" "Assi" \
            --env=prod --no-interaction

    echo ">>> Activation du mode Coming Soon..."
    docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE" exec -T db \
        psql -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -c \
        "INSERT INTO app_settings (id, setting_key, setting_value, updated_at) VALUES ((SELECT COALESCE(MAX(id), 0) + 1 FROM app_settings), 'coming_soon_enabled', 'true', NOW()) ON CONFLICT (setting_key) DO UPDATE SET setting_value = 'true', updated_at = NOW();"

    echo ""
    echo "=== PREMIER DÉPLOIEMENT TERMINÉ ==="
    echo "  Admin : ${ADMIN_EMAIL:-assi.sylvia@khamareo.com}"
    echo "  Coming Soon : activé"
    echo ""
    echo "À faire :"
    echo "  1. Configurer le webhook Stripe : https://api.khamareo.com/api/stripe/webhook"
    echo "  2. Configurer le DNS Cloudflare : A record api → <VPS_IP>"
    echo "  3. SSL Cloudflare : mode Full"
fi

# Health check
echo ">>> Health check..."
sleep 3
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/api/health || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    echo "    OK (HTTP $HTTP_CODE)"
else
    echo "    ÉCHEC (HTTP $HTTP_CODE)"
    echo "    Logs : docker compose -f $COMPOSE_FILE logs php"
    exit 1
fi

# Cleanup
docker image prune -f > /dev/null 2>&1

echo ""
echo "=== Déploiement terminé ! ==="
