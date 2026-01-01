<?
namespace App\User\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;

class CurrentUserProvider implements ProviderInterface
{
    public function __construct(private Security $security) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $user = $this->security->getUser();

        if (!$user) {
            throw new \LogicException('Aucun utilisateur connecté');
        }

        $context['uri_variables'] = ['id' => (string) $user->getId()];

        return $user;
    }
}
