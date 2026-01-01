<?php

namespace App\Shared\Service;

use App\Order\Entity\Order;
use App\Marketing\Entity\PromoCode;
use App\User\Entity\User;
use App\Contact\Entity\ContactMessage;
use App\Marketing\Entity\StockAlert;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MailerService
{
    // Map centralisée de tous les sujets d'emails FR/EN
    private const EMAIL_SUBJECTS = [
        'registration' => [
            'fr' => '🌿 Confirmez votre inscription - Khamareo',
            'en' => '🌿 Confirm Your Registration - Khamareo'
        ],
        'password_reset' => [
            'fr' => '🔐 Réinitialisation de votre mot de passe - Khamareo',
            'en' => '🔐 Reset Your Password - Khamareo'
        ],
        'promo_code' => [
            'fr' => '🎁 Votre code promo Khamareo',
            'en' => '🎁 Your Khamareo Promo Code'
        ],
        'order_confirmation' => [
            'fr' => '✅ Commande confirmée #{orderNumber} - Khamareo',
            'en' => '✅ Order Confirmed #{orderNumber} - Khamareo'
        ],
        'contact_notification' => [
            'fr' => '📧 Nouveau message de contact - {subject}',
            'en' => '📧 New Contact Message - {subject}'
        ],
        'contact_confirmation' => [
            'fr' => '✉️ Message reçu - Khamareo',
            'en' => '✉️ Message Received - Khamareo'
        ],
        'stock_alert' => [
            'fr' => '🎉 {productName} est de nouveau en stock !',
            'en' => '🎉 {productName} is Back in Stock!'
        ],
    ];

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        private string $fromEmail,
        private string $frontBaseUrl,
        private string $backendUrl
    ) {}

    /**
     * Détermine la locale pour un email en fonction du contexte
     */
    private function getEmailLocale(
        ?User $user = null, 
        ?Order $order = null, 
        ?string $fallbackLocale = null
    ): string {
        // 1. Utilisateur connecté → sa préférence
        if ($user && $user->getPreferredLanguage()) {
            return $user->getPreferredLanguage();
        }
        
        // 2. Order avec owner
        if ($order && $order->getOwner() && $order->getOwner()->getPreferredLanguage()) {
            return $order->getOwner()->getPreferredLanguage();
        }
        
        // 3. Order invité → locale de la commande
        if ($order && method_exists($order, 'getLocale') && $order->getLocale()) {
            return $order->getLocale();
        }
        
        // 4. Locale fournie explicitement
        if ($fallbackLocale && in_array($fallbackLocale, ['fr', 'en'])) {
            return $fallbackLocale;
        }
        
        // 5. Fallback par défaut
        return 'fr';
    }

    /**
     * Retourne le sujet de l'email dans la bonne langue
     */
    private function getSubject(string $type, string $locale, array $params = []): string
    {
        if (!isset(self::EMAIL_SUBJECTS[$type])) {
            throw new \InvalidArgumentException("Email type '{$type}' not found");
        }

        $subject = self::EMAIL_SUBJECTS[$type][$locale] ?? self::EMAIL_SUBJECTS[$type]['fr'];

        // Remplacer les placeholders {key} par les valeurs
        foreach ($params as $key => $value) {
            $subject = str_replace('{' . $key . '}', $value, $subject);
        }

        return $subject;
    }

    /**
     * Retourne le chemin du template dans la bonne langue
     */
    private function getTemplate(string $basePath, string $locale): string
    {
        return "{$basePath}.{$locale}.html.twig";
    }

    /**
     * Envoie l'email de confirmation d'inscription
     */
    public function sendEmailConfirmation(User $user, string $token): void
    {
        try {
            $locale = $this->getEmailLocale($user);

            $html = $this->twig->render(
                $this->getTemplate('emails/user/registration_confirmation', $locale),
                [
                    'user' => $user,
                    'confirmationUrl' => $this->backendUrl . '/api/confirm/' . $token,
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($user->getEmail())
                ->subject($this->getSubject('registration', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Registration confirmation email sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'locale' => $locale,
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
            $locale = $this->getEmailLocale($user);

            $html = $this->twig->render(
                $this->getTemplate('emails/user/password_reset', $locale),
                [
                    'user' => $user,
                    'resetUrl' => $this->frontBaseUrl . '/reset-password?token=' . $token . '&lang=' . $locale,
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($user->getEmail())
                ->subject($this->getSubject('password_reset', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Password reset email sent', [
                'user_id' => $user->getId(),
                'email' => $user->getEmail(),
                'locale' => $locale,
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
    public function sendPromoCodeEmail(PromoCode $promoCode, ?string $locale = null): void
    {
        try {
            $locale = $locale ?? 'fr'; // Pas de User, utiliser locale fournie ou FR

            $discount = $promoCode->getDiscountPercentage()
                ? $promoCode->getDiscountPercentage() . '%'
                : $promoCode->getDiscountAmount() . '€';

            $typeMessages = [
                'newsletter' => [
                    'fr' => 'Merci de votre inscription à notre newsletter !',
                    'en' => 'Thank you for subscribing to our newsletter!'
                ],
                'registration' => [
                    'fr' => 'Bienvenue chez Khamareo !',
                    'en' => 'Welcome to Khamareo!'
                ],
                'first_order' => [
                    'fr' => 'Félicitations pour votre première commande !',
                    'en' => 'Congratulations on your first order!'
                ],
                'manual' => [
                    'fr' => 'Vous avez reçu un code promo !',
                    'en' => 'You received a promo code!'
                ],
            ];

            $message = $typeMessages[$promoCode->getType()][$locale] 
                ?? $typeMessages['manual'][$locale];

            $html = $this->twig->render(
                $this->getTemplate('emails/promo/code', $locale),
                [
                    'promoCode' => $promoCode,
                    'discount' => $discount,
                    'message' => $message,
                    'shopUrl' => $this->frontBaseUrl . '/boutique',
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($promoCode->getEmail())
                ->subject($this->getSubject('promo_code', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Promo code email sent', [
                'email' => $promoCode->getEmail(),
                'code' => $promoCode->getCode(),
                'type' => $promoCode->getType(),
                'locale' => $locale,
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
            $locale = $this->getEmailLocale(null, $order);

            $this->logger->info('📧 Sending order confirmation email', [
                'order_id' => $order->getId(),
                'email' => $order->getOwner() ? $order->getOwner()->getEmail() : $order->getGuestEmail(),
                'locale' => $locale
            ]);

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

            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            // Calcul du sous-total des items
            $itemsTotal = 0;
            foreach ($order->getItems() as $item) {
                $itemsTotal += $item->getQuantity() * (float) $item->getUnitPrice();
            }

            $html = $this->twig->render(
                $this->getTemplate('emails/order/confirmation', $locale),
                [
                    'order' => $order,
                    'greeting' => $greetings[$locale],
                    'itemsTotal' => $itemsTotal,
                    'trackingUrl' => $this->frontBaseUrl . '/orders/' . $order->getOrderNumber(),
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('order_confirmation', $locale, [
                    'orderNumber' => $order->getOrderNumber()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Order confirmation email sent', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'email' => $recipientEmail,
                'locale' => $locale,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send order confirmation email', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
            // Ne pas throw pour ne pas bloquer le webhook
        }
    }

    /**
     * Envoie une notification de contact à l'admin (toujours FR)
     */
    public function sendContactNotification(ContactMessage $message): void
    {
        try {
            $html = $this->twig->render(
                $this->getTemplate('emails/admin/contact_notification', 'fr'),
                [
                    'message' => $message,
                    'locale' => 'fr',
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to('contact@khamareo.com')
                ->replyTo($message->getEmail())
                ->subject($this->getSubject('contact_notification', 'fr', [
                    'subject' => $message->getSubject()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Contact notification sent', [
                'contact_id' => $message->getId(),
                'email' => $message->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send contact notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie une confirmation au client
     */
    public function sendContactConfirmation(ContactMessage $message, ?string $locale = null): void
    {
        try {
            $locale = $locale ?? 'fr';

            $html = $this->twig->render(
                $this->getTemplate('emails/contact/confirmation', $locale),
                [
                    'message' => $message,
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($message->getEmail())
                ->subject($this->getSubject('contact_confirmation', $locale))
                ->html($html);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send contact confirmation', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie une notification d'alerte stock
     */
    public function sendStockAlertNotification(StockAlert $alert): void
    {
        try {
            $product = $alert->getProduct();
            $user = $alert->getOwner();
            $locale = $this->getEmailLocale($user);
            
            $html = $this->twig->render(
                $this->getTemplate('emails/stock/alert', $locale),
                [
                    'user' => $user,
                    'product' => $product,
                    'productUrl' => $this->frontBaseUrl . '/boutique/' . $product->getSlug(),
                    'manageAlertsUrl' => $this->frontBaseUrl . '/mon-compte/alertes',
                    'frontendUrl' => $this->frontBaseUrl,
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($user->getEmail())
                ->subject($this->getSubject('stock_alert', $locale, [
                    'productName' => $product->getName()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Stock alert notification sent', [
                'alert_id' => $alert->getId(),
                'user_id' => $user->getId(),
                'product_id' => $product->getId(),
                'locale' => $locale,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send stock alert', [
                'alert_id' => $alert->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}