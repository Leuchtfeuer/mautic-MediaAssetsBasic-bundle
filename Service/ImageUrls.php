<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;

final readonly class ImageUrls
{
    public function __construct(
        private PathsHelper $pathsHelper,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    public function directory(): string
    {
        return rtrim($this->pathsHelper->getImagePath(), '/');
    }

    public function publicUrl(string $relativePath): string
    {
        $static = $this->coreParametersHelper->get('static_url');
        $site   = $this->coreParametersHelper->get('site_url');
        $base   = rtrim(is_string($static) && '' !== $static ? $static : (is_string($site) ? $site : ''), '/');
        $folder = trim(str_replace('\\', '/', (string) $this->pathsHelper->getSystemPath('images', false)), '/');
        $parts  = array_map(
            rawurlencode(...),
            array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), static fn (string $part): bool => '' !== $part)),
        );

        return $base.'/'.$folder.'/'.implode('/', $parts);
    }
}
