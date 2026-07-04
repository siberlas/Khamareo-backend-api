<?php

namespace App\Admin\Controller\Contact;

use App\Contact\Entity\ContactConversation;
use App\Contact\Entity\ContactMessage;
use App\Contact\Repository\ContactConversationRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[Route('/api/admin/contact-conversations', name: 'admin_contact_conversations_')]
#[IsGranted('ROLE_ADMIN')]
class AdminContactConversationController extends AbstractController
{
    public function __construct(
        private ContactConversationRepository $repo,
        private EntityManagerInterface $em,
        private MailerService $mailer,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = 20;

        $conversations = $this->repo->findAllOrderedByLastMessage($limit, ($page - 1) * $limit);
        $total         = $this->repo->countAll();

        return $this->json([
            'conversations' => array_map(fn(ContactConversation $c) => $this->serializeList($c), $conversations),
            'pagination'    => [
                'page'        => $page,
                'totalItems'  => $total,
                'totalPages'  => (int) ceil($total / $limit),
                'itemsPerPage' => $limit,
            ],
        ]);
    }

    #[Route('/unread-count', name: 'unread_count', methods: ['GET'])]
    public function unreadCount(): JsonResponse
    {
        return $this->json(['count' => $this->repo->countNew()]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $conversation = $this->repo->find($id);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation introuvable'], 404);
        }

        $conversation->setHasNew(false);
        $conversation->setIsRead(true);
        $this->em->flush();

        return $this->json($this->serializeDetail($conversation));
    }

    #[Route('/{id}/reply', name: 'reply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reply(int $id, Request $request): JsonResponse
    {
        $conversation = $this->repo->find($id);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation introuvable'], 404);
        }

        $data      = json_decode($request->getContent(), true) ?? [];
        $replyText = trim($data['reply'] ?? '');

        if (empty($replyText)) {
            return $this->json(['error' => 'Le texte de réponse est requis.'], 400);
        }

        try {
            $this->mailer->sendContactReply($conversation, $replyText);
        } catch (\Exception $e) {
            return $this->json(['error' => "Échec de l'envoi : " . $e->getMessage()], 500);
        }

        // Créer le message de réponse admin dans le thread
        $replyMessage = new ContactMessage();
        $replyMessage->setName('Équipe Khamareo');
        $replyMessage->setEmail($conversation->getEmail());
        $replyMessage->setSubject('Re: ' . $conversation->getSubject());
        $replyMessage->setMessage($replyText);
        $replyMessage->setIsAdminReply(true);

        $conversation->addMessage($replyMessage);
        $conversation->setLastMessageAt(new \DateTimeImmutable());
        $conversation->setIsRead(true);

        $this->em->persist($replyMessage);
        $this->em->flush();

        return $this->json(['success' => true, 'conversation' => $this->serializeDetail($conversation)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $conversation = $this->repo->find($id);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation introuvable'], 404);
        }

        $this->em->remove($conversation);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    private function serializeList(ContactConversation $c): array
    {
        return [
            'id'            => $c->getId(),
            'email'         => $c->getEmail(),
            'name'          => $c->getName(),
            'subject'       => $c->getSubject(),
            'lastMessageAt' => $c->getLastMessageAt()->format('c'),
            'hasNew'        => $c->isHasNew(),
            'isRead'        => $c->isRead(),
            'messageCount'  => $c->getMessageCount(),
        ];
    }

    private function serializeDetail(ContactConversation $c): array
    {
        $data             = $this->serializeList($c);
        $data['adminNotes'] = $c->getAdminNotes();
        $data['messages']   = $c->getMessages()->map(fn(ContactMessage $m) => [
            'id'           => $m->getId(),
            'message'      => $m->getMessage(),
            'isAdminReply' => $m->isAdminReply(),
            'createdAt'    => $m->getCreatedAt()?->format('c'),
            'phone'        => $m->getPhone(),
            'orderNumber'  => $m->getOrderNumber(),
        ])->toArray();

        return $data;
    }
}
