<?php

namespace App\User\Controller;

use App\User\Entity\User;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\GoogleUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class GoogleAuthController extends AbstractController
{
    private const REFRESH_TTL = 2_592_000; // 30 jours (identique à la config gesdinet)

    public function __construct(
        private readonly ClientRegistry                $clientRegistry,
        private readonly UserRepository               $userRepository,
        private readonly EntityManagerInterface       $em,
        private readonly JWTTokenManagerInterface     $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly string                       $frontBaseUrl,
    ) {}

    /**
     * GET /api/auth/google
     */
    #[Route('/api/auth/google', name: 'auth_google_redirect', methods: ['GET'])]
    public function redirectToGoogle(): RedirectResponse
    {
        return $this->clientRegistry
            ->getClient('google')
            ->redirect(['email', 'profile'], []);
    }

    /**
     * GET /api/auth/google/callback
     */
    #[Route('/api/auth/google/callback', name: 'auth_google_callback', methods: ['GET'])]
    public function callback(): RedirectResponse
    {
        $errorRedirect = $this->frontBaseUrl . '/login?oauth_error=1';

        try {
            $client = $this->clientRegistry->getClient('google');
            /** @var GoogleUser $googleUser */
            $googleUser = $client->fetchUser();
            $googleId   = $googleUser->getId();
            $email      = $googleUser->getEmail();
            $firstName  = $googleUser->getFirstName() ?? '';
            $lastName   = $googleUser->getLastName() ?? '';
        } catch (\Exception) {
            return new RedirectResponse($errorRedirect);
        }

        if (!$email) {
            return new RedirectResponse($errorRedirect . '&reason=no_email');
        }

        $user = $this->userRepository->findOneBy(['googleId' => $googleId])
             ?? $this->userRepository->findOneBy(['email' => $email]);

        if ($user) {
            if ($user->getGoogleId() === null) {
                $user->setGoogleId($googleId);
                $this->em->flush();
            }
        } else {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstName($firstName ?: 'Utilisateur');
            $user->setLastName($lastName ?: '');
            $user->setGoogleId($googleId);
            $user->setRoles(['ROLE_USER']);
            $user->setAcceptTerms(true);
            $user->setIsVerified(true);
            $user->setPassword('');

            $this->em->persist($user);
            $this->em->flush();
        }

        // JWT access token
        $accessToken = $this->jwtManager->create($user);

        // Refresh token → posé en cookie httpOnly (même config que le login classique)
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, self::REFRESH_TTL);

        $response = new RedirectResponse(
            $this->frontBaseUrl . '/auth/google/callback?token=' . urlencode($accessToken)
        );

        $response->headers->setCookie(
            Cookie::create('refresh_token')
                ->withValue($refreshToken->getRefreshToken())
                ->withExpires(time() + self::REFRESH_TTL)
                ->withPath('/')
                ->withSecure(true)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_NONE)
        );

        return $response;
    }
}
