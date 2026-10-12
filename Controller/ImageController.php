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
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\LandingPagePickerContext;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\UrlImageImporter;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Translation;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImageController extends AjaxController
{
    public function listAction(Request $request, Config $config, ImageLibrary $library, MediaPath $paths, ImageUrls $urls, LandingPagePickerContext $pickerContext): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        try {
            $directory = $paths->resolve($this->queryPath($request));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(['error' => $this->rejected($exception)], Response::HTTP_BAD_REQUEST);
        }

        $page     = max(1, $request->query->getInt('page', 1));
        $limit    = min(100, max(1, $request->query->getInt('limit', 24)));
        $allowSvg = $pickerContext->allowSvg($request);
        $listed   = $library->listImages($directory['absolute'], $page, $limit, $directory['relative'], $allowSvg);

        return $this->sendJsonResponse([
            'page'   => $page,
            'limit'  => $limit,
            'path'   => $directory['relative'],
            'total'  => $listed['total'],
            'images' => array_map(fn (ListedImage $image): array => $this->present($image, $urls), $listed['images']),
        ]);
    }

    public function uploadAction(Request $request, Config $config, ImageStorage $storage, MediaPath $paths, ImageUrls $urls, LandingPagePickerContext $pickerContext): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        try {
            $directory = $paths->resolve($this->requestPath($request));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse([
                'files' => [[
                    'ok'    => false,
                    'name'  => '',
                    'error' => $this->rejected($exception),
                ]],
            ], Response::HTTP_BAD_REQUEST);
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
        $allowSvg = $pickerContext->allowSvg($request);

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
                $image     = $storage->storeUploaded($directory['absolute'], $file, $directory['relative'], $allowSvg);
                $results[] = ['ok' => true, 'image' => $this->present($image, $urls)];
            } catch (ImageRejectedException $exception) {
                $results[] = ['ok' => false, 'name' => $name, 'error' => $this->rejected($exception)];
            }
        }

        return $this->sendJsonResponse(['files' => $results]);
    }

    public function importAction(Request $request, Config $config, UrlImageImporter $importer, MediaPath $paths, ImageUrls $urls, LandingPagePickerContext $pickerContext): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $payload = json_decode($request->getContent(), true);
        $url     = is_array($payload) ? ($payload['url'] ?? null) : $request->request->get('url');
        $path    = is_array($payload) ? ($payload['path'] ?? '') : $request->request->get('path', '');
        if (!is_string($url) || '' === trim($url)) {
            return $this->sendJsonResponse(['error' => $this->translator->trans(Translation::ERROR_URL_REQUIRED)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $directory = $paths->resolve(is_string($path) ? $path : '');
            $image     = $importer->import($directory['absolute'], trim($url), $directory['relative'], $pickerContext->allowSvg($request));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(['error' => $this->rejected($exception)], Response::HTTP_BAD_REQUEST);
        }

        return $this->sendJsonResponse(['image' => $this->present($image, $urls)]);
    }

    public function moveAction(Request $request, Config $config, ImageStorage $storage, MediaPath $paths, ImageUrls $urls, LandingPagePickerContext $pickerContext): JsonResponse
    {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $payload = json_decode($request->getContent(), true);
        $path    = is_array($payload) ? ($payload['path'] ?? null) : $request->request->get('path');
        $folder  = is_array($payload) ? ($payload['folder'] ?? '') : $request->request->get('folder', '');
        if (!is_string($path) || '' === trim($path)) {
            return $this->sendJsonResponse(['error' => $this->translator->trans(Translation::ERROR_PATH)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $folderPath = is_string($folder) ? $folder : '';
            $image      = $storage->move($paths, trim($path), $folderPath, $pickerContext->allowSvg($request));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(['error' => $this->rejected($exception)], Response::HTTP_BAD_REQUEST);
        }

        return $this->sendJsonResponse(['image' => $this->present($image, $urls)]);
    }

    /**
     * @return array{name: string, path: string, url: string, width: int, height: int, bytes: int}
     */
    private function present(ListedImage $image, ImageUrls $urls): array
    {
        $path = '' !== $image->path ? $image->path : $image->name;

        return $image->toArray() + ['url' => $urls->publicUrl($path)];
    }

    private function queryPath(Request $request): ?string
    {
        $path = $request->query->get('path');

        return is_string($path) ? $path : null;
    }

    private function requestPath(Request $request): ?string
    {
        $path = $request->request->get('path', $request->query->get('path'));

        return is_string($path) ? $path : null;
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
