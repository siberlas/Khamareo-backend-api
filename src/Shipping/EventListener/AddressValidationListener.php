<?php

namespace App\Shipping\EventListener;

use App\User\Entity\Address;
use App\Shipping\Service\AddressValidationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class AddressValidationListener
{
    public function __construct(
        private AddressValidationService $validationService,
        private LoggerInterface $logger
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->validateAddress($args->getObject());
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Address) {
            return;
        }

        // Si les champs d'adresse ont changé, invalider les anciennes coordonnées
        // pour forcer la re-validation par texte (pas par reverse geocode des anciens coords)
        $addressFieldsChanged = $args->hasChangedField('streetAddress')
            || $args->hasChangedField('city')
            || $args->hasChangedField('postalCode');

        if ($addressFieldsChanged) {
            $this->logger->info('🔄 [ADDRESS VALIDATION] Address fields changed, clearing old coordinates', [
                'changedFields' => array_filter([
                    'streetAddress' => $args->hasChangedField('streetAddress'),
                    'city' => $args->hasChangedField('city'),
                    'postalCode' => $args->hasChangedField('postalCode'),
                ]),
            ]);
            $entity->setLatitude(null);
            $entity->setLongitude(null);
        }

        $this->validateAddress($entity);
    }

    private function validateAddress(mixed $entity): void
    {
        if (!$entity instanceof Address) {
            return;
        }

        // Points relais: ne pas valider via géocodage (adresse fournie par le transporteur)
        if ($entity->isRelayPoint() || $entity->getAddressKind() === 'relay') {
            $this->logger->info('ℹ️ [ADDRESS VALIDATION] Relay address skipped', [
                'type' => 'connected_user',
                'relayPointId' => $entity->getRelayPointId(),
                'relayCarrier' => $entity->getRelayCarrier(),
                'street' => $entity->getStreetAddress(),
                'city' => $entity->getCity(),
            ]);
            return;
        }

        $this->logger->info('🔍 [ADDRESS VALIDATION] Début de validation', [
            'type' => 'connected_user',
            'street' => $entity->getStreetAddress(),
            'postalCode' => $entity->getPostalCode(),
            'city' => $entity->getCity(),
            'country' => $entity->getCountry(),
            'owner' => $entity->getOwner()?->getId(),
        ]);

        $result = $this->validationService->validateAddress(
            street: $entity->getStreetAddress(),
            postalCode: $entity->getPostalCode(),
            city: $entity->getCity(),
            country: $entity->getCountry(),
            lat: $entity->getLatitude(),
            lon: $entity->getLongitude(),
            strict: false
        );

        if (!$result['valid']) {
            $this->logger->warning('❌ [ADDRESS VALIDATION] Adresse INVALIDE', [
                'type' => 'connected_user',
                'message' => $result['message'],
                'source' => $result['source'],
                'street' => $entity->getStreetAddress(),
            ]);

            throw new BadRequestHttpException(
                sprintf('Invalid address: %s', $result['message'])
            );
        }

        // Déduire si l'adresse a été vérifiée par le géocodeur.
        // source = null → acceptée sans vérification → non vérifiée.
        $geocodingVerified = $result['source'] !== null;
        $entity->setGeocodingVerified($geocodingVerified);

        // Si une adresse normalisée est disponible, on n'applique que les coordonnées.
        // La rue, le code postal et la ville saisies par l'utilisateur sont TOUJOURS conservées.
        if (isset($result['normalized']) && $result['normalized']) {
            $normalized = $result['normalized'];

            if (isset($normalized['lat'])) {
                $entity->setLatitude($normalized['lat']);
            }
            if (isset($normalized['lon'])) {
                $entity->setLongitude($normalized['lon']);
            }

            $this->logger->info('✏️ [ADDRESS VALIDATION] Coordonnées mises à jour', [
                'type' => 'connected_user',
                'source' => $result['source'],
                'geocoding_verified' => $geocodingVerified,
                'lat' => $normalized['lat'] ?? null,
                'lon' => $normalized['lon'] ?? null,
            ]);
        }

        if (!$geocodingVerified) {
            $this->logger->warning('⚠️ [ADDRESS VALIDATION] Adresse non vérifiée par géocodeur — conservée telle quelle', [
                'street' => $entity->getStreetAddress(),
                'postalCode' => $entity->getPostalCode(),
                'city' => $entity->getCity(),
                'country' => $entity->getCountry(),
            ]);
        }

        $this->logger->info('✅ [ADDRESS VALIDATION] Adresse SAUVEGARDÉE', [
            'type' => 'connected_user',
            'source' => $result['source'],
            'message' => $result['message'],
            'street' => $entity->getStreetAddress(),
            'city' => $entity->getCity(),
        ]);
    }
}
