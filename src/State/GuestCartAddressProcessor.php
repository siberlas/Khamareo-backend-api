<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\GuestCartAddressInput;
use App\Entity\Cart;
use App\Entity\Address;
use App\Entity\User;
use App\Exception\AccountExistsException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
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

        // ✅ NOUVEAU : Vérifier le consentement RGPD
        if (!$data->hasAcceptedTerms) {
            throw new BadRequestException("Vous devez accepter les conditions générales pour continuer.");
        }

        $this->logger->info('✅ Panier trouvé', [
            'cart_id' => $cart->getId(),
            'email' => $data->email
        ]);

        // 3️⃣ Vérifier si l'email existe déjà
        $existingUser = $this->em->getRepository(User::class)
            ->findOneBy(['email' => $data->email]);

        if ($existingUser) {
            if (!$existingUser->isGuest()) {
                // ⚠️ Compte utilisateur réel existant
                $this->logger->warning('⚠️ Tentative commande invitée avec compte existant', [
                    'email' => $data->email,
                    'user_id' => $existingUser->getId(),
                ]);

                throw new AccountExistsException($data->email);
            }

            // ✅ Invité existant : mise à jour
            $this->logger->info('✅ Mise à jour invité existant', [
                'user_id' => $existingUser->getId()
            ]);

            $user = $existingUser;
            $user
                ->setFirstName($data->firstName)
                ->setLastName($data->lastName)
                ->setPhone($data->phone)
                ->setHasAcceptedGuestTerms(true); // ✅ Update consent

        } else {
            // ✅ Nouvel invité : création
            $this->logger->info('✅ Création nouvel invité', ['email' => $data->email]);

            $user = new User();
            $user
                ->setEmail($data->email)
                ->setFirstName($data->firstName)
                ->setLastName($data->lastName)
                ->setPhone($data->phone)
                ->setIsGuest(true)
                ->setIsVerified(false)
                ->setHasAcceptedGuestTerms(true); // ✅ NOUVEAU

            // ✅ guestExpiresAt sera défini automatiquement par PrePersist

            // Mot de passe aléatoire (sécurité)
            $randomPassword = bin2hex(random_bytes(16));
            $hashedPassword = $this->passwordHasher->hashPassword($user, $randomPassword);
            $user->setPassword($hashedPassword);

            $this->em->persist($user);
        }

        // 4️⃣ Créer l'adresse de livraison
        $deliveryAddress = new Address();
        $deliveryAddress
            ->setOwner($user)
            ->setAddressKind('personal')
            ->setFirstName($data->firstName)
            ->setLastName($data->lastName)
            ->setPhone($data->phone)
            ->setStreetAddress($data->streetAddress)
            ->setPostalCode($data->postalCode)
            ->setCity($data->city)
            ->setCountry($data->country)
            ->setIsDefault(true)
            ->setLabel('Adresse de livraison');

        $this->em->persist($deliveryAddress);

        // 5️⃣ Adresse de facturation = livraison
        $billingAddress = $deliveryAddress;

        // 6️⃣ Lier le panier à l'utilisateur invité
        $cart->setOwner($user);

        // 7️⃣ Sauvegarder
        $this->em->flush();

        $this->logger->info('✅ Adresse invité enregistrée avec succès', [
            'user_id' => $user->getId(),
            'cart_id' => $cart->getId(),
            'guest_expires_at' => $user->getGuestExpiresAt()?->format('Y-m-d H:i:s'),
        ]);

        // 8️⃣ Réponse
        return [
            'success' => true,
            'message' => 'Adresse invité enregistrée avec succès.',
            'user' => '/api/users/' . $user->getId(),
            'deliveryAddress' => '/api/addresses/' . $deliveryAddress->getId(),
            'billingAddress' => '/api/addresses/' . $billingAddress->getId(),
            'cart' => '/api/carts/' . $cart->getId(),
            'guestExpiresAt' => $user->getGuestExpiresAt()?->format('c'),
        ];
    }
}