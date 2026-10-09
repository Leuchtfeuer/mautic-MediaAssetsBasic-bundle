<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageLibrary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\IniSize;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\UrlImageImporter;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImageController extends AjaxController
{
    public function listAction(Request $request, Config $config, ImageLibrary $library, ImageUrls $urls): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $page   = max(1, $request->query->getInt('page', 1));
        $limit  = min(100, max(1, $request->query->getInt('limit', 24)));
        $listed = $library->listImages($urls->directory(), $page, $limit);

        return $this->sendJsonResponse([
            'page'   => $page,
            'limit'  => $limit,
            'total'  => $listed['total'],
            'images' => array_map(fn (ListedImage $image): array => $this->present($image, $urls), $listed['images']),
        ]);
    }

    public function uploadAction(Request $request, Config $config, ImageStorage $storage, ImageUrls $urls): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $rawLength     = $request->server->get('CONTENT_LENGTH', 0);
        $contentLength = is_numeric($rawLength) ? (int) $rawLength : 0;
        $postLimit     = IniSize::toBytes((string) ini_get('post_max_size'));
        // PHP discards the body when post_max_size is exceeded, so the request arrives with a Content-Length and no files.
        if ($contentLength > 0 && $postLimit > 0 && $contentLength > $postLimit && !$request->files->count()) {
            return $this->sendJsonResponse([
                'files' => [[
                    'ok'    => false,
                    'name'  => '',
                    'error' => $this->limitMessage(),
                ]],
            ], Response::HTTP_BAD_REQUEST);
        }

        $uploaded = $request->files->get('files');
        $files    = $uploaded instanceof UploadedFile ? [$uploaded] : (is_array($uploaded) ? $uploaded : []);
        $results  = [];

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $name = $file->getClientOriginalName();
            if (!$file->isValid()) {
                $results[] = [
                    'ok'    => false,
                    'name'  => $name,
                    'error' => \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                        ? $this->limitMessage()
                        : $this->translator->trans(Translation::ERROR_UPLOAD),
                ];
                continue;
            }

            try {
                $image     = $storage->storeUploaded($urls->directory(), $file);
                $results[] = ['ok' => true, 'image' => $this->present($image, $urls)];
            } catch (ImageRejectedException $exception) {
                $results[] = ['ok' => false, 'name' => $name, 'error' => $this->rejected($exception)];
            }
        }

        return $this->sendJsonResponse(['files' => $results]);
    }

    public function importAction(Request $request, Config $config, UrlImageImporter $importer, ImageUrls $urls): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $payload = json_decode($request->getContent(), true);
        $url     = is_array($payload) ? ($payload['url'] ?? null) : $request->request->get('url');
        if (!is_string($url) || '' === trim($url)) {
            return $this->sendJsonResponse(['error' => $this->translator->trans(Translation::ERROR_URL_REQUIRED)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $image = $importer->import($urls->directory(), trim($url));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(['error' => $this->rejected($exception)], Response::HTTP_BAD_REQUEST);
        }

        return $this->sendJsonResponse(['image' => $this->present($image, $urls)]);
    }

    /**
     * @return array{name: string, url: string, width: int, height: int, bytes: int}
     */
    private function present(ListedImage $image, ImageUrls $urls): array
    {
        return $image->toArray() + ['url' => $urls->publicUrl($image->name)];
    }

    private function unavailable(): JsonResponse
    {
        return $this->sendJsonResponse(['error' => $this->translator->trans(Translation::ERROR_UNPUBLISHED)], Response::HTTP_FORBIDDEN);
    }

    private function rejected(ImageRejectedException $exception): string
    {
        return $this->translator->trans($exception->getMessage(), $exception->getParameters());
    }

    private function limitMessage(): string
    {
        return $this->translator->trans(Translation::ERROR_UPLOAD_LIMIT, ['%limit%' => IniSize::uploadMaxFilesize()]);
    }
}
