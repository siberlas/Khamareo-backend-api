<?php

namespace App\Admin\Controller\Order;

use App\Media\Service\CloudinaryService;
use App\Order\Entity\Order;
use App\Order\Entity\OrderMessage;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/api/admin/orders/{id}/messages', name: 'admin_order_messages_')]
class OrderMessageController extends AbstractController
{
    private const MAX_ATTACHMENT_SIZE = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerService $mailerService,
        private readonly CloudinaryService $cloudinaryService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/admin/orders/{id}/messages
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Order $order): JsonResponse
    {
        $messages = array_map(
            fn (OrderMessage $m) => $this->serialize($m),
            $order->getMessages()->toArray()
        );

        return $this->json($messages);
    }

    /**
     * POST /api/admin/orders/{id}/messages
     *
     * FormData: subject, message, attachment (optionnel)
     */
    #[Route('', name: 'send', methods: ['POST'])]
    public function send(Order $order, Request $request): JsonResponse
    {
        $subject = trim((string) $request->request->get('subject', ''));
        $message = trim((string) $request->request->get('message', ''));

        if ($subject === '' || $message === '') {
            return $this->json(['error' => "L'objet et le message sont requis."], 400);
        }

        /** @var UploadedFile|null $attachment */
        $attachment = $request->files->get('attachment');

        if ($attachment && $attachment->getSize() > self::MAX_ATTACHMENT_SIZE) {
            return $this->json(['error' => 'Pièce jointe trop volumineuse (10 MB max).'], 400);
        }

        $attachmentUrl = null;
        $attachmentFilename = null;

        if ($attachment) {
            $attachmentFilename = $attachment->getClientOriginalName();

            $upload = $this->cloudinaryService->uploadImage($attachment->getPathname(), [
                'asset_folder'  => 'khamareo/order-messages',
                'tags'          => ['order-message'],
                'resource_type' => 'auto',
            ]);

            if (!$upload['success']) {
                return $this->json(['error' => "Échec de l'upload de la pièce jointe : " . ($upload['error'] ?? '')], 500);
            }

            $attachmentUrl = $upload['url'];
        }

        try {
            // Attache directement depuis le fichier temporaire (encore présent à ce
            // stade de la requête) : pas besoin de retélécharger depuis Cloudinary.
            $this->mailerService->sendCustomOrderMessage(
                $order,
                $subject,
                $message,
                $attachment?->getPathname(),
                $attachmentFilename
            );
        } catch (\Exception $e) {
            return $this->json(['error' => "Échec de l'envoi : " . $e->getMessage()], 500);
        }

        $orderMessage = new OrderMessage();
        $orderMessage
            ->setSubject($subject)
            ->setMessage($message)
            ->setAttachmentPath($attachmentUrl)
            ->setAttachmentFilename($attachmentFilename);

        $order->addMessage($orderMessage);

        $this->em->persist($orderMessage);
        $this->em->flush();

        $this->logger->info('Custom order message sent by admin', [
            'order_number' => $order->getOrderNumber(),
            'subject'      => $subject,
        ]);

        return $this->json(['success' => true, 'message' => $this->serialize($orderMessage)], 201);
    }

    private function serialize(OrderMessage $m): array
    {
        return [
            'id'                  => $m->getId(),
            'subject'             => $m->getSubject(),
            'message'             => $m->getMessage(),
            'attachmentUrl'       => $m->getAttachmentPath(),
            'attachmentFilename'  => $m->getAttachmentFilename(),
            'createdAt'           => $m->getCreatedAt()->format('c'),
        ];
    }
}
