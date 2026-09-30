<?php

namespace Akyos\UxNativeCliBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\UX\Native\EventListener\NativeListener;

#[AsEventListener(event: RequestEvent::class, priority: -10)]
final class NativeDetectionEnhancer
{
    private const NATIVE_COOKIE = 'hotwire_native';

    private const ANDROID_PACKAGE_HEADER = 'X-Requested-With';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->get(NativeListener::NATIVE_ATTRIBUTE) === true) {
            return;
        }

        $packageHeader = (string) $request->headers->get(self::ANDROID_PACKAGE_HEADER, '');
        $cookie = (string) $request->cookies->get(self::NATIVE_COOKIE, '');
        $userAgent = (string) $request->headers->get('User-Agent', '');

        $isAndroidWebView = $packageHeader !== ''
            && str_contains($userAgent, 'wv)')
            && preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $packageHeader) === 1;

        if ($isAndroidWebView || $cookie === '1') {
            $request->attributes->set(NativeListener::NATIVE_ATTRIBUTE, true);
        }
    }
}
