<?php

namespace App\Security;

use Rocket\Core\Security\ApplicationUser;
use Rocket\Core\Security\ScopeGuardListener;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * rocket-core only lets an application that does not impersonate anyone call GET /api/me. Cast lets such an
 * application READ its screens (GET /api/screens, /api/screens/{id}), so a dashboard (e.g. Rocket Host) can show
 * whether the screen of a place is online; every write and every other endpoint stays guarded by rocket-core.
 */
#[AsDecorator(ScopeGuardListener::class)]
final class CastScopeGuardListener
{
    private const APPLICATION_READ = '#^/api/screens(/[^/]+)?$#';

    public function __construct(
        #[AutowireDecorated] private readonly ScopeGuardListener $inner,
        private readonly Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->isMethod('GET') && $this->security->getUser() instanceof ApplicationUser
            && preg_match(self::APPLICATION_READ, $request->getPathInfo())) {
            return;
        }

        ($this->inner)($event);
    }
}
