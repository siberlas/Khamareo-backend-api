<?
namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<Order>
 */
final class OrderProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $user = $this->security->getUser();

        // Admin → toutes les commandes
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return $this->entityManager->getRepository(Order::class)->findAll();
        }

        // Utilisateur connecté → uniquement ses commandes
        return $this->entityManager->getRepository(Order::class)
            ->findBy(['owner' => $user], ['createdAt' => 'DESC']);
    }
}
