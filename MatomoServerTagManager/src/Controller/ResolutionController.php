<?php

namespace SwClp\MatomoServerTagManager\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ResolutionController extends AbstractController
{
    #[Route(path: '/matomo-save-resolution', name: 'frontend.matomo.save.resolution', methods: ['POST'], defaults: ['XmlHttpRequest' => true])]
    public function saveResolution(Request $request): JsonResponse
    {
        $width = $request->request->get('width');
        $height = $request->request->get('height');

        $session = $request->getSession();
        $session->set('screen_width', $width);
        $session->set('screen_height', $height);

        return new JsonResponse(['success' => true]);
    }
}
