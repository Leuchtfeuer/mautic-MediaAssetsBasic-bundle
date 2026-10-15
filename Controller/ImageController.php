<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\MediaIndexer;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\ListedImage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\UploadConflict;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\ConflictType;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\UploadDecision;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\ImageRejectedException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageLibrary;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageStorage;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\IniSize;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\LandingPagePickerContext;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\UploadConflictDetector;
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

        $page       = max(1, $request->query->getInt('page', 1));
        $limit      = min(100, max(1, $request->query->getInt('limit', 24)));
        $allowSvg   = $pickerContext->allowSvg($request);
        $query      = trim($request->query->getString('q'));
        $everywhere = $request->query->getBoolean('everywhere');
        $listed     = $library->listImages($directory['relative'], $page, $limit, $allowSvg, $query, $everywhere);

        return $this->sendJsonResponse([
            'page'       => $page,
            'limit'      => $limit,
            'path'       => $directory['relative'],
            'q'          => $query,
            'everywhere' => $everywhere,
            'total'      => $listed['total'],
            'images'     => array_map(fn (ListedImage $image): array => $this->present($image, $urls), $listed['images']),
        ]);
    }

    public function checkUploadAction(
        Request $request,
        Config $config,
        ImageStorage $storage,
        MediaPath $paths,
        ImageUrls $urls,
        LandingPagePickerContext $pickerContext,
        UploadConflictDetector $conflicts,
    ): JsonResponse {
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
                $intended  = $storage->intendedUploadedName($file, $allowSvg);
                $conflict  = $conflicts->detect($file->getPathname(), $intended, $directory['relative']);
                $results[] = [
                    'ok'       => true,
                    'name'     => $name,
                    'conflict' => null === $conflict ? null : $this->presentConflict($conflict, $urls),
                ];
            } catch (ImageRejectedException $exception) {
                $results[] = ['ok' => false, 'name' => $name, 'error' => $this->rejected($exception)];
            }
        }

        return $this->sendJsonResponse(['files' => $results]);
    }

    public function uploadAction(
        Request $request,
        Config $config,
        ImageStorage $storage,
        MediaPath $paths,
        ImageUrls $urls,
        LandingPagePickerContext $pickerContext,
        MediaIndexer $index,
        UploadConflictDetector $conflicts,
    ): JsonResponse {
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
        $filesIn  = $uploaded instanceof UploadedFile ? [$uploaded] : (is_array($uploaded) ? $uploaded : []);
        $results  = [];
        $allowSvg = $pickerContext->allowSvg($request);
        $decision = UploadDecision::tryFrom((string) $request->request->get('decision', ''));

        foreach ($filesIn as $file) {
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
                $results[] = $this->resolveUpload(
                    $file,
                    $name,
                    $directory,
                    $allowSvg,
                    $decision,
                    $storage,
                    $conflicts,
                    $index,
                    $urls,
                );
            } catch (ImageRejectedException $exception) {
                $results[] = ['ok' => false, 'name' => $name, 'error' => $this->rejected($exception)];
            }
        }

        return $this->sendJsonResponse(['files' => $results]);
    }

    public function importAction(
        Request $request,
        Config $config,
        UrlImageImporter $importer,
        ImageStorage $storage,
        MediaPath $paths,
        ImageUrls $urls,
        LandingPagePickerContext $pickerContext,
        MediaIndexer $index,
        UploadConflictDetector $conflicts,
    ): JsonResponse {
        if (!$config->isPublished()) {
            return $this->unavailable();
        }

        $payload        = json_decode($request->getContent(), true);
        $url            = is_array($payload) ? ($payload['url'] ?? null) : $request->request->get('url');
        $path           = is_array($payload) ? ($payload['path'] ?? '') : $request->request->get('path', '');
        $decisionRaw    = is_array($payload) ? ($payload['decision'] ?? '') : $request->request->get('decision', '');
        $decisionString = is_string($decisionRaw) ? $decisionRaw : '';
        $decision       = UploadDecision::tryFrom($decisionString);
        if (!is_string($url) || '' === trim($url)) {
            return $this->sendJsonResponse(['error' => $this->translator->trans(Translation::ERROR_URL_REQUIRED)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $directory  = $paths->resolve(is_string($path) ? $path : '');
            $allowSvg   = $pickerContext->allowSvg($request);
            $downloaded = $importer->downloadBody(trim($url));
            $name       = $importer->nameFromDownloadedUrl($downloaded['url']);
            $intended   = $storage->intendedContentsName($name, $downloaded['body'], $allowSvg);
            $temp       = tempnam(sys_get_temp_dir(), 'lf-media-import');
            if (false === $temp) {
                throw new ImageRejectedException(Translation::ERROR_DOWNLOAD);
            }
            file_put_contents($temp, $downloaded['body']);
            try {
                $conflict = $conflicts->detect($temp, $intended, $directory['relative']);
            } finally {
                @unlink($temp);
            }

            if ($conflict instanceof UploadConflict && null === $decision) {
                return $this->sendJsonResponse([
                    'conflict' => $this->presentConflict($conflict, $urls),
                    'name'     => $name,
                ], Response::HTTP_CONFLICT);
            }

            $image = $this->commitImport(
                $downloaded['body'],
                $name,
                $intended,
                $directory,
                $allowSvg,
                $decision,
                $conflict,
                $storage,
                $index,
                $urls,
            );
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(['error' => $this->rejected($exception)], Response::HTTP_BAD_REQUEST);
        }

        return $this->sendJsonResponse(['image' => $image]);
    }

    public function moveAction(Request $request, Config $config, ImageStorage $storage, MediaPath $paths, ImageUrls $urls, LandingPagePickerContext $pickerContext, MediaIndexer $index): JsonResponse
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
            $folderPath  = is_string($folder) ? $folder : '';
            $trimmedPath = trim($path);
            $image       = $storage->move($paths, $trimmedPath, $folderPath, $pickerContext->allowSvg($request));
            $index->registerMoved($trimmedPath, $image, $pickerContext->allowSvg($request));
        } catch (ImageRejectedException $exception) {
            return $this->sendJsonResponse(['error' => $this->rejected($exception)], Response::HTTP_BAD_REQUEST);
        }

        return $this->sendJsonResponse(['image' => $this->present($image, $urls)]);
    }

    /**
     * @param array{absolute: string, relative: string} $directory
     *
     * @return array<string, mixed>
     */
    private function resolveUpload(
        UploadedFile $file,
        string $name,
        array $directory,
        bool $allowSvg,
        ?UploadDecision $decision,
        ImageStorage $storage,
        UploadConflictDetector $conflicts,
        MediaIndexer $index,
        ImageUrls $urls,
    ): array {
        $intended = $storage->intendedUploadedName($file, $allowSvg);
        $conflict = $conflicts->detect($file->getPathname(), $intended, $directory['relative']);

        if ($conflict instanceof UploadConflict && null === $decision) {
            return [
                'ok'       => false,
                'name'     => $name,
                'conflict' => $this->presentConflict($conflict, $urls),
            ];
        }

        if ($conflict instanceof UploadConflict) {
            return $this->commitUploadWithDecision(
                $file,
                $name,
                $intended,
                $directory,
                $allowSvg,
                $decision,
                $conflict,
                $storage,
                $index,
                $urls,
            );
        }

        $image = $storage->storeUploaded($directory['absolute'], $file, $directory['relative'], $allowSvg);
        $index->registerUploaded($image, $allowSvg);

        return ['ok' => true, 'image' => $this->present($image, $urls)];
    }

    /**
     * @param array{absolute: string, relative: string} $directory
     *
     * @return array<string, mixed>
     */
    private function commitUploadWithDecision(
        UploadedFile $file,
        string $name,
        string $intended,
        array $directory,
        bool $allowSvg,
        ?UploadDecision $decision,
        UploadConflict $conflict,
        ImageStorage $storage,
        MediaIndexer $index,
        ImageUrls $urls,
    ): array {
        if (ConflictType::ChecksumDuplicate === $conflict->type) {
            if (UploadDecision::UseExisting === $decision) {
                return [
                    'ok'            => true,
                    'usedExisting'  => true,
                    'image'         => $this->presentFile($conflict->existing, $urls),
                ];
            }
            if (UploadDecision::KeepBoth === $decision) {
                $image = $storage->storeUploaded($directory['absolute'], $file, $directory['relative'], $allowSvg);
                $index->registerUploaded($image, $allowSvg);

                return ['ok' => true, 'image' => $this->present($image, $urls)];
            }

            return [
                'ok'    => false,
                'name'  => $name,
                'error' => $this->translator->trans(Translation::ERROR_UPLOAD_DECISION),
            ];
        }

        // name clash
        if (UploadDecision::Replace === $decision) {
            $image = $storage->replaceUploaded(
                $directory['absolute'],
                $file,
                $directory['relative'],
                $intended,
                $allowSvg,
            );
            $index->registerUploaded($image, $allowSvg);

            return ['ok' => true, 'image' => $this->present($image, $urls)];
        }
        if (UploadDecision::KeepBoth === $decision) {
            $image = $storage->storeUploaded($directory['absolute'], $file, $directory['relative'], $allowSvg);
            $index->registerUploaded($image, $allowSvg);

            return ['ok' => true, 'image' => $this->present($image, $urls)];
        }

        return [
            'ok'    => false,
            'name'  => $name,
            'error' => $this->translator->trans(Translation::ERROR_UPLOAD_DECISION),
        ];
    }

    /**
     * @param array{absolute: string, relative: string} $directory
     *
     * @return array{name: string, path: string, url: string, width: int, height: int, bytes: int}
     */
    private function commitImport(
        string $contents,
        string $originalName,
        string $intended,
        array $directory,
        bool $allowSvg,
        ?UploadDecision $decision,
        ?UploadConflict $conflict,
        ImageStorage $storage,
        MediaIndexer $index,
        ImageUrls $urls,
    ): array {
        if (!$conflict instanceof UploadConflict) {
            $image = $storage->store($directory['absolute'], $originalName, $contents, $directory['relative'], $allowSvg);
            $index->registerUploaded($image, $allowSvg);

            return $this->present($image, $urls);
        }

        if (ConflictType::ChecksumDuplicate === $conflict->type) {
            if (UploadDecision::UseExisting === $decision) {
                return $this->presentFile($conflict->existing, $urls);
            }
            if (UploadDecision::KeepBoth === $decision) {
                $image = $storage->store($directory['absolute'], $originalName, $contents, $directory['relative'], $allowSvg);
                $index->registerUploaded($image, $allowSvg);

                return $this->present($image, $urls);
            }

            throw new ImageRejectedException(Translation::ERROR_UPLOAD_DECISION);
        }

        if (UploadDecision::Replace === $decision) {
            $image = $storage->storeContentsAs(
                $directory['absolute'],
                $intended,
                $contents,
                $directory['relative'],
                $allowSvg,
            );
            $index->registerUploaded($image, $allowSvg);

            return $this->present($image, $urls);
        }
        if (UploadDecision::KeepBoth === $decision) {
            $image = $storage->store($directory['absolute'], $originalName, $contents, $directory['relative'], $allowSvg);
            $index->registerUploaded($image, $allowSvg);

            return $this->present($image, $urls);
        }

        throw new ImageRejectedException(Translation::ERROR_UPLOAD_DECISION);
    }

    /**
     * @return array{type: string, intendedName: string, intendedPath: string, existing: array{name: string, path: string, url: string, dateAdded: ?string}}
     */
    private function presentConflict(UploadConflict $conflict, ImageUrls $urls): array
    {
        $existing = $conflict->existing;
        $path     = $existing->getRelativePath();

        return [
            'type'          => $conflict->type->value,
            'intendedName'  => $conflict->intendedName,
            'intendedPath'  => $conflict->intendedPath,
            'existing'      => [
                'name'      => $existing->getFilename(),
                'path'      => $path,
                'url'       => $urls->publicUrl($path),
                'dateAdded' => $existing->getDateAdded()?->format('Y-m-d'),
            ],
        ];
    }

    /**
     * @return array{name: string, path: string, url: string, width: int, height: int, bytes: int}
     */
    private function presentFile(MediaAssetFile $file, ImageUrls $urls): array
    {
        $path = $file->getRelativePath();

        return [
            'name'   => $file->getFilename(),
            'path'   => $path,
            'url'    => $urls->publicUrl($path),
            'width'  => $file->getWidth(),
            'height' => $file->getHeight(),
            'bytes'  => $file->getBytes(),
        ];
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
