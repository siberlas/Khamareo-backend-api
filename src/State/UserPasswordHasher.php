<?php
// api/src/State/UserPasswordHasher.php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\MailerService;
use App\Service\PromoCodeService;
use Psr\Log\LoggerInterface;
use ApiPlatform\Metadata\Post;

/**
 * @implements ProcessorInterface<User, User|void>
 */
final readonly class UserPasswordHasher implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $processor,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerService $mailerService,
        private PromoCodeService $promoCodeService,
        private LoggerInterface $logger
    )
    {
    }
    /**
     * @param User $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        // Déterminer si c'est un nouvel utilisateur AVANT de modifier quoi que ce soit
        $isNewUser = !$data->getId() || $operation instanceof \ApiPlatform\Metadata\Post;
        $hasPlainPassword = $data->getPlainPassword() !== null;

         // 🔍 LOG DEBUG
        $this->logger->info('🔍 UserPasswordHasher - START', [
            'is_new_user' => $isNewUser,
            'has_plain_password' => $hasPlainPassword,
            'email' => $data->getEmail(),
            'user_id' => $data->getId(),
        ]);

        // Génération du token de confirmation AVANT d'effacer le plainPassword
        $token = null;
        if ($isNewUser && $hasPlainPassword) {
            $token = Uuid::v4()->toRfc4122();
            $data->setConfirmationToken($token);
            $data->setIsVerified(false);

            $this->logger->info('✅ Token généré', [
                'token' => $token,
                'email' => $data->getEmail(),
            ]);
        }

        // Hash du mot de passe si présent
        if ($hasPlainPassword) {
            $plainPassword = $data->getPlainPassword();
            $this->logger->info('🔐 Hashing password', [
                'email' => $data->getEmail(),
                'plain_password_length' => strlen($plainPassword),
            ]);
        
            $hashedPassword = $this->passwordHasher->hashPassword(
                $data,
                $data->getPlainPassword()
            );
            $data->setPassword($hashedPassword);
            $data->setPlainPassword(null); // Effacer APRÈS avoir généré le token

            $this->logger->info('✅ Password hashed', [
                'email' => $data->getEmail(),
                'hash_length' => strlen($hashedPassword),
            ]);
        }

      
        // Persister l'utilisateur via le processor existant
        $result = $this->processor->process($data, $operation, $uriVariables, $context);

        $this->logger->info('✅ User persisted', [
            'user_id' => $result->getId(),
            'email' => $result->getEmail(),
            'is_verified' => $result->isVerified(),
            'has_password' => !empty($result->getPassword()),
        ]);
        
        // Envoi de l'email de confirmation (logique existante)
        if ($isNewUser && $token) {
            try {
                $this->mailerService->sendEmailConfirmation($result, $token);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send confirmation email', [
                    'email' => $result->getEmail(),
                    'error' => $e->getMessage()
                ]);
                // Ne pas throw pour ne pas bloquer l'inscription
            }
        }
        
        if ($isNewUser) {
            try {
                $promoCode = $this->promoCodeService->handleUserRegistration(
                    $data->getEmail()
                );
                
                // Ajouter les infos du code promo à la réponse
                $result->setPromoCode($promoCode->getCode());
                $result->setPromoDiscountPercentage(
                    $promoCode->getDiscountPercentage() ? (float) $promoCode->getDiscountPercentage() : null
                );
                $result->setPromoDiscountAmount(
                    $promoCode->getDiscountAmount() ? (float) $promoCode->getDiscountAmount() : null
                );
                $result->setPromoExpiresAt($promoCode->getExpiresAt());
                
            } catch (\Exception $e) {
                // Log l'erreur mais ne bloque pas l'inscription
                $this->logger->error('Failed to create promo code for user registration', [
                    'email' => $data->getEmail(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $result;
    }
}