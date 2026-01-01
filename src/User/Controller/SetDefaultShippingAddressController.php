<?php

namespace App\User\Controller;

use App\User\Entity\Address;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

class SetDefaultShippingAddressController extends AbstractController
{
    #[Route('/api/shipping_addresses/{id}/set-default', methods: ['PATCH'])]
    public function __invoke(Address $address, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user || $address->getOwner()?->getId() !== $user->getId()) {
            return $this->json(['error' => 'Unauthorized'], 403);
        }

        $address->setIsDefault(true);
        $em->flush();

        return $this->json(['success' => true]);
    }
}
