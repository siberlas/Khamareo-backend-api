<?php

namespace App\Security\RateLimit;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class ApiRateLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.login_limiter')]
        private RateLimiterFactory $loginLimiter,
        #[Autowire(service: 'limiter.registration_limiter')]
        private RateLimiterFactory $registrationLimiter,
        #[Autowire(service: 'limiter.password_reset_limiter')]
        private RateLimiterFactory $passwordResetLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 10]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()) {
            return;
        }

        if ($request->getMethod() !== Request::METHOD_POST) {
            return;
        }

        $path = $request->getPathInfo();

        if ($path === '/api/users' || $path === '/api/users/convert-guest') {
            $this->consume($this->registrationLimiter, $request, 'register');
            return;
        }

        if ($path === '/api/auth') {
            $this->consume($this->loginLimiter, $request, 'login');
            return;
        }

        if ($path === '/api/forgot-password' || $path === '/api/reset-password') {
            $this->consume($this->passwordResetLimiter, $request, 'password_reset');
        }
    }

    private function consume(RateLimiterFactory $limiter, Request $request, string $context): void
    {
        $payload = [];
        $content = $request->getContent();

        if ($content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $email = isset($payload['email']) && is_string($payload['email']) ? strtolower(trim($payload['email'])) : 'unknown';
        $ip = $request->getClientIp() ?? 'unknown';

        $key = sprintf('%s:%s:%s', $context, $ip, $email);
        $limit = $limiter->create($key)->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()?->getTimestamp();
            $headers = [];
            if ($retryAfter) {
                $headers['Retry-After'] = (string) max(0, $retryAfter - time());
            }
            throw new TooManyRequestsHttpException(null, 'Too many requests', null, 0, $headers);
        }
    }
}