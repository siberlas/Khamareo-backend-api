<?php

namespace App\User\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use App\User\Entity\Address;
use App\User\Entity\User;

final class AddressOwnerProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $em
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        /** @var Address $data */
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \RuntimeException("Aucun utilisateur connecté.");
        }

        // 🔗 Associer au propriétaire
        $data->setOwner($user);

        // =============================
        // 🔧 Nettoyage selon addressKind
        // =============================

        switch ($data->getAddressKind()) {

            case 'business':
                // adresse PRO => pas de civilité / nom / prénom
                $data->setCivility(null);
                $data->setFirstName(null);
                $data->setLastName(null);
                break;

            case 'personal':
                // adresse PERSONNELLE => pas de companyName
                $data->setCompanyName(null);
                break;

            case 'relay':
                // adresse RELAIS => aucun champ personnel
                $data->setCivility(null);
                $data->setFirstName(null);
                $data->setLastName(null);
                $data->setCompanyName(null);
                // isRelayPoint doit être true (validé par l'entité)
                break;
        }

        $kind = $data->getAddressKind();

        // ================================================
        // 📌 Récupérer les autres adresses actives du même kind
        // ================================================
        $existingActive = array_filter(
            $user->getAddresses()->toArray(),
            fn(Address $a) =>
                !$a->isDeleted()
                && $a->getAddressKind() === $kind
        );

        // ================================================
        // ⭐ Première adresse de ce type = par défaut
        // ================================================
        if (empty($existingActive) && $kind !== 'relay') {
            $data->setIsDefault(true);
        }

        // ================================================
        // ⭐ Si utilisateur force "isDefault = true"
        //    alors retirer aux autres du même kind
        // ================================================
        if ($data->isDefault() && $kind !== 'relay') {
            foreach ($existingActive as $address) {
                if ($address !== $data) {
                    $address->setIsDefault(false);
                }
            }
        }

        // ❌ Une adresse relais ne peut jamais être par défaut
        if ($kind === 'relay') {
            $data->setIsDefault(false);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
