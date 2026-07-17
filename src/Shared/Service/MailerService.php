<?php

namespace App\Shared\Service;

use App\Cart\Entity\Cart;
use App\Order\Entity\Order;
use App\Marketing\Entity\NewsletterSubscriber;
use App\Marketing\Entity\PromoCode;
use App\User\Entity\User;
use App\Contact\Entity\ContactConversation;
use App\Contact\Entity\ContactMessage;
use App\Marketing\Entity\StockAlert;
use App\Shipping\Entity\Parcel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MailerService
{
    // Map centralisée de tous les sujets d'emails FR/EN
    private const EMAIL_SUBJECTS = [
        'registration' => [
            'fr' => 'Confirmez votre inscription - Khamareo',
            'en' => 'Confirm Your Registration - Khamareo'
        ],
        'password_reset' => [
            'fr' => 'Réinitialisation de votre mot de passe - Khamareo',
            'en' => 'Reset Your Password - Khamareo'
        ],
        'promo_code' => [
            'fr' => 'Votre code promo Khamareo',
            'en' => 'Your Khamareo Promo Code'
        ],
        'order_confirmation' => [
            'fr' => 'Commande confirmée #{orderNumber} - Khamareo',
            'en' => 'Order Confirmed #{orderNumber} - Khamareo'
        ],
        'contact_notification' => [
            'fr' => 'Nouveau message de contact - {subject}',
            'en' => 'New Contact Message - {subject}'
        ],
        'contact_confirmation' => [
            'fr' => 'Message reçu - Khamareo',
            'en' => 'Message Received - Khamareo'
        ],
        'stock_alert' => [
            'fr' => '{productName} est de nouveau en stock ! - Khamareo',
            'en' => '{productName} is Back in Stock! - Khamareo'
        ],
        'order_preparing' => [
            'fr' => 'Votre commande #{orderNumber} est en préparation - Khamareo',
            'en' => 'Your Order #{orderNumber} is Being Prepared - Khamareo'
        ],
        'order_shipped' => [
            'fr' => 'Votre commande #{orderNumber} est en route ! - Khamareo',
            'en' => 'Your Order #{orderNumber} is on its Way! - Khamareo'
        ],
        'parcel_shipped' => [
            'fr' => 'Colis {parcelNumber}/{totalParcels} expédié — Commande #{orderNumber} - Khamareo',
            'en' => 'Parcel {parcelNumber}/{totalParcels} shipped — Order #{orderNumber} - Khamareo'
        ],
        'order_delivered' => [
            'fr' => 'Votre commande #{orderNumber} a été livrée - Khamareo',
            'en' => 'Your Order #{orderNumber} has been Delivered - Khamareo'
        ],
        'newsletter_confirmation' => [
            'fr' => 'Confirmez votre inscription à la newsletter - Khamareo',
            'en' => 'Confirm Your Newsletter Subscription - Khamareo'
        ],
        'newsletter_reminder' => [
            'fr' => 'Il ne reste qu\'une étape avant de rejoindre Khamareo',
            'en' => 'One last step to join Khamareo'
        ],
        'order_refund' => [
            'fr' => 'Remboursement effectué #{orderNumber} - Khamareo',
            'en' => 'Refund Processed #{orderNumber} - Khamareo'
        ],
        'order_cancellation' => [
            'fr' => 'Annulation de votre commande #{orderNumber} - Khamareo',
            'en' => 'Your Order #{orderNumber} Has Been Cancelled - Khamareo'
        ],
        'cart_checkout_issue_recovery' => [
            'fr' => 'Votre panier vous attend toujours',
            'en' => 'Your cart is still waiting for you'
        ],
        'cart_reminder_stage1_guest' => [
            'fr' => 'Vous avez oublié quelque chose chez Khamareo',
            'en' => 'You forgot something at Khamareo'
        ],
        'cart_reminder_stage1_user' => [
            'fr' => 'Votre panier vous attend',
            'en' => 'Your cart is waiting for you'
        ],
        'cart_reminder_stage2' => [
            'fr' => 'Encore là ? Voici pourquoi nos clientes nous font confiance',
            'en' => 'Still here? Here\'s why our customers trust us'
        ],
        'cart_reminder_stage3' => [
            'fr' => '-10% sur votre panier, valable 48h',
            'en' => '-10% on your cart, valid for 48h'
        ],
        'promo_reminder_rappel' => [
            'fr' => 'Votre code promo Khamareo vous attend',
            'en' => 'Your Khamareo promo code is waiting'
        ],
        'promo_reminder_urgency' => [
            'fr' => 'Dernier jour pour profiter de votre code promo',
            'en' => 'Last day to use your promo code'
        ],
    ];

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
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
    public function sendEmailConfirmation(User $user, string $token, bool $newsletterSubscribed = false): void
    {
        try {
            $locale = $this->getEmailLocale($user);

            $html = $this->twig->render(
                $this->getTemplate('emails/user/registration_confirmation', $locale),
                [
                    'user' => $user,
                    'confirmationUrl' => $this->backendUrl . '/api/confirm/' . $token,
                    'newsletterSubscribed' => $newsletterSubscribed,
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
     * Relance "rappel" (Email 1) pour un code promo non utilisé — Segment 3
     * du cron marketing. Ton neutre, met en avant les best-sellers du moment.
     *
     * @param array{id:mixed,name:string,slug:string,price:string}[] $products
     */
    public function sendPromoCodeReminderRappel(PromoCode $promoCode, array $products, string $locale = 'fr'): void
    {
        try {
            $discount = $promoCode->getDiscountPercentage()
                ? $promoCode->getDiscountPercentage() . '%'
                : $promoCode->getDiscountAmount() . '€';

            $html = $this->twig->render(
                $this->getTemplate('emails/promo/reminder_rappel', $locale),
                [
                    'promoCode' => $promoCode,
                    'discount' => $discount,
                    'products' => $products,
                    'shopUrl' => $this->frontBaseUrl . '/boutique',
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($promoCode->getEmail())
                ->subject($this->getSubject('promo_reminder_rappel', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Promo code reminder (rappel) email sent', [
                'email' => $promoCode->getEmail(),
                'code' => $promoCode->getCode(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send promo code reminder (rappel) email', [
                'email' => $promoCode->getEmail(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Relance "urgence" (Email 2) pour un code promo non utilisé, à J-3 avant
     * expiration — Segment 3 du cron marketing. Ton direct, assumé.
     *
     * @param array{id:int,name:string,slug:string,price:string} $product
     */
    public function sendPromoCodeReminderUrgency(PromoCode $promoCode, array $product, string $locale = 'fr'): void
    {
        try {
            $discount = $promoCode->getDiscountPercentage()
                ? $promoCode->getDiscountPercentage() . '%'
                : $promoCode->getDiscountAmount() . '€';

            $html = $this->twig->render(
                $this->getTemplate('emails/promo/reminder_urgency', $locale),
                [
                    'promoCode' => $promoCode,
                    'discount' => $discount,
                    'product' => $product,
                    'shopUrl' => $this->frontBaseUrl . '/boutique',
                    'productUrl' => $this->frontBaseUrl . '/produit/' . $product['slug'],
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($promoCode->getEmail())
                ->subject($this->getSubject('promo_reminder_urgency', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Promo code reminder (urgency) email sent', [
                'email' => $promoCode->getEmail(),
                'code' => $promoCode->getCode(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send promo code reminder (urgency) email', [
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
        // Idempotence: ne pas renvoyer si déjà envoyé
        if ($order->isConfirmationEmailSent()) {
            $this->logger->info('📧 Order confirmation email already sent (skipped)', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
            ]);
            return;
        }

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
                    'trackingUrl' => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
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

            // Marquer l'email comme envoyé pour éviter les doublons
            $order->setConfirmationEmailSent(true);
            $this->em->flush();

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
                ->to($this->fromEmail)
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
     * Envoie la réponse admin au client via son email de contact
     */
    public function sendContactReply(ContactConversation $conversation, string $replyText): void
    {
        try {
            $locale   = in_array($conversation->getLocale(), ['fr', 'en'], true) ? $conversation->getLocale() : 'fr';
            $template = "emails/contact/admin_reply.{$locale}.html.twig";
            $html = $this->twig->render($template, [
                'conversation' => $conversation,
                'replyText'    => $replyText,
            ]);

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($conversation->getEmail())
                ->replyTo($this->fromEmail)
                ->subject('Réponse à votre message - Khamareo')
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Contact reply sent', [
                'conversation_id' => $conversation->getId(),
                'to'              => $conversation->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send contact reply', [
                'conversation_id' => $conversation->getId(),
                'error'           => $e->getMessage(),
            ]);
            throw $e;
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
                    'productUrl' => $this->frontBaseUrl . '/product/' . $product->getSlug(),
                    'manageAlertsUrl' => $this->frontBaseUrl . '/account/alerts',
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

    /**
     * Notifie le client que sa commande est en préparation
     */
    public function sendPreparingNotification(Order $order): void
    {
        try {
            $locale = $this->getEmailLocale(null, $order);

            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
            if (!$recipientEmail) {
                return;
            }

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/order/preparing', $locale),
                [
                    'order'         => $order,
                    'greeting'      => $greetings[$locale],
                    'dispatchDelay' => null,
                    'deliveryDelay' => null,
                    'deliveryNote'  => null,
                    'orderUrl'      => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
                    'locale'        => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('order_preparing', $locale, [
                    'orderNumber' => $order->getOrderNumber()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Preparing notification sent', [
                'order_number' => $order->getOrderNumber(),
                'email'        => $recipientEmail,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send preparing notification', [
                'order_number' => $order->getOrderNumber(),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie le client que sa commande a été expédiée
     */
    public function sendShippingNotification(Order $order): void
    {
        try {
            $locale = $this->getEmailLocale(null, $order);

            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
            if (!$recipientEmail) {
                return;
            }

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            // Pas de fallback sur getShippingLabel() : les commandes payées avant la
            // suppression du stub PaymentStatusSubscriber/ShippingLabelService ont encore
            // un faux numéro "TEST-..." non exploitable sur ce champ. Order::trackingNumber
            // est la seule source fiable (saisie/génération admin réelle).
            $trackingNumber = $order->getTrackingNumber();

            $html = $this->twig->render(
                $this->getTemplate('emails/order/shipped', $locale),
                [
                    'order'              => $order,
                    'greeting'           => $greetings[$locale],
                    'trackingNumber'     => $trackingNumber,
                    'carrierTrackingUrl' => null,
                    'deliveryDelay'      => null,
                    'deliveryNote'       => null,
                    'trackingUrl'        => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
                    'locale'             => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('order_shipped', $locale, [
                    'orderNumber' => $order->getOrderNumber()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Shipping notification sent', [
                'order_number'   => $order->getOrderNumber(),
                'email'          => $recipientEmail,
                'tracking_number' => $trackingNumber,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send shipping notification', [
                'order_number' => $order->getOrderNumber(),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie le client qu'un colis individuel a été expédié
     */
    public function sendParcelShippedNotification(Order $order, Parcel $parcel): void
    {
        try {
            $locale = $this->getEmailLocale(null, $order);

            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
            if (!$recipientEmail) {
                return;
            }

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            $totalParcels = $order->getParcels()->count();

            // Calculer les articles restants non encore assignés à un colis expédié
            $allocatedByOrderItemId = [];
            foreach ($order->getParcels() as $p) {
                if ($p->getStatus() === 'shipped') {
                    foreach ($p->getItems() as $parcelItem) {
                        $oi = $parcelItem->getOrderItem();
                        if ($oi) {
                            $oid = $oi->getId()->toRfc4122();
                            $allocatedByOrderItemId[$oid] = ($allocatedByOrderItemId[$oid] ?? 0) + (int) $parcelItem->getQuantity();
                        }
                    }
                }
            }
            $remainingItems = [];
            foreach ($order->getItems() as $orderItem) {
                $oid = $orderItem->getId()->toRfc4122();
                $allocated = (int) ($allocatedByOrderItemId[$oid] ?? 0);
                $remaining = (int) $orderItem->getQuantity() - $allocated;
                if ($remaining > 0) {
                    $remainingItems[] = [
                        'name' => $orderItem->getProduct()?->getName() ?? 'Produit',
                        'quantity' => $remaining,
                    ];
                }
            }

            $html = $this->twig->render(
                $this->getTemplate('emails/order/parcel_shipped', $locale),
                [
                    'order'              => $order,
                    'parcel'             => $parcel,
                    'greeting'           => $greetings[$locale],
                    // Pas de numéro de suivi brut affiché : sans lien vers le site du
                    // transporteur, ce n'est pas exploitable par le client (cf. fix
                    // équivalent sur sendShippingNotification).
                    'trackingNumber'     => null,
                    'parcelNumber'       => $parcel->getParcelNumber(),
                    'totalParcels'       => $totalParcels,
                    'remainingItems'     => $remainingItems,
                    'trackingUrl'        => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
                    'locale'             => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('parcel_shipped', $locale, [
                    'orderNumber'  => $order->getOrderNumber(),
                    'parcelNumber' => (string) $parcel->getParcelNumber(),
                    'totalParcels' => (string) $totalParcels,
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Parcel shipped notification sent', [
                'order_number'   => $order->getOrderNumber(),
                'parcel_number'  => $parcel->getParcelNumber(),
                'email'          => $recipientEmail,
                'tracking_number' => $parcel->getTrackingNumber(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send parcel shipped notification', [
                'order_number'  => $order->getOrderNumber(),
                'parcel_number' => $parcel->getParcelNumber(),
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie le client que sa commande a été livrée
     */
    public function sendDeliveryNotification(Order $order): void
    {
        try {
            $locale = $this->getEmailLocale(null, $order);

            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
            if (!$recipientEmail) {
                return;
            }

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/order/delivered', $locale),
                [
                    'order'    => $order,
                    'greeting' => $greetings[$locale],
                    'orderUrl' => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
                    'locale'   => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('order_delivered', $locale, [
                    'orderNumber' => $order->getOrderNumber()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Delivery notification sent', [
                'order_number' => $order->getOrderNumber(),
                'email'        => $recipientEmail,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send delivery notification', [
                'order_number' => $order->getOrderNumber(),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie l'email de confirmation double opt-in pour la newsletter
     */
    public function sendNewsletterConfirmationEmail(
        NewsletterSubscriber $subscriber,
        string $confirmUrl,
        string $unsubscribeUrl
    ): void {
        try {
            $html = $this->twig->render(
                'emails/newsletter/confirmation.fr.html.twig',
                [
                    'email'          => $subscriber->getEmail(),
                    'confirmUrl'     => $confirmUrl,
                    'unsubscribeUrl' => $unsubscribeUrl,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($subscriber->getEmail())
                ->subject($this->getSubject('newsletter_confirmation', 'fr'))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Newsletter confirmation email sent', [
                'email' => $subscriber->getEmail(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send newsletter confirmation email', [
                'email' => $subscriber->getEmail(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envoie un rappel hebdomadaire à un abonné newsletter qui n'a pas confirmé
     * son inscription (double opt-in). Segment 1 du cron marketing.
     */
    public function sendNewsletterReminderEmail(
        NewsletterSubscriber $subscriber,
        string $confirmUrl,
        string $unsubscribeUrl
    ): void {
        try {
            $html = $this->twig->render(
                'emails/newsletter/reminder.fr.html.twig',
                [
                    'email'          => $subscriber->getEmail(),
                    'confirmUrl'     => $confirmUrl,
                    'unsubscribeUrl' => $unsubscribeUrl,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($subscriber->getEmail())
                ->subject($this->getSubject('newsletter_reminder', 'fr'))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Newsletter reminder email sent', [
                'email' => $subscriber->getEmail(),
                'reminder_count' => $subscriber->getReminderCount() + 1,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send newsletter reminder email', [
                'email' => $subscriber->getEmail(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Notifie le client qu'un remboursement a été effectué
     */
    public function sendRefundNotification(Order $order, float $amount, string $refundId): void
    {
        try {
            $locale = $this->getEmailLocale(null, $order);

            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
            if (!$recipientEmail) {
                return;
            }

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/order/refund', $locale),
                [
                    'order'        => $order,
                    'greeting'     => $greetings[$locale],
                    'refundAmount' => $amount,
                    'refundId'     => $refundId,
                    'orderUrl'     => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
                    'locale'       => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('order_refund', $locale, [
                    'orderNumber' => $order->getOrderNumber()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Refund notification sent', [
                'order_number' => $order->getOrderNumber(),
                'email'        => $recipientEmail,
                'refund_id'    => $refundId,
                'amount'       => $amount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send refund notification', [
                'order_number' => $order->getOrderNumber(),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    public function sendCancellationNotification(Order $order, string $reason, string $adminMessage = ''): void
    {
        try {
            $locale = $this->getEmailLocale(null, $order);

            $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
            if (!$recipientEmail) {
                return;
            }

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/order/cancellation', $locale),
                [
                    'order'        => $order,
                    'greeting'     => $greetings[$locale],
                    'reason'       => $reason,
                    'adminMessage' => $adminMessage,
                    'orderUrl'     => $this->frontBaseUrl . '/order-confirmation/' . $order->getOrderNumber(),
                    'locale'       => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('order_cancellation', $locale, [
                    'orderNumber' => $order->getOrderNumber()
                ]))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Cancellation notification sent', [
                'order_number' => $order->getOrderNumber(),
                'email'        => $recipientEmail,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send cancellation notification', [
                'order_number' => $order->getOrderNumber(),
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie un message libre (objet + texte, pièce jointe optionnelle) rédigé
     * par l'admin depuis le détail d'une commande, au client propriétaire.
     */
    public function sendCustomOrderMessage(
        Order $order,
        string $subject,
        string $message,
        ?string $attachmentPath = null,
        ?string $attachmentFilename = null
    ): void {
        $recipientEmail = $order->getOwner()?->getEmail() ?? $order->getGuestEmail();
        if (!$recipientEmail) {
            throw new \RuntimeException("Aucun email destinataire pour la commande {$order->getOrderNumber()}.");
        }

        try {
            $locale = $this->getEmailLocale(null, $order);

            $firstName = $order->getOwner()?->getFirstName() ?? $order->getGuestFirstName();
            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : "Bonjour",
                'en' => $firstName ? "Hello {$firstName}" : "Hello",
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/order/custom_message', $locale),
                [
                    'order'    => $order,
                    'greeting' => $greetings[$locale],
                    'message'  => $message,
                    'locale'   => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->replyTo($this->fromEmail)
                ->subject($subject)
                ->html($html);

            if ($attachmentPath) {
                $email->attachFromPath($attachmentPath, $attachmentFilename);
            }

            $this->mailer->send($email);

            $this->logger->info('Custom order message sent', [
                'order_number' => $order->getOrderNumber(),
                'email'        => $recipientEmail,
                'subject'      => $subject,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to send custom order message', [
                'order_number' => $order->getOrderNumber(),
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Envoie l'email d'annonce de lancement avec le code promo individuel.

     *
     * @return array{success: bool, error: string|null}
     */
    public function sendLaunchAnnouncement(
        string $recipientEmail,
        string $promoCode,
        int $discountPercent,
        \DateTimeImmutable $expiresAt,
        ?\DateTimeImmutable $launchDate = null,
        string $locale = 'fr',
        bool $isNewsletter = false,
    ): array {
        try {
            $html = $this->twig->render(
                $this->getTemplate('emails/launch/announcement', $locale),
                [
                    'promoCode'          => $promoCode,
                    'discountPercentage' => $discountPercent,
                    'expiresAt'          => $expiresAt,
                    'launchDate'         => $launchDate,
                    'isNewsletter'       => $isNewsletter,
                    'shopUrl'            => $this->frontBaseUrl . '/boutique',
                    'locale'             => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($launchDate
                    ? 'Khamareo ouvre ses portes le ' . $launchDate->format('d/m/Y') . ' !'
                    : 'Khamareo est maintenant ouvert !')
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('Launch announcement sent', [
                'email'      => $recipientEmail,
                'promo_code' => $promoCode,
            ]);

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send launch announcement', [
                'email' => $recipientEmail,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envoie l'email de relance panier abandonné (page admin "Paniers abandonnés").
     * Déclenchement manuel uniquement — pas de cadence automatique.
     * Met à jour les compteurs de rappel séparément pour user connecté / invité.
     */
    public function sendAbandonedCartCheckoutRecovery(Cart $cart): bool
    {
        $owner = $cart->getOwner();
        $recipientEmail = $owner?->getEmail();

        if (!$recipientEmail) {
            $this->logger->warning('⚠️ Relance panier impossible : aucun email disponible', [
                'cart_id' => $cart->getId(),
            ]);
            return false;
        }

        try {
            $locale = $this->getEmailLocale($owner);
            $firstName = $owner->getFirstName();

            $greetings = [
                'fr' => $firstName ? "Bonjour {$firstName}" : 'Bonjour',
                'en' => $firstName ? "Hello {$firstName}" : 'Hello',
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/cart/checkout_issue_recovery', $locale),
                [
                    'cart' => $cart,
                    'greeting' => $greetings[$locale] ?? $greetings['fr'],
                    'shopUrl' => $this->frontBaseUrl . '/boutique',
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('cart_checkout_issue_recovery', $locale))
                ->html($html);

            $this->mailer->send($email);

            $now = new \DateTimeImmutable();
            if ($owner->isGuest()) {
                $cart->setLastGuestReminderAt($now)
                    ->setGuestReminderCount($cart->getGuestReminderCount() + 1);
            } else {
                $cart->setLastReminderAt($now)
                    ->setReminderCount($cart->getReminderCount() + 1);
            }
            $this->em->flush();

            $this->logger->info('✅ Email relance panier envoyé', [
                'cart_id' => $cart->getId(),
                'email' => $recipientEmail,
                'is_guest' => $owner->isGuest(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('❌ Échec envoi relance panier', [
                'cart_id' => $cart->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Segment 4, Email 1 (J+1h) — rappel simple. Contenu différent invité/inscrit.
     */
    public function sendCartReminderStage1(Cart $cart): bool
    {
        $owner = $cart->getOwner();
        $recipientEmail = $owner?->getEmail();

        if (!$recipientEmail) {
            return false;
        }

        try {
            $locale = $this->getEmailLocale($owner);
            $isGuest = $owner->isGuest();
            $firstName = $owner->getFirstName();

            $greetings = [
                'fr' => $firstName && !$isGuest ? "Bonjour {$firstName}" : 'Bonjour',
                'en' => $firstName && !$isGuest ? "Hello {$firstName}" : 'Hello',
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/cart/reminder_stage1', $locale),
                [
                    'cart' => $cart,
                    'greeting' => $greetings[$locale] ?? $greetings['fr'],
                    'isGuest' => $isGuest,
                    'firstName' => $isGuest ? null : $firstName,
                    'checkoutUrl' => $this->frontBaseUrl . '/cart',
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject($isGuest ? 'cart_reminder_stage1_guest' : 'cart_reminder_stage1_user', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('✅ Relance panier étape 1 envoyée', ['cart_id' => $cart->getId(), 'email' => $recipientEmail]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('❌ Échec relance panier étape 1', ['cart_id' => $cart->getId(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Segment 4, Email 2 (J+1 jour) — réassurance (avis clients).
     *
     * @param Review[] $reviews
     */
    public function sendCartReminderStage2(Cart $cart, array $reviews): bool
    {
        $owner = $cart->getOwner();
        $recipientEmail = $owner?->getEmail();

        if (!$recipientEmail) {
            return false;
        }

        try {
            $locale = $this->getEmailLocale($owner);
            $firstName = $owner->getFirstName();

            $greetings = [
                'fr' => $firstName && !$owner->isGuest() ? "Bonjour {$firstName}" : 'Bonjour',
                'en' => $firstName && !$owner->isGuest() ? "Hello {$firstName}" : 'Hello',
            ];

            $html = $this->twig->render(
                $this->getTemplate('emails/cart/reminder_stage2', $locale),
                [
                    'cart' => $cart,
                    'greeting' => $greetings[$locale] ?? $greetings['fr'],
                    'reviews' => $reviews,
                    'checkoutUrl' => $this->frontBaseUrl . '/cart',
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('cart_reminder_stage2', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('✅ Relance panier étape 2 envoyée', ['cart_id' => $cart->getId(), 'email' => $recipientEmail]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('❌ Échec relance panier étape 2', ['cart_id' => $cart->getId(), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Segment 4, Email 3 (J+3 jours) — code -10% valable 48h.
     */
    public function sendCartReminderStage3(Cart $cart, PromoCode $promoCode): bool
    {
        $owner = $cart->getOwner();
        $recipientEmail = $owner?->getEmail();

        if (!$recipientEmail) {
            return false;
        }

        try {
            $locale = $this->getEmailLocale($owner);
            $firstName = $owner->getFirstName();

            $greetings = [
                'fr' => $firstName && !$owner->isGuest() ? "Bonjour {$firstName}" : 'Bonjour',
                'en' => $firstName && !$owner->isGuest() ? "Hello {$firstName}" : 'Hello',
            ];

            $discount = $promoCode->getDiscountPercentage()
                ? $promoCode->getDiscountPercentage() . '%'
                : $promoCode->getDiscountAmount() . '€';

            $html = $this->twig->render(
                $this->getTemplate('emails/cart/reminder_stage3', $locale),
                [
                    'cart' => $cart,
                    'greeting' => $greetings[$locale] ?? $greetings['fr'],
                    'promoCode' => $promoCode,
                    'discount' => $discount,
                    'checkoutUrl' => $this->frontBaseUrl . '/cart',
                    'locale' => $locale,
                ]
            );

            $email = (new Email())
                ->from($this->fromEmail)
                ->to($recipientEmail)
                ->subject($this->getSubject('cart_reminder_stage3', $locale))
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('✅ Relance panier étape 3 envoyée', [
                'cart_id' => $cart->getId(),
                'email' => $recipientEmail,
                'promo_code' => $promoCode->getCode(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('❌ Échec relance panier étape 3', ['cart_id' => $cart->getId(), 'error' => $e->getMessage()]);
            return false;
        }
    }
}
