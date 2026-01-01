<?php

namespace App\Blog\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Blog\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Processor pour BlogPost
 * - Génère le slug automatiquement
 * - Calcule le temps de lecture
 * - Assigne l'auteur
 * - Gère le publishedAt
 */
class BlogPostProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private SluggerInterface $slugger
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof BlogPost) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }


        // ⚠️ CORRECTION : Remplir createdAt AVANT de traiter
        if (!$data->getCreatedAt()) {
            $data->setCreatedAt(new \DateTimeImmutable());
        }

        // 1. Générer le slug si vide ou si le titre a changé
        $this->generateSlug($data);

        // 2. Calculer le temps de lecture
        $this->calculateReadingTime($data);

        // 3. Assigner l'auteur si pas déjà défini
        $this->assignAuthor($data);

        // 4. Gérer la date de publication
        $this->handlePublishedAt($data);

        // 5. Valider et nettoyer le contenu
        $this->sanitizeContent($data);

        // Persister via le processor par défaut
        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * Génère un slug unique basé sur le titre
     */
    private function generateSlug(BlogPost $blogPost): void
    {
        $title = $blogPost->getTitle();
        
        if (!$title) {
            return;
        }

        // Générer un slug de base
        $baseSlug = $this->slugger->slug($title)->lower()->toString();
        
        // Si le slug est déjà défini et identique, ne rien faire
        if ($blogPost->getSlug() === $baseSlug) {
            return;
        }

        // Vérifier l'unicité du slug
        $slug = $baseSlug;
        $counter = 1;

        while ($this->slugExists($slug, $blogPost->getId())) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        $blogPost->setSlug($slug);
    }

    /**
     * Vérifie si un slug existe déjà (pour un autre article)
     */
    private function slugExists(string $slug, mixed $excludeId = null): bool
    {
        $qb = $this->entityManager->getRepository(BlogPost::class)
            ->createQueryBuilder('bp')
            ->where('bp.slug = :slug')
            ->setParameter('slug', $slug);

        if ($excludeId) {
            $qb->andWhere('bp.id != :id')
               ->setParameter('id', $excludeId);
        }

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    /**
     * Calcule le temps de lecture en minutes
     * Basé sur une vitesse moyenne de 200 mots/minute
     */
    private function calculateReadingTime(BlogPost $blogPost): void
    {
        $content = $blogPost->getContent();
        
        if (!$content) {
            $blogPost->setReadingTime(1); // Minimum 1 minute
            return;
        }

        // Supprimer les balises HTML pour compter uniquement le texte
        $textContent = strip_tags($content);
        
        // Compter les mots
        $wordCount = str_word_count($textContent);
        
        // Calculer le temps (200 mots/minute, arrondi au supérieur)
        $readingTime = max(1, (int) ceil($wordCount / 200));
        
        $blogPost->setReadingTime($readingTime);
    }

    /**
     * Assigne l'auteur automatiquement si pas déjà défini
     */
    private function assignAuthor(BlogPost $blogPost): void
    {
        // Si un auteur est déjà assigné, ne rien faire
        if ($blogPost->getAuthor()) {
            return;
        }

        // Récupérer l'utilisateur connecté
        $currentUser = $this->security->getUser();
        
        if ($currentUser) {
            $blogPost->setAuthor($currentUser);
            
            // Si authorName n'est pas défini, utiliser le nom de l'user
            if (!$blogPost->getAuthorName() && method_exists($currentUser, 'getFullName')) {
                $blogPost->setAuthorName($currentUser->getFullName());
            } elseif (!$blogPost->getAuthorName() && method_exists($currentUser, 'getEmail')) {
                $blogPost->setAuthorName($currentUser->getEmail());
            }
        }
    }

    /**
     * Gère la date de publication automatiquement
     */
    private function handlePublishedAt(BlogPost $blogPost): void
    {
        // Si le statut est "published" et qu'il n'y a pas encore de date de publication
        if ($blogPost->getStatus() === 'published' && !$blogPost->getPublishedAt()) {
            $blogPost->setPublishedAt(new \DateTimeImmutable());
        }

        // Si on repasse en draft, on peut optionnellement retirer la date
        // (décommentez si vous voulez ce comportement)
        // if ($blogPost->getStatus() === 'draft') {
        //     $blogPost->setPublishedAt(null);
        // }
    }

    /**
     * Nettoie et valide le contenu HTML
     * Supprime les scripts et autres contenus dangereux
     */
    private function sanitizeContent(BlogPost $blogPost): void
    {
        $content = $blogPost->getContent();
        
        if (!$content) {
            return;
        }

        // Liste des balises autorisées
        $allowedTags = '<p><br><strong><em><u><h1><h2><h3><h4><ul><ol><li><a><img><blockquote><code><pre>';
        
        // Nettoyer le HTML (supprimer scripts, iframes, etc.)
        $cleanContent = strip_tags($content, $allowedTags);
        
        // Supprimer les attributs dangereux (onclick, onerror, etc.)
        $cleanContent = preg_replace('/on\w+\s*=\s*["\'].*?["\']/i', '', $cleanContent);
        
        $blogPost->setContent($cleanContent);

        // Générer automatiquement l'excerpt si vide
        if (!$blogPost->getExcerpt()) {
            $this->generateExcerpt($blogPost);
        }
    }

    /**
     * Génère un excerpt automatique à partir du contenu
     */
    private function generateExcerpt(BlogPost $blogPost): void
    {
        $content = $blogPost->getContent();
        
        if (!$content) {
            return;
        }

        // Supprimer les balises HTML
        $textContent = strip_tags($content);
        
        // Prendre les 160 premiers caractères (optimal pour SEO)
        $excerpt = mb_substr($textContent, 0, 160);
        
        // Couper au dernier mot complet
        $lastSpace = mb_strrpos($excerpt, ' ');
        if ($lastSpace !== false) {
            $excerpt = mb_substr($excerpt, 0, $lastSpace);
        }
        
        // Ajouter "..." si le contenu est plus long
        if (mb_strlen($textContent) > 160) {
            $excerpt .= '...';
        }
        
        $blogPost->setExcerpt($excerpt);
    }
}