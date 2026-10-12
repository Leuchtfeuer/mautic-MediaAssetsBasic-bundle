<?php

declare(strict_types=1);

return [
    'name'        => 'Media & Assets (Basic) by Leuchtfeuer',
    'description' => 'Powerful Media & Assets Manager for organizing and picking images etc. in the Mautic builders',
    'version'     => '1.0.0',
    'author'      => 'Leuchtfeuer Digital Marketing GmbH',
    'routes'      => [
        'main' => [
            'leuchtfeuer_media_assets_basic_image_list' => [
                'path'       => '/media-assets-basic/images',
                'controller' => 'MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller\ImageController::listAction',
                'method'     => 'GET',
            ],
            'leuchtfeuer_media_assets_basic_image_upload' => [
                'path'       => '/media-assets-basic/images/upload',
                'controller' => 'MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller\ImageController::uploadAction',
                'method'     => 'POST',
            ],
            'leuchtfeuer_media_assets_basic_image_import' => [
                'path'       => '/media-assets-basic/images/import',
                'controller' => 'MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller\ImageController::importAction',
                'method'     => 'POST',
            ],
            'leuchtfeuer_media_assets_basic_image_move' => [
                'path'       => '/media-assets-basic/images/move',
                'controller' => 'MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller\ImageController::moveAction',
                'method'     => 'POST',
            ],
            'leuchtfeuer_media_assets_basic_folder_list' => [
                'path'       => '/media-assets-basic/folders',
                'controller' => 'MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller\FolderController::listAction',
                'method'     => 'GET',
            ],
            'leuchtfeuer_media_assets_basic_folder_create' => [
                'path'       => '/media-assets-basic/folders',
                'controller' => 'MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Controller\FolderController::createAction',
                'method'     => 'POST',
            ],
        ],
    ],
];
