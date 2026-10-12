<?php

namespace Akyos\UxNativeCliBundle\Live;

use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait NotificationTrait
{
    #[LiveProp]
    public ?string $notificationToken = null;

    #[LiveProp]
    public ?string $notificationError = null;

    #[LiveAction]
    public function receiveNotificationToken(#[LiveArg] string $token): void
    {
        $this->notificationToken = $token;
        $this->notificationError = null;
        $this->onNotificationToken($token);
    }

    #[LiveAction]
    public function failNotificationToken(#[LiveArg] string $error): void
    {
        $this->notificationError = $error;
    }

    protected function onNotificationToken(string $token): void
    {
    }
}
