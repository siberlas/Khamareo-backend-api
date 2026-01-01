<?

namespace App\Cart\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cart\Entity\Cart;
use Symfony\Bundle\SecurityBundle\Security;
use Doctrine\ORM\EntityManagerInterface;
use App\Cart\Repository\CartRepository;

class CartProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em,
        private CartRepository $cartRepository

    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        if (!$data instanceof Cart) {
            return $data;
        }

        $user = $this->security->getUser();

        // 🔹 Cas 1 : utilisateur connecté
        if ($user) {
            $existingCart = $this->cartRepository->findOneBy([
                'owner' => $user,
                'isActive' => true,
            ]);

            if ($existingCart) {
                return $existingCart;
            }

            $data->setOwner($user);
            $data->setIsActive(true);
        }

        // 🔹 Cas 2 : invité
        if (!$user) {
            // Vérifie s'il existe déjà un panier avec ce token (si transmis depuis le front)
            $guestToken = $data->getGuestToken() ?? bin2hex(random_bytes(16));
            $existingGuestCart = $this->cartRepository->findOneBy([
                'guestToken' => $guestToken,
                'isActive' => true,
            ]);

            if ($existingGuestCart) {
                return $existingGuestCart;
            }

            $data->setGuestToken($guestToken);
            $data->setIsActive(true);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
