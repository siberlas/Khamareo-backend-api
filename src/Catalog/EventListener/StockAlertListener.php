<?php
namespace App\Catalog\EventListener;

use App\Catalog\Entity\Product;
use App\Marketing\Repository\StockAlertRepository;
use App\Shared\Service\MailerService;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StockAlertListener
{
    public function __construct(
        #[Autowire(service: 'App\\Marketing\\Repository\\StockAlertRepository')]
        private StockAlertRepository $stockAlertRepository,
        #[Autowire(service: 'App\\Shared\\Service\\MailerService')]
        private MailerService $mailerService,
    ) {}

    /**
     * Triggered after product update
     */
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Product) {
            return;
        }
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $changes = $uow->getEntityChangeSet($entity);
        if (!isset($changes['stock'])) {
            return;
        }
        [$oldStock, $newStock] = $changes['stock'];
        if ($oldStock === 0 && $newStock > 0) {
            $alerts = $this->stockAlertRepository->findBy([
                'product' => $entity,
                'notified' => false,
            ]);
            foreach ($alerts as $alert) {
                $this->mailerService->sendStockAlertNotification($alert);
                $alert->setNotified(true);
                $alert->setNotifiedAt(new \DateTimeImmutable());
                $em->persist($alert);
            }
            $em->flush();
        }
    }
}
