<?php

namespace App\Admin\Controller\Contact;

use App\Contact\Entity\ContactMessage;
use App\Contact\Repository\ContactMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[Route('/api/admin/contact-messages', name: 'admin_contact_messages_')]
#[IsGranted('ROLE_ADMIN')]
class AdminContactMessageController extends AbstractController
{
    public function __construct(
        private ContactMessageRepository $repo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(ContactMessage::class, 'm')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $messages = $qb->getQuery()->getResult();

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ContactMessage::class, 'm')
            ->getQuery()->getSingleScalarResult();

        return $this->json([
            'messages' => array_map(fn(ContactMessage $m) => $this->serialize($m), $messages),
            'pagination' => [
                'page' => $page,
                'totalItems' => $total,
                'totalPages' => (int) ceil($total / $limit),
                'itemsPerPage' => $limit,
            ],
        ]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ContactMessage::class, 'm')
            ->where('m.isRead = false')
            ->getQuery()->getSingleScalarResult();

        return $this->json(['count' => $count]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $message = $this->repo->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message introuvable'], 404);
        }

        if (!$message->isRead()) {
            $message->setIsRead(true);
            $this->em->flush();
        }

        return $this->json($this->serialize($message, true));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $message = $this->repo->find($id);
        if (!$message) {
            return $this->json(['error' => 'Message introuvable'], 404);
        }

        $this->em->remove($message);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    private function serialize(ContactMessage $m, bool $full = false): array
    {
        $data = [
            'id'        => $m->getId(),
            'name'      => $m->getName(),
            'email'     => $m->getEmail(),
            'subject'   => $m->getSubject(),
            'isRead'    => $m->isRead(),
            'createdAt' => $m->getCreatedAt()?->format('c'),
        ];

        if ($full) {
            $data['phone']       = $m->getPhone();
            $data['message']     = $m->getMessage();
            $data['orderNumber'] = $m->getOrderNumber();
            $data['adminNotes']  = $m->getAdminNotes();
        }

        return $data;
    }
}
