<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        // Admin user
        $admin = new User();
        $admin->setEmail('admin@demo.com')
              ->setRoles(['ROLE_ADMIN'])
              ->setFirstName('Admin User')
              ->setLastName('Admin User')
              ->setPhone('0600000000')
              ->setAddress('1 rue de Paris, 75000 Paris');

        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'password');
        $admin->setPassword($hashedPassword);

        $manager->persist($admin);

        // Regular user
        $user = new User();
        $user->setEmail('user@demo.com')
             ->setRoles(['ROLE_USER'])
             ->setFirstName('Client Test')
             ->setLastName('Client Test')
             ->setPhone('0700000000')
             ->setAddress('10 avenue de Lyon, 69000 Lyon');

        $hashedPassword = $this->passwordHasher->hashPassword($user, 'password');
        $user->setPassword($hashedPassword);

        $manager->persist($user);

        $manager->flush();
    }
}
