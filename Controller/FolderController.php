<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FolderLibrary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FolderController extends AjaxController
{
    public function listAction(Config $config, FolderLibrary $folders): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $tree = $folders->tree($this->translator->trans(Translation::HOME));

        return $this->sendJsonResponse(['folder' => $tree->toArray()]);
    }

    public function createAction(Request $request, Config $config, FolderLibrary $folders): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $payload = json_decode($request->getContent(), true);
        $parent  = is_array($payload) ? ($payload['parent'] ?? '') : $request->request->get('parent', '');
        $name    = is_array($payload) ? ($payload['name'] ?? null) : $request->request->get('name');
        if (!is_string($name) || '' === trim($name)) {
            return $this->sendJsonResponse(
                ['error' => $this->translator->trans(Translation::ERROR_FOLDER_NAME)],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $folder = $folders->create(is_string($parent) ? $parent : '', trim($name));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(
                ['error' => $this->translator->trans($exception->getMessage(), $exception->getParameters())],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return $this->sendJsonResponse(['folder' => $folder->toArray()]);
    }

    private function unavailable(): JsonResponse
    {
        return $this->sendJsonResponse(['error' => $this->translator->trans(Translation::ERROR_UNPUBLISHED)], Response::HTTP_FORBIDDEN);
    }
}
