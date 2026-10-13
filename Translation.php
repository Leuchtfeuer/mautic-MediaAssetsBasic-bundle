<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle;

final class Translation
{
    public const NAME = 'mautic.integration.leuchtfeuermediaassetsbasic';

    public const DESCRIPTION = 'mautic.plugin.leuchtfeuermediaassetsbasic.description';

    public const IMAGES = 'mautic.plugin.leuchtfeuermediaassetsbasic.images';

    public const IMPORT_FROM_URL = 'mautic.plugin.leuchtfeuermediaassetsbasic.import_from_url';

    public const UPLOAD = 'mautic.plugin.leuchtfeuermediaassetsbasic.upload';

    public const CLOSE = 'mautic.plugin.leuchtfeuermediaassetsbasic.close';

    public const FETCH = 'mautic.plugin.leuchtfeuermediaassetsbasic.fetch';

    public const URL_PLACEHOLDER = 'mautic.plugin.leuchtfeuermediaassetsbasic.url_placeholder';

    public const LOAD_FAILED = 'mautic.plugin.leuchtfeuermediaassetsbasic.load_failed';

    public const IMPORT_FAILED = 'mautic.plugin.leuchtfeuermediaassetsbasic.import_failed';

    public const EMPTY = 'mautic.plugin.leuchtfeuermediaassetsbasic.empty';

    public const DROP = 'mautic.plugin.leuchtfeuermediaassetsbasic.drop';

    public const ITEMS_PER_PAGE = 'mautic.plugin.leuchtfeuermediaassetsbasic.items_per_page';

    public const RANGE = 'mautic.plugin.leuchtfeuermediaassetsbasic.range';

    public const PAGE = 'mautic.plugin.leuchtfeuermediaassetsbasic.page';

    public const BYTE = 'mautic.plugin.leuchtfeuermediaassetsbasic.byte';

    public const KILOBYTE = 'mautic.plugin.leuchtfeuermediaassetsbasic.kilobyte';

    public const MEGABYTE = 'mautic.plugin.leuchtfeuermediaassetsbasic.megabyte';

    public const ERROR_TYPE = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.type';

    public const ERROR_READ = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.read';

    public const ERROR_DIRECTORY = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.directory';

    public const ERROR_STORE = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.store';

    public const ERROR_REDIRECTS = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.redirects';

    public const ERROR_SCHEME = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.scheme';

    public const ERROR_DOWNLOAD = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.download';

    public const ERROR_TOO_LARGE = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.too_large';

    public const ERROR_TIMEOUT = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.timeout';

    public const ERROR_CREDENTIALS = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.credentials';

    public const ERROR_RESOLVE = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.resolve';

    public const ERROR_BLOCKED = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.blocked';

    public const ERROR_UPLOAD_LIMIT = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.upload_limit';

    public const ERROR_UPLOAD = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.upload';

    public const ERROR_URL_REQUIRED = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.url_required';

    public const ERROR_UNPUBLISHED = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.unpublished';

    public const ERROR_PATH = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.path';

    public const ERROR_FOLDER_NAME = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.folder_name';

    public const ERROR_FOLDER_EXISTS = 'mautic.plugin.leuchtfeuermediaassetsbasic.error.folder_exists';

    public const FOLDERS = 'mautic.plugin.leuchtfeuermediaassetsbasic.folders';

    public const HOME = 'mautic.plugin.leuchtfeuermediaassetsbasic.home';

    public const NEW_FOLDER = 'mautic.plugin.leuchtfeuermediaassetsbasic.new_folder';

    public const FOLDER_NAME_PLACEHOLDER = 'mautic.plugin.leuchtfeuermediaassetsbasic.folder_name_placeholder';

    public const CREATE_FOLDER = 'mautic.plugin.leuchtfeuermediaassetsbasic.create_folder';

    public const FOLDER_CREATE_FAILED = 'mautic.plugin.leuchtfeuermediaassetsbasic.folder_create_failed';

    public const EXPAND_FOLDER = 'mautic.plugin.leuchtfeuermediaassetsbasic.expand_folder';

    public const COLLAPSE_FOLDER = 'mautic.plugin.leuchtfeuermediaassetsbasic.collapse_folder';

    public const MOVE_FAILED = 'mautic.plugin.leuchtfeuermediaassetsbasic.move_failed';

    public const MOVING = 'mautic.plugin.leuchtfeuermediaassetsbasic.moving';

    /**
     * @return array<string, string>
     */
    public static function picker(): array
    {
        return [
            'images'                => self::IMAGES,
            'importFromUrl'         => self::IMPORT_FROM_URL,
            'upload'                => self::UPLOAD,
            'close'                 => self::CLOSE,
            'fetch'                 => self::FETCH,
            'urlPlaceholder'        => self::URL_PLACEHOLDER,
            'loadFailed'            => self::LOAD_FAILED,
            'importFailed'          => self::IMPORT_FAILED,
            'empty'                 => self::EMPTY,
            'drop'                  => self::DROP,
            'itemsPerPage'          => self::ITEMS_PER_PAGE,
            'range'                 => self::RANGE,
            'page'                  => self::PAGE,
            'byte'                  => self::BYTE,
            'kilobyte'              => self::KILOBYTE,
            'megabyte'              => self::MEGABYTE,
            'uploadFailed'          => self::ERROR_UPLOAD,
            'folders'               => self::FOLDERS,
            'home'                  => self::HOME,
            'newFolder'             => self::NEW_FOLDER,
            'folderNamePlaceholder' => self::FOLDER_NAME_PLACEHOLDER,
            'createFolder'          => self::CREATE_FOLDER,
            'folderCreateFailed'    => self::FOLDER_CREATE_FAILED,
            'expandFolder'          => self::EXPAND_FOLDER,
            'collapseFolder'        => self::COLLAPSE_FOLDER,
            'moveFailed'            => self::MOVE_FAILED,
            'moving'                => self::MOVING,
        ];
    }
}
