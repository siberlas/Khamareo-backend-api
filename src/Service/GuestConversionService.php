<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

class GuestConversionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerService $mailerService,
        private LoggerInterface $logger
    ) {}

    /**
     * Convertir un invité en utilisateur réel
     */
    public function convertGuestToUser(User $guestUser, string $password): void
    {
        if (!$guestUser->isGuest()) {
            throw new \LogicException('Cet utilisateur n\'est pas un invité.');
        }

        $this->logger->info('🔄 Conversion invité → utilisateur', [
            'user_id' => $guestUser->getId(),
            'email' => $guestUser->getEmail()
        ]);

        // Générer token de confirmation
        $confirmationToken = Uuid::v4()->toRfc4122();

        // Transformer en utilisateur réel
        $guestUser
            ->setIsGuest(false)
            ->setIsVerified(false) // Doit confirmer son email
            ->setGuestExpiresAt(null) // Plus besoin d'expiration
            ->setConfirmationToken($confirmationToken)
            ->setPassword($this->passwordHasher->hashPassword($guestUser, $password));

        $this->em->flush();

        // Envoyer email de confirmation
        try {
            $this->mailerService->sendEmailConfirmation($guestUser, $confirmationToken);
            
            $this->logger->info('✅ Email de confirmation envoyé', [
                'user_id' => $guestUser->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur envoi email confirmation', [
                'user_id' => $guestUser->getId(),
                'error' => $e->getMessage()
            ]);
            // Ne pas bloquer la conversion si l'email échoue
        }

        $this->logger->info('✅ Conversion réussie', [
            'user_id' => $guestUser->getId()
        ]);
    }

    /**
     * Vérifier si un invité peut être converti
     */
    public function canConvert(User $user): bool
    {
        return $user->isGuest() && !$user->isGuestExpired();
    }
}