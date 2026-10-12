<?php

namespace Akyos\UxNativeCliBundle\Live;

use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

trait ScanQRTrait
{
    #[LiveProp]
    public ?string $scannedCode = null;

    #[LiveAction]
    public function receiveScannedCode(#[LiveArg] string $code): void
    {
        $this->scannedCode = $code;
        $this->onQrScanned($code);
    }

    protected function onQrScanned(string $code): void
    {
    }
}
