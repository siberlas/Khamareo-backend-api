<?php

namespace App\Shared\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Shared\Entity\ReturnRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enregistre une demande de rétractation (Art. L221-18 Code de la consommation)
 * et envoie les emails de confirmation au client et de notification à l'équipe.
 */

#[AsController]
class RetractationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private MailerInterface $mailer,
        private RateLimiterFactory $retractationLimiter,
    ) {}

    #[Route('/api/public/retractation', name: 'public_retractation', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $limiter = $this->retractationLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives. Réessayez dans une heure.'], 429);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $firstName   = trim($data['firstName']   ?? '');
        $lastName    = trim($data['lastName']    ?? '');
        $email       = trim($data['email']       ?? '');
        $orderNumber = trim($data['orderNumber'] ?? '');
        $reason      = trim($data['reason']      ?? '');

        if (!$firstName || !$lastName || !$email || !$orderNumber) {
            return new JsonResponse(['error' => 'Champs obligatoires manquants.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'Adresse email invalide.'], 400);
        }

        $returnRequest = (new ReturnRequest())
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail($email)
            ->setOrderNumber($orderNumber ?: null)
            ->setReason($reason ?: null)
            ->setIpAddress($this->pseudonymiseIp($request->getClientIp()));

        $this->em->persist($returnRequest);
        $this->em->flush();

        // ── Email de confirmation au client ──────────────────────────────
        try {
            $this->mailer->send(
                (new Email())
                    ->from('noreply@khamareo.com')
                    ->to($email)
                    ->subject('Confirmation de votre demande de rétractation – Khamareo')
                    ->html(sprintf(
                        '<p>Bonjour %s %s,</p>
                        <p>Nous avons bien reçu votre demande de rétractation concernant la commande
                        <strong>%s</strong>.</p>
                        <p>Notre équipe la traitera dans les meilleurs délais (sous 14 jours ouvrés).
                        Vous serez contacté(e) à cette adresse pour la suite de la procédure.</p>
                        <p>Pour toute question :
                        <a href="mailto:retractation@khamareo.com">retractation@khamareo.com</a></p>
                        <p>Cordialement,<br>L\'équipe Khamareo</p>',
                        htmlspecialchars($firstName),
                        htmlspecialchars($lastName),
                        htmlspecialchars($orderNumber)
                    ))
            );
        } catch (\Throwable) {
            // Ne pas bloquer la réponse si l'envoi échoue
        }

        // ── Notification admin ───────────────────────────────────────────
        try {
            $this->mailer->send(
                (new Email())
                    ->from('noreply@khamareo.com')
                    ->to('retractation@khamareo.com')
                    ->subject("[Rétractation] {$firstName} {$lastName} – Commande {$orderNumber}")
                    ->html(sprintf(
                        '<p><strong>Nouvelle demande de rétractation</strong></p>
                        <p><strong>Client :</strong> %s %s (<a href="mailto:%s">%s</a>)</p>
                        <p><strong>Commande :</strong> %s</p>
                        <p><strong>Motif :</strong> %s</p>
                        <p><strong>Date :</strong> %s</p>',
                        htmlspecialchars($firstName),
                        htmlspecialchars($lastName),
                        htmlspecialchars($email),
                        htmlspecialchars($email),
                        htmlspecialchars($orderNumber),
                        htmlspecialchars($reason ?: 'Non précisé'),
                        (new \DateTimeImmutable())->format('d/m/Y H:i')
                    ))
            );
        } catch (\Throwable) {
            // Ne pas bloquer la réponse
        }

        return new JsonResponse([
            'message' => 'Votre demande de rétractation a été enregistrée. Un email de confirmation vous a été envoyé.',
        ], 201);
    }

    /** Pseudonymise l'IP (RGPD) : IPv4 → dernier octet à 0, IPv6 → /48. */
    private function pseudonymiseIp(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);
            return implode(':', array_slice($parts, 0, 3)) . ':0:0:0:0:0';
        }
        $parts = explode('.', $ip);
        $parts[3] = '0';
        return implode('.', $parts);
    }
}
