<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\PromoCode;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MailerService
{
    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        private string $fromEmail,
        private string $frontBaseUrl,
        private string $backendUrl
    ) {}

    /**
     * Envoie l'email de confirmation d'inscription
     */
    public function sendEmailConfirmation(User $user, string $token): void
    {
        try {
            $html = $this->twig->render('emails/user/registration_confirmation.html.twig', [
                'user' => $user,
                'confirmationUrl' => $this->backendUrl . '/api/confirm/' . $token,
            ]);

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($user->getEmail())
                ->subject('Confirmez votre inscription - Khamareo')
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Registration confirmation email sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send registration confirmation email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envoie l'email de réinitialisation de mot de passe
     */
    public function sendPasswordResetEmail(User $user, string $token): void
    {
        try {
            $html = $this->twig->render('emails/user/password_reset.html.twig', [
                'user' => $user,
                'resetUrl' => $this->frontBaseUrl . '/reset-password?token=' . $token,
            ]);

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($user->getEmail())
                ->subject('Réinitialisation de votre mot de passe - Khamareo')
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Password reset email sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send password reset email', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envoie l'email avec le code promo
     */
    public function sendPromoCodeEmail(PromoCode $promoCode): void
    {
        try {
            $discount = $promoCode->getDiscountPercentage()
                ? $promoCode->getDiscountPercentage() . '%'
                : $promoCode->getDiscountAmount() . '€';

            $typeMessages = [
                'newsletter' => 'Merci de votre inscription à notre newsletter !',
                'registration' => 'Bienvenue chez Khamareo !',
                'first_order' => 'Félicitations pour votre première commande !',
                'manual' => 'Vous avez reçu un code promo !',
            ];

            $message = $typeMessages[$promoCode->getType()] ?? 'Vous avez reçu un code promo !';

            $html = $this->twig->render('emails/promo/code.html.twig', [
                'promoCode' => $promoCode,
                'discount' => $discount,
                'message' => $message,
                'shopUrl' => $this->frontBaseUrl . '/boutique',
            ]);

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($promoCode->getEmail())
                ->subject('Votre code promo Khamareo 🎁')
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Promo code email sent', [
                'email' => $promoCode->getEmail(),
                'code' => $promoCode->getCode(),
                'type' => $promoCode->getType(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send promo code email', [
                'email' => $promoCode->getEmail(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envoie l'email de confirmation de commande
     */
    public function sendOrderConfirmation(Order $order): void
    {
        try {
            // Récupérer l'email (user connecté ou invité)
            $recipientEmail = $order->getOwner()
                ? $order->getOwner()->getEmail()
                : $order->getGuestEmail();

            if (!$recipientEmail) {
                throw new \InvalidArgumentException('Impossible de déterminer l\'email du destinataire');
            }

            // Récupérer le prénom
            $firstName = $order->getOwner()
                ? $order->getOwner()->getFirstName()
                : $order->getGuestFirstName();

            $greeting = $firstName ? "Bonjour {$firstName}" : "Bonjour";

            // Calcul du sous-total des items
            $itemsTotal = 0;
            foreach ($order->getItems() as $item) {
                $itemsTotal += $item->getQuantity() * (float) $item->getUnitPrice();
            }

            $html = $this->twig->render('emails/order/confirmation.html.twig', [
                'order' => $order,
                'greeting' => $greeting,
                'itemsTotal' => $itemsTotal,
                'trackingUrl' => $this->frontBaseUrl . '/api/orders/' . $order->getOrderNumber(),
            ]);

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject("Commande confirmée #{$order->getOrderNumber()} - Khamareo")
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Order confirmation email sent', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'email' => $recipientEmail,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order confirmation email', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
            // Ne pas throw pour ne pas bloquer le webhook
        }
    }
}