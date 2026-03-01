<?php

namespace App\User\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\User\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RGPD Art. 17 – Droit à l'effacement (« droit à l'oubli »).
 * Anonymise le compte utilisateur tout en conservant les données comptables (obligation légale 10 ans).
 */
#[IsGranted('ROLE_USER')]
#[AsController]
class UserAccountDeletionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    #[Route('/api/users/me/account', name: 'user_account_deletion', methods: ['DELETE'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $userId = (string) $user->getId();

        // Anonymisation RGPD : on préserve l'enregistrement pour les obligations légales
        // (comptabilité, commandes), mais toutes les données personnelles identifiantes sont effacées.
        $user->setEmail("deleted_{$userId}@deleted.local");
        $user->setFirstName('Compte');
        $user->setLastName('Supprimé');
        $user->setPhone(null);
        $user->setPassword(bin2hex(random_bytes(32))); // mot de passe irrécupérable
        $user->setPlainPassword(null);
        $user->setNewsletter(false); // déclenche aussi newsletterConsentAt = null
        $user->setRoles(['ROLE_DELETED']);
        $user->setIsVerified(false);
        $user->setConfirmationToken(null);
        $user->setResetPasswordToken(null);
        $user->setStripeCustomerId(null);

        $this->em->flush();

        return new JsonResponse(
            ['message' => 'Votre compte a été anonymisé conformément au RGPD (Art. 17).'],
            200
        );
    }
}
