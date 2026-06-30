<?php

namespace App\Order\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Order\Dto\GuestDeliveryAddressInput;
use App\Cart\Entity\Cart;
use App\User\Entity\Address;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use App\Shared\Service\PhoneNumberService;

final class GuestDeliveryAddressProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
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

        // La validation (géocodage + coordonnées + geocodingVerified) est gérée
        // par AddressValidationListener (prePersist/preUpdate). Ne pas modifier
        // street/city/postalCode ici : l'adresse saisie est toujours conservée.

        $this->em->persist($deliveryAddress);
        $this->em->flush();

        $addressVerified = $deliveryAddress->getGeocodingVerified();
        $warnings = [];
        if ($addressVerified === false) {
            $warnings[] = 'address_unverified';
        }

        $this->logger->info('✅ Adresse de livraison invité enregistrée avec succès', [
            'user_id' => $user->getId(),
            'cart_id' => $cart->getId(),
            'delivery_address_id' => $deliveryAddress->getId(),
            'address_verified' => $addressVerified,
        ]);

        return new JsonResponse([
            'success' => true,
            'message' => 'Adresse de livraison invitée enregistrée avec succès.',
            'user' => '/api/users/' . $user->getId(),
            'deliveryAddress' => '/api/addresses/' . $deliveryAddress->getId(),
            'cart' => '/api/carts/' . $cart->getId(),
            'warnings' => $warnings,
            'addressVerified' => $addressVerified,
        ]);
    }
}
