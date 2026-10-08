<?php

declare(strict_types=1);

trait MediaAssetsAcceptanceTrait
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function loginAsAdmin(AcceptanceTester $I): void
    {
        $username = $_ENV['MAUTIC_ADMIN_USERNAME'] ?? 'admin';
        $password = $_ENV['MAUTIC_ADMIN_PASSWORD'] ?? 'Maut1cR0cks!';

        // .env.test uses "mautic" as a placeholder; DDEV installs use Maut1cR0cks!
        if ('mautic' === $password) {
            $password = 'Maut1cR0cks!';
        }

        $I->login($username, $password);
    }

    protected function dbTable(string $name): string
    {
        $prefix = $_ENV['MAUTIC_TABLE_PREFIX'] ?? $_ENV['MAUTIC_DB_PREFIX'] ?? 'test_';

        return $prefix.$name;
    }

    protected function ensureMediaAssetsPluginEnabled(AcceptanceTester $I, bool $published = true): void
    {
        $this->ensureIntegration($I, 'GrapesJsBuilderBundle', 'grapesjsbuilder', 'GrapesJS', true);
        $this->ensureIntegration(
            $I,
            'LeuchtfeuerMediaAssetsBasicBundle',
            'LeuchtfeuerMediaAssetsBasic',
            'Media & Assets (Basic) by Leuchtfeuer',
            $published,
        );
    }

    protected function imageDirectory(): string
    {
        return dirname(__DIR__, 5).'/media/images';
    }

    protected function writeImage(string $filename): void
    {
        $directory = $this->imageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The image directory could not be created.');
        }

        $png = base64_decode(self::PNG, true);
        if (!is_string($png) || false === file_put_contents($directory.'/'.$filename, $png)) {
            throw new RuntimeException('The acceptance image could not be written.');
        }
    }

    protected function deleteImages(string $prefix): void
    {
        $paths = glob($this->imageDirectory().'/'.$prefix.'*');
        if (!is_array($paths)) {
            return;
        }

        foreach ($paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    protected function openPageBuilder(AcceptanceTester $I): void
    {
        $I->amOnPage('/s/pages/new');
        $I->waitForElementVisible('form[name="page"]', AcceptanceTester::TIMEOUT);
        $I->fillField('input[name="page[title]"]', 'Media Assets E2E '.date('YmdHis'));
        $I->waitForJS("return typeof Mautic.launchBuilder === 'function' && Mautic.launchBuilder.toString().includes('builder-active') && document.querySelector('#page_buttons_builder') !== null;", 30);
        $I->waitForJS("return document.querySelector('#page_buttons_builder').disabled === false;", 30);
        // GrapesJS passes the editor as the second argument of builder:show.
        $I->executeJS(<<<'JS'
            window.__lfMediaAssetsEditor = null;
            window.mQuery(document).on('builder:show', '.builder', function (_event, editor) {
                window.__lfMediaAssetsEditor = editor;
            });
        JS);
        $I->executeJS("document.querySelector('#page_buttons_builder').click();");
        $I->waitForElementVisible('.builder.builder-active', AcceptanceTester::TIMEOUT);
        $I->waitForElementVisible('iframe.gjs-frame', AcceptanceTester::TIMEOUT);
    }

    protected function openCustomImagePicker(AcceptanceTester $I): void
    {
        $I->waitForJS('return window.__lfMediaAssetsBasicInstalled === true && !!(window.LeuchtfeuerMediaAssetsBasic && window.LeuchtfeuerMediaAssetsBasic.listUrl);', 15);
        $this->openAssetManager($I, '.lf-media-assets');
        $I->waitForText('Images', AcceptanceTester::TIMEOUT, '.lf-media-assets');
    }

    protected function openBuiltInAssetManager(AcceptanceTester $I): void
    {
        $I->waitForJS('return typeof window.LeuchtfeuerMediaAssetsBasic === "undefined";', 15);
        $this->openAssetManager($I, '.gjs-am-assets');
    }

    private function openAssetManager(AcceptanceTester $I, string $readySelector): void
    {
        $I->waitForElementVisible('iframe.gjs-frame', AcceptanceTester::TIMEOUT);
        // A click on the Image block does not insert it (appendOnClick is false).
        // Double-clicking an image runs the same active handler, which opens the asset manager.
        $I->waitForJS('return !!(window.__lfMediaAssetsEditor && window.__lfMediaAssetsEditor.getWrapper);', 15);
        $I->executeJS(<<<'JS'
            const editor = window.__lfMediaAssetsEditor;
            const wrapper = editor.getWrapper();
            const existing = wrapper.findType('image')[0];
            const image = existing || wrapper.append({ type: 'image' })[0];
            const view = image.getView();
            if (!view || typeof view.onActive !== 'function') {
                throw new Error('Image component has no active handler');
            }
            view.onActive();
        JS);
        $I->waitForElementVisible($readySelector, AcceptanceTester::TIMEOUT);
    }

    private function ensureIntegration(
        AcceptanceTester $I,
        string $bundle,
        string $name,
        string $label,
        bool $published,
    ): void {
        $pluginsTable  = $this->dbTable('plugins');
        $settingsTable = $this->dbTable('plugin_integration_settings');

        if (!$I->grabFromDatabase($pluginsTable, 'id', ['bundle' => $bundle])) {
            $I->haveInDatabase($pluginsTable, [
                'name'        => $label,
                'description' => $label,
                'is_missing'  => 0,
                'bundle'      => $bundle,
                'version'     => '1.0.0',
                'author'      => 'Leuchtfeuer',
            ]);
        }

        $pluginId = (int) $I->grabFromDatabase($pluginsTable, 'id', ['bundle' => $bundle]);
        $values   = [
            'plugin_id'          => $pluginId,
            'is_published'       => $published ? 1 : 0,
            'supported_features' => serialize([]),
            'api_keys'           => serialize([]),
            'feature_settings'   => serialize([]),
        ];

        if ($I->grabFromDatabase($settingsTable, 'id', ['name' => $name])) {
            $I->updateInDatabase($settingsTable, $values, ['name' => $name]);
        } else {
            $I->haveInDatabase($settingsTable, $values + ['name' => $name]);
        }
    }
}
