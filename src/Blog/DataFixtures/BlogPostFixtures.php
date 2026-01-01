<?php
// src/DataFixtures/BlogPostFixtures.php

namespace App\Blog\DataFixtures;

use App\Blog\Entity\BlogPost;
use App\User\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Faker\Factory;

class BlogPostFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $faker = Factory::create('fr_FR');

        $users = $manager->getRepository(User::class)->findAll();

        foreach ($users as $user) {
            for ($i = 0; $i < 3; $i++) {
                $title = $faker->sentence(6);
                
                // Générer un slug à partir du titre
                $slug = $this->generateSlug($title, $faker);

                $post = new BlogPost();
                $post->setTitle($title)
                     ->setSlug($slug)  // ← Ajout du slug
                     ->setContent($faker->paragraphs(4, true))
                     ->setAuthor($user);

                $manager->persist($post);
            }
        }

        $manager->flush();
    }

    /**
     * Générer un slug unique à partir du titre
     */
    private function generateSlug(string $title, $faker): string
    {
        // Nettoyer le titre et le convertir en slug
        $slug = strtolower(trim($title));
        
        // Remplacer les espaces et caractères spéciaux par des tirets
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        
        // Supprimer les tirets multiples
        $slug = preg_replace('/-+/', '-', $slug);
        
        // Supprimer les tirets en début/fin
        $slug = trim($slug, '-');
        
        // Ajouter un nombre aléatoire pour garantir l'unicité
        $slug .= '-' . $faker->unique()->numberBetween(1000, 9999);
        
        return $slug;
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }
}