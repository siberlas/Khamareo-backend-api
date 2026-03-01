<?php

namespace App\Order\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Order\Dto\GuestDeliveryAddressInput;
use App\Cart\Entity\Cart;
use App\User\Entity\Address;
use App\Shipping\Service\AddressValidationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use App\Shared\Service\PhoneNumberService;

final class GuestDeliveryAddressProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private AddressValidationService $validationService,
        private PhoneNumberService $phoneNumberService
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        if (!$data instanceof GuestDeliveryAddressInput) {
            throw new BadRequestException('Payload invalide.');
        }

        $request = $this->requestStack->getCurrentRequest();
        $guestToken = $request?->query->get('guestToken');

        if (!$guestToken) {
            $this->logger->error('❌ guestToken manquant');
            throw new BadRequestException("Le token invité est requis pour continuer.");
        }

        $cart = $this->em->getRepository(Cart::class)->findOneBy([
            'guestToken' => $guestToken,
            'isActive' => true
        ]);

        if (!$cart) {
            $this->logger->error('❌ Panier introuvable', ['guestToken' => $guestToken]);
            throw new BadRequestException("Aucun panier actif trouvé pour ce token.");
        }

        $user = $cart->getOwner();
        if (!$user || !$user->isGuest()) {
            $this->logger->error('❌ Utilisateur invité introuvable pour ce panier', [
                'cart_id' => $cart->getId(),
                'guestToken' => $guestToken,
            ]);
            throw new BadRequestException("Aucun utilisateur invité associé à ce panier.");
        }

        $normalizedPhone = null;
        if ($data->phone) {
            try {
                $normalizedPhone = $this->phoneNumberService->normalizeToE164($data->phone, $data->country);
            } catch (\InvalidArgumentException $e) {
                throw new BadRequestException($e->getMessage());
            }
        }

        $deliveryAddress = new Address();
        $deliveryAddress
            ->setOwner($user)
            ->setAddressKind($data->addressKind)
            ->setFirstName($data->firstName)
            ->setLastName($data->lastName)
            ->setPhone($normalizedPhone)
            ->setStreetAddress($data->streetAddress)
            ->setPostalCode($data->postalCode)
            ->setCity($data->city)
            ->setCountry($data->country)
            ->setState($data->state)
            ->setIsDefault(false)
            ->setLabel('Adresse de livraison');

        if ($data->addressKind === 'relay') {
            $deliveryAddress
                ->setIsRelayPoint($data->isRelayPoint)
                ->setRelayPointId($data->relayPointId)
                ->setRelayCarrier($data->relayCarrier);
        }

        if ($data->addressKind !== 'relay') {
            $this->logger->info('🔍 [GUEST DELIVERY ADDRESS VALIDATION] Début de validation', [
                'type' => 'guest_user',
                'street' => $data->streetAddress,
                'postalCode' => $data->postalCode,
                'city' => $data->city,
                'country' => $data->country,
                'state' => $data->state,
                'guest_email' => $user->getEmail(),
            ]);

            $validationResult = $this->validationService->validateAddress(
                street: $data->streetAddress,
                postalCode: $data->postalCode,
                city: $data->city,
                country: $data->country,
                strict: false
            );

            if (!$validationResult['valid']) {
                $this->logger->warning('❌ [GUEST DELIVERY ADDRESS VALIDATION] Adresse INVALIDE', [
                    'type' => 'guest_user',
                    'message' => $validationResult['message'],
                    'street' => $data->streetAddress,
                    'guest_email' => $user->getEmail(),
                ]);
                throw new BadRequestException(
                    sprintf('Invalid delivery address: %s', $validationResult['message'])
                );
            }

            $this->logger->info('✅ [GUEST DELIVERY ADDRESS VALIDATION] Score validation acceptable', [
                'type' => 'guest_user',
                'source' => $validationResult['source'],
                'message' => $validationResult['message'],
            ]);

            if (!empty($validationResult['normalized'])) {
                $normalized = $validationResult['normalized'];

                if (isset($normalized['lat']) && $normalized['lat'] !== null) {
                    $deliveryAddress->setLatitude((float) $normalized['lat']);
                }
                if (isset($normalized['lon']) && $normalized['lon'] !== null) {
                    $deliveryAddress->setLongitude((float) $normalized['lon']);
                }
                if (!empty($normalized['street'])) {
                    $deliveryAddress->setStreetAddress($normalized['street']);
                }

                $this->logger->info('✅ [GUEST DELIVERY ADDRESS VALIDATION] Données normalisées appliquées', [
                    'lat' => $normalized['lat'] ?? null,
                    'lon' => $normalized['lon'] ?? null,
                    'street' => $normalized['street'] ?? null,
                    'state' => $data->state,
                ]);
            }

            if ($data->state !== null) {
                $deliveryAddress->setState($data->state);
            }
        } else {
            $this->logger->info('ℹ️ [GUEST DELIVERY ADDRESS VALIDATION] Adresse point relais - validation ignorée', [
                'type' => 'guest_user',
                'relayPointId' => $data->relayPointId,
                'relayCarrier' => $data->relayCarrier,
                'guest_email' => $user->getEmail(),
            ]);
        }

        $this->em->persist($deliveryAddress);
        $this->em->flush();

        $this->logger->info('✅ Adresse de livraison invité enregistrée avec succès', [
            'user_id' => $user->getId(),
            'cart_id' => $cart->getId(),
            'delivery_address_id' => $deliveryAddress->getId(),
        ]);

        return [
            'success' => true,
            'message' => 'Adresse de livraison invitée enregistrée avec succès.',
            'user' => '/api/users/' . $user->getId(),
            'deliveryAddress' => '/api/addresses/' . $deliveryAddress->getId(),
            'cart' => '/api/carts/' . $cart->getId(),
        ];
    }
}
