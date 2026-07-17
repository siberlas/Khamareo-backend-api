<?php

namespace App\Marketing\Controller;

use App\Marketing\Repository\NewsletterSubscriberRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AsController]
class NewsletterAdminController extends AbstractController
{
    public function __construct(
        private NewsletterSubscriberRepository $repository,
        private EntityManagerInterface         $em,
        private MailerService                  $mailerService,
        private string                         $backendUrl,
    ) {}

    /**
     * GET /api/admin/newsletter/subscribers
     * ?page=1&itemsPerPage=20&status=all|confirmed|pending&search=email
     */
    #[Route('/api/admin/newsletter/subscribers', name: 'admin_newsletter_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page         = max(1, $request->query->getInt('page', 1));
        $itemsPerPage = min(100, max(1, $request->query->getInt('itemsPerPage', 20)));
        $status       = $request->query->get('status', 'all'); // all | confirmed | pending
        $search       = trim((string) $request->query->get('search', ''));

        $qb = $this->repository->createQueryBuilder('n');

        if ($search !== '') {
            $qb->andWhere('n.email LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($status === 'confirmed') {
            $qb->andWhere('n.confirmedAt IS NOT NULL');
        } elseif ($status === 'pending') {
            $qb->andWhere('n.confirmedAt IS NULL');
        }

        // Totaux pour les badges (sans filtre statut)
        $countBase = $this->repository->createQueryBuilder('n');
        if ($search !== '') {
            $countBase->andWhere('n.email LIKE :search')->setParameter('search', '%' . $search . '%');
        }
        $totalAll       = (int) (clone $countBase)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
        $totalConfirmed = (int) (clone $countBase)->select('COUNT(n.id)')->andWhere('n.confirmedAt IS NOT NULL')->getQuery()->getSingleScalarResult();
        $totalPending   = $totalAll - $totalConfirmed;

        $totalItems = (int) (clone $qb)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($totalItems / $itemsPerPage));

        $subscribers = $qb
            ->orderBy('n.subscribedAt', 'DESC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();

        $data = array_map(fn($s) => [
            'id'                  => (string) $s->getId(),
            'email'               => $s->getEmail(),
            'subscribedAt'        => $s->getSubscribedAt()?->format(\DateTimeInterface::ATOM),
            'confirmedAt'         => $s->getConfirmedAt()?->format(\DateTimeInterface::ATOM),
            'confirmationSentAt'  => $s->getConfirmationSentAt()?->format(\DateTimeInterface::ATOM),
            'isConfirmed'         => $s->isConfirmed(),
        ], $subscribers);

        return $this->json([
            'subscribers' => $data,
            'pagination'  => [
                'currentPage'    => $page,
                'itemsPerPage'   => $itemsPerPage,
                'totalItems'     => $totalItems,
                'totalPages'     => $totalPages,
                'totalAll'       => $totalAll,
                'totalConfirmed' => $totalConfirmed,
                'totalPending'   => $totalPending,
            ],
        ]);
    }

    /**
     * GET /api/admin/newsletter/export
     * Export CSV de tous les abonnés (confirmés + en attente).
     */
    #[Route('/api/admin/newsletter/export', name: 'admin_newsletter_export', methods: ['GET'])]
    public function export(): StreamedResponse
    {
        $subscribers = $this->repository->createQueryBuilder('n')
            ->orderBy('n.subscribedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $response = new StreamedResponse(function () use ($subscribers) {
            $handle = fopen('php://output', 'w');
            // BOM UTF-8 pour qu'Excel affiche correctement les accents
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Email', 'Statut', "Date d'inscription", 'Date de confirmation'], ';');

            foreach ($subscribers as $s) {
                fputcsv($handle, [
                    $s->getEmail(),
                    $s->isConfirmed() ? 'Confirmé' : 'En attente',
                    $s->getSubscribedAt()?->format('Y-m-d H:i:s'),
                    $s->getConfirmedAt()?->format('Y-m-d H:i:s') ?? '',
                ], ';');
            }

            fclose($handle);
        });

        $filename = 'newsletter-abonnes-' . (new \DateTimeImmutable())->format('Y-m-d') . '.csv';

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    /**
     * POST /api/admin/newsletter/resend/{id}
     */
    #[Route('/api/admin/newsletter/resend/{id}', name: 'admin_newsletter_resend', methods: ['POST'])]
    public function resend(string $id): JsonResponse
    {
        $subscriber = $this->repository->find($id);

        if (!$subscriber) {
            return $this->json(['error' => 'not_found', 'message' => 'Abonné introuvable.'], 404);
        }

        if ($subscriber->isConfirmed()) {
            return $this->json(['error' => 'already_confirmed', 'message' => 'Cet abonné est déjà confirmé.'], 409);
        }

        $token = bin2hex(random_bytes(32));
        $subscriber->setConfirmationToken($token);
        $subscriber->setConfirmationSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $confirmUrl     = $this->backendUrl . '/api/newsletter/confirm?token=' . $token;
        $unsubscribeUrl = $this->backendUrl . '/api/newsletter/unsubscribe?token=' . $token;
        $this->mailerService->sendNewsletterConfirmationEmail($subscriber, $confirmUrl, $unsubscribeUrl);

        return $this->json([
            'message'            => 'Email de confirmation renvoyé à ' . $subscriber->getEmail(),
            'confirmationSentAt' => $subscriber->getConfirmationSentAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * DELETE /api/admin/newsletter/subscribers/{id}
     */
    #[Route('/api/admin/newsletter/subscribers/{id}', name: 'admin_newsletter_delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $subscriber = $this->repository->find($id);

        if (!$subscriber) {
            return $this->json(['error' => 'not_found', 'message' => 'Abonné introuvable.'], 404);
        }

        $this->em->remove($subscriber);
        $this->em->flush();

        return $this->json(['message' => 'Abonné supprimé.']);
    }
}
