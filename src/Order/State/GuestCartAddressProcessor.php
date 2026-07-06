<?php

namespace App\Order\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Order\Dto\GuestCartAddressInput;
use App\Cart\Entity\Cart;
use App\User\Entity\Address;
use App\User\Entity\User;
use App\Shared\Exception\AccountExistsException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

final class GuestCartAddressProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        if (!$data instanceof GuestCartAddressInput) {
            throw new BadRequestException('Payload invalide.');
        }

        // 1️⃣ Récupérer le guestToken
        $request = $this->requestStack->getCurrentRequest();
        $guestToken = $request?->query->get('guestToken');

        if (!$guestToken) {
            $this->logger->error('❌ guestToken manquant');
            throw new BadRequestException("Le token invité est requis pour continuer.");
        }

        // 2️⃣ Récupérer le panier
        $cart = $this->em->getRepository(Cart::class)->findOneBy([
            'guestToken' => $guestToken,
            'isActive' => true
        ]);

        if (!$cart) {
            $this->logger->error('❌ Panier introuvable', ['guestToken' => $guestToken]);
            throw new BadRequestException("Aucun panier actif trouvé pour ce token.");
        }

        // 3️⃣ Cas 1 : le panier a déjà un owner → ajout d'adresse alternative (email/CGV déjà collectés)
        $existingOwner = $cart->getOwner();

        if ($existingOwner !== null) {
            $this->logger->info('✅ Ajout adresse alternative pour invité existant', [
                'cart_id' => $cart->getId(),
                'user_id' => $existingOwner->getId(),
            ]);

            $user = $existingOwner;

        } else {
            // Cas 2 : checkout initial → email + téléphone + CGV obligatoires
            if (!$data->email) {
                throw new BadRequestException("L'adresse email est obligatoire pour continuer.");
            }

            if (!$data->phone) {
                throw new BadRequestException("Le numéro de téléphone est obligatoire pour continuer.");
            }

            if (!$data->hasAcceptedTerms) {
                throw new BadRequestException("Vous devez accepter les conditions générales pour continuer.");
            }

            $this->logger->info('✅ Panier trouvé (checkout initial)', [
                'cart_id' => $cart->getId(),
                'email' => $data->email,
            ]);

            $existingUser = $this->em->getRepository(User::class)
                ->findOneBy(['email' => $data->email]);

            if ($existingUser) {
                if (!$existingUser->isGuest()) {
                    $this->logger->warning('⚠️ Tentative commande invitée avec compte existant', [
                        'email' => $data->email,
                        'user_id' => $existingUser->getId(),
                    ]);

                    throw new AccountExistsException($data->email);
                }

                $this->logger->info('✅ Mise à jour invité existant', [
                    'user_id' => $existingUser->getId(),
                ]);

                $user = $existingUser;
                $user
                    ->setFirstName($data->firstName)
                    ->setLastName($data->lastName)
                    ->setPhone($data->phone)
                    ->setHasAcceptedGuestTerms(true);

            } else {
                $this->logger->info('✅ Création nouvel invité', ['email' => $data->email]);

                $user = new User();
                $user
                    ->setEmail($data->email)
                    ->setFirstName($data->firstName)
                    ->setLastName($data->lastName)
                    ->setPhone($data->phone)
                    ->setIsGuest(true)
                    ->setIsVerified(false)
                    ->setHasAcceptedGuestTerms(true);

                $randomPassword = bin2hex(random_bytes(16));
                $hashedPassword = $this->passwordHasher->hashPassword($user, $randomPassword);
                $user->setPassword($hashedPassword);

                $this->em->persist($user);
            }

            $cart->setOwner($user);
        }

        // 4️⃣ Créer l'adresse de livraison
        $this->logger->info('🔎 [GUEST CART ADDRESS] Données reçues', [
            'guestToken' => $guestToken,
            'country' => $data->country,
            'state' => $data->state,
            'postalCode' => $data->postalCode,
        ]);
        $deliveryAddress = new Address();
        $deliveryAddress
            ->setOwner($user)
            ->setAddressKind('personal')
            ->setFirstName($data->firstName)
            ->setLastName($data->lastName)
            ->setPhone($data->phone)
            ->setStreetAddress($data->streetAddress)
            ->setAddressComplement($data->addressComplement)
            ->setPostalCode($data->postalCode)
            ->setCity($data->city)
            ->setCountry($data->country)
            ->setState($data->state)
            ->setIsDefault(true)
            ->setLabel('Adresse de livraison');

        $this->em->persist($deliveryAddress);

        // 5️⃣ Adresse de facturation = livraison
        $billingAddress = $deliveryAddress;

        // 6️⃣ Sauvegarder
        $this->em->flush();

        $addressVerified = $deliveryAddress->getGeocodingVerified();

        $this->logger->info('✅ Adresse invité enregistrée avec succès', [
            'user_id' => $user->getId(),
            'cart_id' => $cart->getId(),
            'guest_expires_at' => $user->getGuestExpiresAt()?->format('Y-m-d H:i:s'),
            'address_verified' => $addressVerified,
        ]);

        $warnings = [];
        if ($addressVerified === false) {
            $warnings[] = 'address_unverified';
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Adresse invité enregistrée avec succès.',
            'user' => '/api/users/' . $user->getId(),
            'deliveryAddress' => '/api/addresses/' . $deliveryAddress->getId(),
            'billingAddress' => '/api/addresses/' . $billingAddress->getId(),
            'cart' => '/api/carts/' . $cart->getId(),
            'guestExpiresAt' => $user->getGuestExpiresAt()?->format('c'),
            'warnings' => $warnings,
            'addressVerified' => $addressVerified,
        ]);
    }
}
