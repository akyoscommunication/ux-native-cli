<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NativeOfflineController extends AbstractController
{
    #[Route('/offline', name: 'native_offline', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('native/offline.html.twig');
    }
}
