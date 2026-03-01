#!/bin/bash

# Khamareo - Script de création d'entités DDD
# Usage: ./bin/make-entity.sh Shipping Carrier

set -e

DOMAIN=$1
ENTITY=$2

# Couleurs pour l'affichage
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

if [ -z "$DOMAIN" ] || [ -z "$ENTITY" ]; then
    echo -e "${YELLOW}Usage: ./bin/make-entity.sh <Domain> <EntityName>${NC}"
    echo ""
    echo "Exemples:"
    echo "  ./bin/make-entity.sh Shipping Carrier"
    echo "  ./bin/make-entity.sh Order OrderStatus"
    echo "  ./bin/make-entity.sh Cart CartRule"
    echo ""
    echo "Domaines disponibles:"
    echo "  Blog, Cart, Catalog, Contact, Marketing, Media,"
    echo "  Order, Payment, Shipping, User, Shared"
    exit 1
fi

echo -e "${BLUE}🚀 Création de l'entité ${ENTITY} dans ${DOMAIN}...${NC}"
echo ""

# 1. Crée l'entité avec make:entity
php bin/console make:entity "$ENTITY"

echo ""
echo -e "${BLUE}📦 Déplacement vers src/${DOMAIN}/Entity/${NC}"

# 2. Déplace Entity
if [ -f "src/Entity/${ENTITY}.php" ]; then
    mkdir -p "src/${DOMAIN}/Entity"
    mv "src/Entity/${ENTITY}.php" "src/${DOMAIN}/Entity/"
    
    # Met à jour les namespaces dans l'Entity
    sed -i "s|namespace App\\\\Entity;|namespace App\\\\${DOMAIN}\\\\Entity;|g" "src/${DOMAIN}/Entity/${ENTITY}.php"
    sed -i "s|use App\\\\Repository\\\\${ENTITY}Repository;|use App\\\\${DOMAIN}\\\\Repository\\\\${ENTITY}Repository;|g" "src/${DOMAIN}/Entity/${ENTITY}.php"
    
    echo -e "${GREEN}✅ Entity: src/${DOMAIN}/Entity/${ENTITY}.php${NC}"
else
    echo -e "${YELLOW}⚠️  Aucune entity créée${NC}"
    exit 1
fi

# 3. Déplace Repository
if [ -f "src/Repository/${ENTITY}Repository.php" ]; then
    mkdir -p "src/${DOMAIN}/Repository"
    mv "src/Repository/${ENTITY}Repository.php" "src/${DOMAIN}/Repository/"
    
    # Met à jour les namespaces dans le Repository
    sed -i "s|namespace App\\\\Repository;|namespace App\\\\${DOMAIN}\\\\Repository;|g" "src/${DOMAIN}/Repository/${ENTITY}Repository.php"
    sed -i "s|use App\\\\Entity\\\\${ENTITY};|use App\\\\${DOMAIN}\\\\Entity\\\\${ENTITY};|g" "src/${DOMAIN}/Repository/${ENTITY}Repository.php"
    
    echo -e "${GREEN}✅ Repository: src/${DOMAIN}/Repository/${ENTITY}Repository.php${NC}"
fi

# 4. Nettoyage - supprime les dossiers vides
rmdir src/Entity 2>/dev/null && echo -e "${GREEN}🗑️  Dossier src/Entity/ vide supprimé${NC}" || true
rmdir src/Repository 2>/dev/null && echo -e "${GREEN}🗑️  Dossier src/Repository/ vide supprimé${NC}" || true

echo ""
echo -e "${GREEN}✅ Entité créée avec succès !${NC}"
echo ""
echo -e "${BLUE}📋 Prochaines étapes:${NC}"
echo "   1. Édite: src/${DOMAIN}/Entity/${ENTITY}.php"
echo "   2. Lance: php bin/console make:migration"
echo "   3. Lance: php bin/console doctrine:migrations:migrate"
echo ""