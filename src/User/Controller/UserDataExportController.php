<?php

namespace App\User\Controller;
use Symfony\Component\HttpKernel\Attribute\AsController;

use App\Shared\Repository\ConsentLogRepository;
use App\User\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * RGPD Art. 20 – Droit à la portabilité des données.
 * Exporte toutes les données personnelles de l'utilisateur connecté.
 */
#[IsGranted('ROLE_USER')]
#[AsController]
class UserDataExportController extends AbstractController
{
    public function __construct(
        private ConsentLogRepository $consentLogRepository,
    ) {}

    #[Route('/api/users/me/export', name: 'user_data_export', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = [
            'exportDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'profile' => [
                'id'                  => (string) $user->getId(),
                'email'               => $user->getEmail(),
                'firstName'           => $user->getFirstName(),
                'lastName'            => $user->getLastName(),
                'phone'               => $user->getPhone(),
                'createdAt'           => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'newsletter'          => $user->isNewsletter(),
                'newsletterConsentAt' => $user->getNewsletterConsentAt()?->format(\DateTimeInterface::ATOM),
                'acceptTermsAt'       => $user->getAcceptTermsAt()?->format(\DateTimeInterface::ATOM),
                'preferredLanguage'   => $user->getPreferredLanguage(),
            ],
            'addresses'   => [],
            'consentLogs' => [],
        ];

        foreach ($user->getAddresses() as $address) {
            if ($address->isDeleted()) {
                continue;
            }
            $data['addresses'][] = [
                'label'         => $address->getLabel(),
                'civility'      => $address->getCivility(),
                'firstName'     => $address->getFirstName(),
                'lastName'      => $address->getLastName(),
                'companyName'   => $address->getCompanyName(),
                'streetAddress' => $address->getStreetAddress(),
                'postalCode'    => $address->getPostalCode(),
                'city'          => $address->getCity(),
                'country'       => $address->getCountry(),
                'phone'         => $address->getPhone(),
                'addressKind'   => $address->getAddressKind(),
                'isDefault'     => $address->isDefault(),
            ];
        }

        $logs = $this->consentLogRepository->findBy(['userId' => $user->getId()]);
        foreach ($logs as $log) {
            $data['consentLogs'][] = [
                'type'      => $log->getType(),
                'choice'    => $log->getChoice(),
                'version'   => $log->getVersion(),
                'createdAt' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse($data, 200, [
            'Content-Disposition' => 'attachment; filename="mes-donnees-khamareo.json"',
        ]);
    }
}
