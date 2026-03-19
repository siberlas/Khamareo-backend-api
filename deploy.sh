#!/bin/bash
set -euo pipefail

# ── Khamareo Backend Deployment ─────────────────────────────
# Usage: ./deploy.sh [first-run]

COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"
BACKUP_DIR="backups"

# Charger les variables d'environnement
export $(grep -v '^#' "$ENV_FILE" | grep -v '^\s*$' | xargs)

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
if docker compose -f "$COMPOSE_FILE" ps db --status running -q 2>/dev/null; then
    echo ">>> Backup de la base de données..."
    mkdir -p "$BACKUP_DIR"
    docker compose -f "$COMPOSE_FILE" exec -T db \
        pg_dump -U khamareo -Fc khamareo \
        > "$BACKUP_DIR/pre-deploy-$(date +%Y%m%d_%H%M%S).dump"
    echo "    Backup terminé."
fi

# Pull latest code
echo ">>> Pull du code..."
git pull origin main

# Build and restart
echo ">>> Build des images..."
docker compose -f "$COMPOSE_FILE" build

echo ">>> Démarrage des services..."
docker compose -f "$COMPOSE_FILE" up -d

# Wait for containers
echo ">>> Attente des conteneurs..."
sleep 5

# Run migrations
echo ">>> Migrations..."
docker compose -f "$COMPOSE_FILE" exec -T php \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Clear cache
echo ">>> Cache..."
docker compose -f "$COMPOSE_FILE" exec -T php \
    php bin/console cache:clear --env=prod --no-debug

# First run
if [ "${1:-}" = "first-run" ]; then
    echo ">>> Premier déploiement : création du schéma..."
    docker compose -f "$COMPOSE_FILE" exec -T php \
        php bin/console doctrine:schema:create --no-interaction 2>/dev/null || true

    echo ""
    echo "=== PREMIER DÉPLOIEMENT TERMINÉ ==="
    echo "À faire :"
    echo "  1. Créer un admin"
    echo "  2. Configurer le webhook Stripe : https://api.khamareo.com/api/stripe/webhook"
    echo "  3. Configurer le DNS Cloudflare : A record api → <VPS_IP>"
    echo "  4. SSL Cloudflare : mode Full"
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
