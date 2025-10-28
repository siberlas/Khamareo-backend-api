<?
namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\CartItem;
use App\Entity\Cart;

final class CartItemProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = [])
    {
        /** @var CartItem $data */
        $cart = $data->getCart();
        $product = $data->getProduct();

        // Vérifier si le produit existe déjà dans le panier
        $existingItem = $this->em->getRepository(CartItem::class)
            ->findOneBy(['cart' => $cart, 'product' => $product]);

        if ($existingItem) {
            // Produit déjà présent → on incrémente la quantité
            $existingItem->setQuantity($existingItem->getQuantity() + $data->getQuantity());
            $this->em->persist($existingItem);
            $this->em->flush();

            return $existingItem;
        }

        // Produit nouveau → comportement normal
        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
