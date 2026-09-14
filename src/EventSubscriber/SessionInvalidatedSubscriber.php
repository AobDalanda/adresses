<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Exception\SessionInvalidatedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SessionInvalidatedSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof SessionInvalidatedException) {
            return;
        }

        $event->setResponse(new JsonResponse(['message' => 'SESSION_INVALIDATED'], 401));
    }
}
