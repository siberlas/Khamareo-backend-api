#!/bin/bash

# Couleurs
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_success() { echo -e "${GREEN}✅ $1${NC}"; }
log_error() { echo -e "${RED}❌ $1${NC}"; }
log_info() { echo -e "${YELLOW}ℹ️  $1${NC}"; }
log_step() { echo -e "${BLUE}🔹 $1${NC}"; }

# Fonction pour déplacer un fichier
safe_move() {
    local source=$1
    local dest=$2
    
    if [ ! -f "$source" ]; then
        return 0
    fi
    
    mkdir -p "$(dirname "$dest")"
    
    if git mv "$source" "$dest" 2>/dev/null; then
        log_success "Déplacé: $(basename $source)"
    else
        mv "$source" "$dest" 2>/dev/null
        git add "$dest" 2>/dev/null
        log_success "Déplacé: $(basename $source)"
    fi
}

# Fonction pour mettre à jour les namespaces dans un fichier
update_namespace() {
    local file=$1
    local old_ns=$2
    local new_ns=$3
    
    if [ ! -f "$file" ]; then
        return 0
    fi
    
    sed -i "s|namespace $old_ns;|namespace $new_ns;|g" "$file"
    sed -i "s|use $old_ns\\\\|use $new_ns\\\\|g" "$file"
}

# Fonction pour mettre à jour les imports globalement
update_imports() {
    local old_import=$1
    local new_import=$2
    
    find src -type f -name "*.php" -exec sed -i "s|use $old_import;|use $new_import;|g" {} \;
    find src -type f -name "*.php" -exec sed -i "s|use $old_import\\\\|use $new_import\\\\|g" {} \;
}

#######################################
# MIGRATION CART
#######################################
migrate_cart() {
    log_info "========================================="
    log_info "📦 MIGRATION CART"
    log_info "========================================="
    
    mkdir -p src/Cart/{Entity,Repository,Controller,Service,State,DataFixtures,Dto}
    
    # Entities
    log_step "Entities..."
    safe_move "src/Entity/Cart.php" "src/Cart/Entity/Cart.php"
    safe_move "src/Entity/CartItem.php" "src/Cart/Entity/CartItem.php"
    safe_move "src/Entity/Favorite.php" "src/Cart/Entity/Favorite.php"
    
    # Repositories
    log_step "Repositories..."
    safe_move "src/Repository/CartRepository.php" "src/Cart/Repository/CartRepository.php"
    safe_move "src/Repository/CartItemRepository.php" "src/Cart/Repository/CartItemRepository.php"
    safe_move "src/Repository/FavoriteRepository.php" "src/Cart/Repository/FavoriteRepository.php"
    
    # Controllers
    log_step "Controllers..."
    safe_move "src/Controller/GetCurrentCartController.php" "src/Cart/Controller/GetCurrentCartController.php"
    safe_move "src/Controller/MergeGuestCartController.php" "src/Cart/Controller/MergeGuestCartController.php"
    
    # Services
    log_step "Services..."
    safe_move "src/Service/CartWeightCalculator.php" "src/Cart/Service/CartWeightCalculator.php"
    safe_move "src/Service/GuestConversionService.php" "src/Cart/Service/GuestConversionService.php"
    
    # State Processors/Providers
    log_step "State Processors..."
    safe_move "src/State/CartProcessor.php" "src/Cart/State/CartProcessor.php"
    safe_move "src/State/CartItemProcessor.php" "src/Cart/State/CartItemProcessor.php"
    safe_move "src/State/FavoriteProcessor.php" "src/Cart/State/FavoriteProcessor.php"
    safe_move "src/State/FavoriteCollectionProvider.php" "src/Cart/State/FavoriteCollectionProvider.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/CartFixtures.php" "src/Cart/DataFixtures/CartFixtures.php"
    safe_move "src/DataFixtures/CartItemFixtures.php" "src/Cart/DataFixtures/CartItemFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Cart/Entity/*.php; do update_namespace "$file" "App\\\\Entity" "App\\\\Cart\\\\Entity"; done
    for file in src/Cart/Repository/*.php; do update_namespace "$file" "App\\\\Repository" "App\\\\Cart\\\\Repository"; done
    for file in src/Cart/Controller/*.php; do update_namespace "$file" "App\\\\Controller" "App\\\\Cart\\\\Controller"; done
    for file in src/Cart/Service/*.php; do update_namespace "$file" "App\\\\Service" "App\\\\Cart\\\\Service"; done
    for file in src/Cart/State/*.php; do update_namespace "$file" "App\\\\State" "App\\\\Cart\\\\State"; done
    for file in src/Cart/DataFixtures/*.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\Cart\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Entity\\\\Cart" "App\\\\Cart\\\\Entity\\\\Cart"
    update_imports "App\\\\Entity\\\\CartItem" "App\\\\Cart\\\\Entity\\\\CartItem"
    update_imports "App\\\\Entity\\\\Favorite" "App\\\\Cart\\\\Entity\\\\Favorite"
    update_imports "App\\\\Repository\\\\CartRepository" "App\\\\Cart\\\\Repository\\\\CartRepository"
    update_imports "App\\\\Repository\\\\CartItemRepository" "App\\\\Cart\\\\Repository\\\\CartItemRepository"
    update_imports "App\\\\Repository\\\\FavoriteRepository" "App\\\\Cart\\\\Repository\\\\FavoriteRepository"
    update_imports "App\\\\Service\\\\CartWeightCalculator" "App\\\\Cart\\\\Service\\\\CartWeightCalculator"
    update_imports "App\\\\Service\\\\GuestConversionService" "App\\\\Cart\\\\Service\\\\GuestConversionService"
    
    log_success "✅ CART migré !"
}

#######################################
# MIGRATION CATALOG
#######################################
migrate_catalog() {
    log_info "========================================="
    log_info "🛍️  MIGRATION CATALOG"
    log_info "========================================="
    
    mkdir -p src/Catalog/{Entity,Repository,Controller,Service,State,DataFixtures,Filter}
    
    # Entities
    log_step "Entities..."
    safe_move "src/Entity/Product.php" "src/Catalog/Entity/Product.php"
    safe_move "src/Entity/Category.php" "src/Catalog/Entity/Category.php"
    safe_move "src/Entity/ProductPrice.php" "src/Catalog/Entity/ProductPrice.php"
    safe_move "src/Entity/Review.php" "src/Catalog/Entity/Review.php"
    
    # Repositories
    log_step "Repositories..."
    safe_move "src/Repository/ProductRepository.php" "src/Catalog/Repository/ProductRepository.php"
    safe_move "src/Repository/CategoryRepository.php" "src/Catalog/Repository/CategoryRepository.php"
    safe_move "src/Repository/ProductPriceRepository.php" "src/Catalog/Repository/ProductPriceRepository.php"
    safe_move "src/Repository/ReviewRepository.php" "src/Catalog/Repository/ReviewRepository.php"
    
    # State
    log_step "State Processors..."
    safe_move "src/State/ProductPriceProvider.php" "src/Catalog/State/ProductPriceProvider.php"
    safe_move "src/State/ManualTranslationProvider.php" "src/Catalog/State/ManualTranslationProvider.php"
    safe_move "src/State/ManualTranslationCollectionProvider.php" "src/Catalog/State/ManualTranslationCollectionProvider.php"
    
    # Filters
    log_step "Filters..."
    safe_move "src/Filter/CategoryOrChildrenFilter.php" "src/Catalog/Filter/CategoryOrChildrenFilter.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/ProductFixtures.php" "src/Catalog/DataFixtures/ProductFixtures.php"
    safe_move "src/DataFixtures/CategoryFixtures.php" "src/Catalog/DataFixtures/CategoryFixtures.php"
    safe_move "src/DataFixtures/ProductPriceFixtures.php" "src/Catalog/DataFixtures/ProductPriceFixtures.php"
    safe_move "src/DataFixtures/ReviewFixtures.php" "src/Catalog/DataFixtures/ReviewFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Catalog/Entity/*.php; do update_namespace "$file" "App\\\\Entity" "App\\\\Catalog\\\\Entity"; done
    for file in src/Catalog/Repository/*.php; do update_namespace "$file" "App\\\\Repository" "App\\\\Catalog\\\\Repository"; done
    for file in src/Catalog/State/*.php; do update_namespace "$file" "App\\\\State" "App\\\\Catalog\\\\State"; done
    for file in src/Catalog/Filter/*.php; do update_namespace "$file" "App\\\\Filter" "App\\\\Catalog\\\\Filter"; done
    for file in src/Catalog/DataFixtures/*.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\Catalog\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Entity\\\\Product" "App\\\\Catalog\\\\Entity\\\\Product"
    update_imports "App\\\\Entity\\\\Category" "App\\\\Catalog\\\\Entity\\\\Category"
    update_imports "App\\\\Entity\\\\ProductPrice" "App\\\\Catalog\\\\Entity\\\\ProductPrice"
    update_imports "App\\\\Entity\\\\Review" "App\\\\Catalog\\\\Entity\\\\Review"
    update_imports "App\\\\Repository\\\\ProductRepository" "App\\\\Catalog\\\\Repository\\\\ProductRepository"
    update_imports "App\\\\Repository\\\\CategoryRepository" "App\\\\Catalog\\\\Repository\\\\CategoryRepository"
    update_imports "App\\\\Repository\\\\ProductPriceRepository" "App\\\\Catalog\\\\Repository\\\\ProductPriceRepository"
    update_imports "App\\\\Repository\\\\ReviewRepository" "App\\\\Catalog\\\\Repository\\\\ReviewRepository"
    update_imports "App\\\\Filter\\\\CategoryOrChildrenFilter" "App\\\\Catalog\\\\Filter\\\\CategoryOrChildrenFilter"
    
    log_success "✅ CATALOG migré !"
}

#######################################
# MIGRATION ORDER
#######################################
migrate_order() {
    log_info "========================================="
    log_info "📋 MIGRATION ORDER"
    log_info "========================================="
    
    mkdir -p src/Order/{Entity,Repository,Controller,Service,State,DataFixtures,EventSubscriber,Dto}
    
    # Entities
    log_step "Entities..."
    safe_move "src/Entity/Order.php" "src/Order/Entity/Order.php"
    safe_move "src/Entity/OrderItem.php" "src/Order/Entity/OrderItem.php"
    
    # Repositories
    log_step "Repositories..."
    safe_move "src/Repository/OrderRepository.php" "src/Order/Repository/OrderRepository.php"
    safe_move "src/Repository/OrderItemRepository.php" "src/Order/Repository/OrderItemRepository.php"
    
    # Controllers
    log_step "Controllers..."
    safe_move "src/Controller/PublicOrderByNumberController.php" "src/Order/Controller/PublicOrderByNumberController.php"
    
    # State
    log_step "State Processors..."
    safe_move "src/State/OrderProvider.php" "src/Order/State/OrderProvider.php"
    safe_move "src/State/GuestCartAddressProcessor.php" "src/Order/State/GuestCartAddressProcessor.php"
    safe_move "src/State/GuestCheckoutProvider.php" "src/Order/State/GuestCheckoutProvider.php"
    
    # EventSubscriber
    log_step "EventSubscriber..."
    safe_move "src/EventSubscriber/OrderPaymentSyncSubscriber.php" "src/Order/EventSubscriber/OrderPaymentSyncSubscriber.php"
    safe_move "src/EventSubscriber/ShippingAddressDefaultSubscriber.php" "src/Order/EventSubscriber/ShippingAddressDefaultSubscriber.php"
    
    # Dto
    log_step "Dto..."
    safe_move "src/Dto/GuestCartAddressInput.php" "src/Order/Dto/GuestCartAddressInput.php"
    safe_move "src/Dto/GuestCheckoutView.php" "src/Order/Dto/GuestCheckoutView.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/OrderFixtures.php" "src/Order/DataFixtures/OrderFixtures.php"
    safe_move "src/DataFixtures/OrderItemFixtures.php" "src/Order/DataFixtures/OrderItemFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Order/Entity/*.php; do update_namespace "$file" "App\\\\Entity" "App\\\\Order\\\\Entity"; done
    for file in src/Order/Repository/*.php; do update_namespace "$file" "App\\\\Repository" "App\\\\Order\\\\Repository"; done
    for file in src/Order/Controller/*.php; do update_namespace "$file" "App\\\\Controller" "App\\\\Order\\\\Controller"; done
    for file in src/Order/State/*.php; do update_namespace "$file" "App\\\\State" "App\\\\Order\\\\State"; done
    for file in src/Order/EventSubscriber/*.php; do update_namespace "$file" "App\\\\EventSubscriber" "App\\\\Order\\\\EventSubscriber"; done
    for file in src/Order/Dto/*.php; do update_namespace "$file" "App\\\\Dto" "App\\\\Order\\\\Dto"; done
    for file in src/Order/DataFixtures/*.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\Order\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Entity\\\\Order" "App\\\\Order\\\\Entity\\\\Order"
    update_imports "App\\\\Entity\\\\OrderItem" "App\\\\Order\\\\Entity\\\\OrderItem"
    update_imports "App\\\\Repository\\\\OrderRepository" "App\\\\Order\\\\Repository\\\\OrderRepository"
    update_imports "App\\\\Repository\\\\OrderItemRepository" "App\\\\Order\\\\Repository\\\\OrderItemRepository"
    update_imports "App\\\\Dto\\\\GuestCartAddressInput" "App\\\\Order\\\\Dto\\\\GuestCartAddressInput"
    update_imports "App\\\\Dto\\\\GuestCheckoutView" "App\\\\Order\\\\Dto\\\\GuestCheckoutView"
    
    log_success "✅ ORDER migré !"
}

#######################################
# MIGRATION SHIPPING
#######################################
migrate_shipping() {
    log_info "========================================="
    log_info "🚚 MIGRATION SHIPPING"
    log_info "========================================="
    
    mkdir -p src/Shipping/{Entity,Repository,Controller,Service,DataFixtures}
    
    # Entities
    log_step "Entities..."
    safe_move "src/Entity/ShippingLabel.php" "src/Shipping/Entity/ShippingLabel.php"
    safe_move "src/Entity/ShippingMethod.php" "src/Shipping/Entity/ShippingMethod.php"
    safe_move "src/Entity/ShippingRate.php" "src/Shipping/Entity/ShippingRate.php"
    
    # Repositories
    log_step "Repositories..."
    safe_move "src/Repository/ShippingLabelRepository.php" "src/Shipping/Repository/ShippingLabelRepository.php"
    safe_move "src/Repository/ShippingMethodRepository.php" "src/Shipping/Repository/ShippingMethodRepository.php"
    safe_move "src/Repository/ShippingRateRepository.php" "src/Shipping/Repository/ShippingRateRepository.php"
    
    # Controllers
    log_step "Controllers..."
    safe_move "src/Controller/ShippingCostController.php" "src/Shipping/Controller/ShippingCostController.php"
    safe_move "src/Controller/TestColissimoController.php" "src/Dev/Controller/TestColissimoController.php"
    
    # Services
    log_step "Services..."
    safe_move "src/Service/ColissimoApiService.php" "src/Shipping/Service/ColissimoApiService.php"
    safe_move "src/Service/ShippingLabelService.php" "src/Shipping/Service/ShippingLabelService.php"
    safe_move "src/Service/ShippingRateCalculator.php" "src/Shipping/Service/ShippingRateCalculator.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/ShippingMethodFixtures.php" "src/Shipping/DataFixtures/ShippingMethodFixtures.php"
    safe_move "src/DataFixtures/ShippingRateFixtures.php" "src/Shipping/DataFixtures/ShippingRateFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Shipping/Entity/*.php; do update_namespace "$file" "App\\\\Entity" "App\\\\Shipping\\\\Entity"; done
    for file in src/Shipping/Repository/*.php; do update_namespace "$file" "App\\\\Repository" "App\\\\Shipping\\\\Repository"; done
    for file in src/Shipping/Controller/*.php; do update_namespace "$file" "App\\\\Controller" "App\\\\Shipping\\\\Controller"; done
    for file in src/Shipping/Service/*.php; do update_namespace "$file" "App\\\\Service" "App\\\\Shipping\\\\Service"; done
    for file in src/Shipping/DataFixtures/*.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\Shipping\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Entity\\\\ShippingLabel" "App\\\\Shipping\\\\Entity\\\\ShippingLabel"
    update_imports "App\\\\Entity\\\\ShippingMethod" "App\\\\Shipping\\\\Entity\\\\ShippingMethod"
    update_imports "App\\\\Entity\\\\ShippingRate" "App\\\\Shipping\\\\Entity\\\\ShippingRate"
    update_imports "App\\\\Repository\\\\ShippingLabelRepository" "App\\\\Shipping\\\\Repository\\\\ShippingLabelRepository"
    update_imports "App\\\\Repository\\\\ShippingMethodRepository" "App\\\\Shipping\\\\Repository\\\\ShippingMethodRepository"
    update_imports "App\\\\Repository\\\\ShippingRateRepository" "App\\\\Shipping\\\\Repository\\\\ShippingRateRepository"
    update_imports "App\\\\Service\\\\ColissimoApiService" "App\\\\Shipping\\\\Service\\\\ColissimoApiService"
    update_imports "App\\\\Service\\\\ShippingLabelService" "App\\\\Shipping\\\\Service\\\\ShippingLabelService"
    update_imports "App\\\\Service\\\\ShippingRateCalculator" "App\\\\Shipping\\\\Service\\\\ShippingRateCalculator"
    
    log_success "✅ SHIPPING migré !"
}

#######################################
# COMPLÉTER SHARED
#######################################
complete_shared() {
    log_info "========================================="
    log_info "🔧 COMPLÉTION SHARED"
    log_info "========================================="
    
    mkdir -p src/Shared/{Enum,EventListener,Exception,Voter,Service,DataFixtures}
    
    # Enums
    log_step "Enums..."
    safe_move "src/Enum/OrderStatus.php" "src/Shared/Enum/OrderStatus.php"
    safe_move "src/Enum/PaymentStatus.php" "src/Shared/Enum/PaymentStatus.php"
    
    # EventListeners
    log_step "EventListeners..."
    safe_move "src/EventListener/CheckVerifiedUserListener.php" "src/Shared/EventListener/CheckVerifiedUserListener.php"
    safe_move "src/EventListener/LocaleListener.php" "src/Shared/EventListener/LocaleListener.php"
    
    # Exceptions
    log_step "Exceptions..."
    safe_move "src/Exception/AccountExistsException.php" "src/Shared/Exception/AccountExistsException.php"
    
    # Voters
    log_step "Voters..."
    safe_move "src/Security/Voter/AddressOrderVoter.php" "src/Shared/Voter/AddressOrderVoter.php"
    
    # Services
    log_step "Services..."
    safe_move "src/Service/MailerService.php" "src/Shared/Service/MailerService.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/AppFixtures.php" "src/Shared/DataFixtures/AppFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Shared/Enum/*.php; do update_namespace "$file" "App\\\\Enum" "App\\\\Shared\\\\Enum"; done
    for file in src/Shared/EventListener/*.php; do update_namespace "$file" "App\\\\EventListener" "App\\\\Shared\\\\EventListener"; done
    for file in src/Shared/Exception/*.php; do update_namespace "$file" "App\\\\Exception" "App\\\\Shared\\\\Exception"; done
    for file in src/Shared/Voter/*.php; do update_namespace "$file" "App\\\\Security\\\\Voter" "App\\\\Shared\\\\Voter"; done
    for file in src/Shared/Service/MailerService.php; do update_namespace "$file" "App\\\\Service" "App\\\\Shared\\\\Service"; done
    for file in src/Shared/DataFixtures/AppFixtures.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\Shared\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Enum\\\\OrderStatus" "App\\\\Shared\\\\Enum\\\\OrderStatus"
    update_imports "App\\\\Enum\\\\PaymentStatus" "App\\\\Shared\\\\Enum\\\\PaymentStatus"
    update_imports "App\\\\Exception\\\\AccountExistsException" "App\\\\Shared\\\\Exception\\\\AccountExistsException"
    update_imports "App\\\\Security\\\\Voter\\\\AddressOrderVoter" "App\\\\Shared\\\\Voter\\\\AddressOrderVoter"
    update_imports "App\\\\Service\\\\MailerService" "App\\\\Shared\\\\Service\\\\MailerService"
    
    log_success "✅ SHARED complété !"
}

#######################################
# COMPLÉTER USER
#######################################
complete_user() {
    log_info "========================================="
    log_info "👤 COMPLÉTION USER"
    log_info "========================================="
    
    mkdir -p src/User/{Controller,State,DataFixtures,Dto}
    
    # Controllers
    log_step "Controllers..."
    safe_move "src/Controller/ConfirmAccountController.php" "src/User/Controller/ConfirmAccountController.php"
    safe_move "src/Controller/ForgotPasswordController.php" "src/User/Controller/ForgotPasswordController.php"
    safe_move "src/Controller/ResendConfirmationController.php" "src/User/Controller/ResendConfirmationController.php"
    safe_move "src/Controller/ResetPasswordController.php" "src/User/Controller/ResetPasswordController.php"
    
    # State
    log_step "State Processors..."
    safe_move "src/State/AddressOwnerProcessor.php" "src/User/State/AddressOwnerProcessor.php"
    safe_move "src/State/AddressProvider.php" "src/User/State/AddressProvider.php"
    safe_move "src/State/AddressSetDefaultProcessor.php" "src/User/State/AddressSetDefaultProcessor.php"
    safe_move "src/State/AddressSoftDeleteProcessor.php" "src/User/State/AddressSoftDeleteProcessor.php"
    safe_move "src/State/ChangePasswordProcessor.php" "src/User/State/ChangePasswordProcessor.php"
    safe_move "src/State/CurrentUserProvider.php" "src/User/State/CurrentUserProvider.php"
    safe_move "src/State/UserPasswordHasher.php" "src/User/State/UserPasswordHasher.php"
    
    # Dto
    log_step "Dto..."
    safe_move "src/Dto/ChangePasswordRequest.php" "src/User/Dto/ChangePasswordRequest.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/AddressFixtures.php" "src/User/DataFixtures/AddressFixtures.php"
    safe_move "src/DataFixtures/UserFixtures.php" "src/User/DataFixtures/UserFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/User/Controller/*.php; do update_namespace "$file" "App\\\\Controller" "App\\\\User\\\\Controller"; done
    for file in src/User/State/*.php; do update_namespace "$file" "App\\\\State" "App\\\\User\\\\State"; done
    for file in src/User/Dto/*.php; do update_namespace "$file" "App\\\\Dto" "App\\\\User\\\\Dto"; done
    for file in src/User/DataFixtures/*.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\User\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Dto\\\\ChangePasswordRequest" "App\\\\User\\\\Dto\\\\ChangePasswordRequest"
    
    log_success "✅ USER complété !"
}

#######################################
# COMPLÉTER MARKETING
#######################################
complete_marketing() {
    log_info "========================================="
    log_info "📣 COMPLÉTION MARKETING"
    log_info "========================================="
    
    mkdir -p src/Marketing/{State,EventSubscriber,Service,DataFixtures}
    
    # State
    log_step "State Processors..."
    safe_move "src/State/NewsletterSubscriberProcessor.php" "src/Marketing/State/NewsletterSubscriberProcessor.php"
    safe_move "src/State/StockAlertCollectionProvider.php" "src/Marketing/State/StockAlertCollectionProvider.php"
    safe_move "src/State/StockAlertProcessor.php" "src/Marketing/State/StockAlertProcessor.php"
    
    # EventSubscriber
    log_step "EventSubscriber..."
    safe_move "src/EventSubscriber/PromoCodeSubscriber.php" "src/Marketing/EventSubscriber/PromoCodeSubscriber.php"
    
    # Services
    log_step "Services..."
    safe_move "src/Service/PromoCodeApplicationService.php" "src/Marketing/Service/PromoCodeApplicationService.php"
    safe_move "src/Service/PromoCodeService.php" "src/Marketing/Service/PromoCodeService.php"
    
    # DataFixtures
    log_step "DataFixtures..."
    safe_move "src/DataFixtures/NewsletterSubscriberFixtures.php" "src/Marketing/DataFixtures/NewsletterSubscriberFixtures.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Marketing/State/*.php; do update_namespace "$file" "App\\\\State" "App\\\\Marketing\\\\State"; done
    for file in src/Marketing/EventSubscriber/*.php; do update_namespace "$file" "App\\\\EventSubscriber" "App\\\\Marketing\\\\EventSubscriber"; done
    for file in src/Marketing/Service/*.php; do update_namespace "$file" "App\\\\Service" "App\\\\Marketing\\\\Service"; done
    for file in src/Marketing/DataFixtures/*.php; do update_namespace "$file" "App\\\\DataFixtures" "App\\\\Marketing\\\\DataFixtures"; done
    
    # Mise à jour imports
    log_step "Mise à jour imports globaux..."
    update_imports "App\\\\Service\\\\PromoCodeApplicationService" "App\\\\Marketing\\\\Service\\\\PromoCodeApplicationService"
    update_imports "App\\\\Service\\\\PromoCodeService" "App\\\\Marketing\\\\Service\\\\PromoCodeService"
    
    log_success "✅ MARKETING complété !"
}

#######################################
# COMPLÉTER CONTACT
#######################################
complete_contact() {
    log_info "========================================="
    log_info "📧 COMPLÉTION CONTACT"
    log_info "========================================="
    
    mkdir -p src/Contact/State
    
    # State
    log_step "State Processors..."
    safe_move "src/State/ContactMessageProcessor.php" "src/Contact/State/ContactMessageProcessor.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Contact/State/*.php; do update_namespace "$file" "App\\\\State" "App\\\\Contact\\\\State"; done
    
    log_success "✅ CONTACT complété !"
}

#######################################
# COMPLÉTER PAYMENT
#######################################
complete_payment() {
    log_info "========================================="
    log_info "💳 COMPLÉTION PAYMENT"
    log_info "========================================="
    
    mkdir -p src/Payment/EventSubscriber
    
    # EventSubscriber
    log_step "EventSubscriber..."
    safe_move "src/EventSubscriber/PaymentStatusSubscriber.php" "src/Payment/EventSubscriber/PaymentStatusSubscriber.php"
    
    # Mise à jour namespaces
    log_step "Mise à jour namespaces..."
    for file in src/Payment/EventSubscriber/*.php; do update_namespace "$file" "App\\\\EventSubscriber" "App\\\\Payment\\\\EventSubscriber"; done
    
    log_success "✅ PAYMENT complété !"
}

#######################################
# NETTOYAGE FINAL
#######################################
cleanup() {
    log_info "========================================="
    log_info "🧹 NETTOYAGE FINAL"
    log_info "========================================="
    
    # Supprime les dossiers vides
    log_step "Suppression des dossiers vides..."
    find src -type d -empty -delete 2>/dev/null
    
    # Supprime l'ancien Repository NewsletterSuscriberRepository (typo)
    log_step "Nettoyage fichiers avec typos..."
    [ -f "src/Repository/NewsletterSuscriberRepository.php" ] && rm "src/Repository/NewsletterSuscriberRepository.php"
    
    log_success "✅ Nettoyage terminé !"
}

#######################################
# MAIN
#######################################
main() {
    echo ""
    echo "========================================="
    echo "🚀 MIGRATION DDD COMPLÈTE - KHAMAREO"
    echo "========================================="
    echo ""
    
    log_info "⚠️  ATTENTION : Cette migration va déplacer tous les fichiers restants"
    echo ""
    read -p "Voulez-vous continuer ? (y/n): " confirm
    
    if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
        log_error "Migration annulée"
        exit 0
    fi
    
    echo ""
    log_info "Début de la migration..."
    echo ""
    
    migrate_cart
    echo ""
    
    migrate_catalog
    echo ""
    
    migrate_order
    echo ""
    
    migrate_shipping
    echo ""
    
    complete_shared
    echo ""
    
    complete_user
    echo ""
    
    complete_marketing
    echo ""
    
    complete_contact
    echo ""
    
    complete_payment
    echo ""
    
    cleanup
    echo ""
    
    log_success "========================================="
    log_success "✅ MIGRATION TERMINÉE !"
    log_success "========================================="
    echo ""
    log_info "Prochaines étapes :"
    echo "1. php bin/console cache:clear"
    echo "2. php bin/console doctrine:mapping:info"
    echo "3. Mettre à jour config/packages/api_platform.yaml"
    echo "4. Tester l'application"
    echo ""
}

main