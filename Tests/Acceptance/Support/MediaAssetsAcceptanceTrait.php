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

    protected function writeImage(AcceptanceTester $I, string $filename): void
    {
        $directory = $this->imageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('The image directory could not be created.');
        }

        $png  = base64_decode(self::PNG, true);
        $path = $directory.'/'.$filename;
        if (!is_string($png) || false === file_put_contents($path, $png)) {
            throw new RuntimeException('The acceptance image could not be written.');
        }

        // Picker lists from the DB index. Seed every DB the live app may use:
        // Codeception/.env.test → `test` + prefix; DDEV local.php often → `db` without prefix.
        $this->indexSeededImage($filename, $path);
    }

    protected function deleteImages(AcceptanceTester $I, string $prefix): void
    {
        $paths = glob($this->imageDirectory().'/'.$prefix.'*');
        if (is_array($paths)) {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        $this->softDeleteIndexedImages($prefix);
    }

    private function indexSeededImage(string $filename, string $absolutePath): void
    {
        $mtime = filemtime($absolutePath);
        $bytes = filesize($absolutePath);
        if (false === $mtime || false === $bytes) {
            throw new RuntimeException('The seeded image metadata could not be read.');
        }

        $seeded = false;
        foreach ($this->mediaIndexTargets() as $target) {
            try {
                $pdo = $this->pdoFor($target);
                $sql = sprintf(
                    'INSERT INTO `%s` (folder_id, relative_path, filename, mime, width, height, bytes, file_mtime, missing_since)
                     VALUES (NULL, :relative_path, :filename, :mime, 1, 1, :bytes, :file_mtime, NULL)
                     ON DUPLICATE KEY UPDATE mime = VALUES(mime), width = VALUES(width), height = VALUES(height),
                       bytes = VALUES(bytes), file_mtime = VALUES(file_mtime), missing_since = NULL',
                    $target['table'],
                );
                $statement = $pdo->prepare($sql);
                $statement->execute([
                    'relative_path' => $filename,
                    'filename'      => $filename,
                    'mime'          => 'image/png',
                    'bytes'         => $bytes,
                    'file_mtime'    => $mtime,
                ]);
                $seeded = true;
            } catch (Throwable) {
                continue;
            }
        }

        if (!$seeded) {
            throw new RuntimeException('The seeded image could not be written to any media index table.');
        }
    }

    private function softDeleteIndexedImages(string $prefix): void
    {
        $missingSince = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($this->mediaIndexTargets() as $target) {
            try {
                $pdo       = $this->pdoFor($target);
                $statement = $pdo->prepare(sprintf(
                    'UPDATE `%s` SET missing_since = :missing_since WHERE relative_path LIKE :prefix',
                    $target['table'],
                ));
                $statement->execute([
                    'missing_since' => $missingSince,
                    'prefix'        => $prefix.'%',
                ]);
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * @return list<array{dsn: string, user: string, pass: string, table: string}>
     */
    private function mediaIndexTargets(): array
    {
        $host   = $_ENV['DB_HOST'] ?? 'db';
        $user   = $_ENV['DB_USER'] ?? 'db';
        $pass   = $_ENV['DB_PASSWD'] ?? 'db';
        $prefix = $_ENV['MAUTIC_TABLE_PREFIX'] ?? $_ENV['MAUTIC_DB_PREFIX'] ?? 'test_';
        $testDb = $_ENV['DB_NAME'] ?? 'test';

        $targets = [
            [
                'dsn'   => sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $testDb),
                'user'  => $user,
                'pass'  => $pass,
                'table' => $prefix.'lf_media_asset_files',
            ],
            // DDEV / local.php default: database `db`, no table prefix.
            [
                'dsn'   => sprintf('mysql:host=%s;dbname=db;charset=utf8mb4', $host),
                'user'  => $user,
                'pass'  => $pass,
                'table' => 'lf_media_asset_files',
            ],
        ];

        $unique = [];
        foreach ($targets as $target) {
            $unique[$target['dsn'].'|'.$target['table']] = $target;
        }

        return array_values($unique);
    }

    /**
     * @param array{dsn: string, user: string, pass: string, table: string} $target
     */
    private function pdoFor(array $target): PDO
    {
        return new PDO($target['dsn'], $target['user'], $target['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function openPageBuilder(AcceptanceTester $I): void
    {
        $I->amOnPage('/s/pages/new');
        $I->waitForElementVisible('form[name="page"]', AcceptanceTester::TIMEOUT);
        $I->fillField('input[name="page[title]"]', 'Media Assets E2E '.date('YmdHis'));
        $I->waitForJS("return typeof Mautic.launchBuilder === 'function' && Mautic.launchBuilder.toString().includes('builder-active') && document.querySelector('#page_buttons_builder') !== null;", 30);
        $I->waitForJS("return document.querySelector('#page_buttons_builder').disabled === false;", 30);
        $this->launchBuilder($I, '#page_buttons_builder');
    }

    protected function openEmailBuilder(AcceptanceTester $I): void
    {
        $I->amOnPage('/s/emails/new');
        $I->waitForElementVisible('.email-type-modal .col-md-6:last-child .tile.tile--clickable', AcceptanceTester::TIMEOUT);
        $I->click('.email-type-modal .col-md-6:last-child .tile.tile--clickable');
        $I->waitForElementVisible('form[name="emailform"]', AcceptanceTester::TIMEOUT);
        $I->fillField('input[name="emailform[name]"]', 'Media Assets E2E '.date('YmdHis'));
        $I->fillField('input[name="emailform[subject]"]', 'Media Assets E2E');
        $I->waitForJS("return typeof Mautic.setThemeHtml === 'function' && typeof Mautic.launchBuilder === 'function' && Mautic.launchBuilder.toString().includes('builder-active');", 30);
        // The default email theme is MJML. Reload it once GrapesJS owns setThemeHtml,
        // so the builder opens the MJML editor rather than the HTML one.
        $I->executeJS(<<<'JS'
            const mjml = document.querySelector('textarea.builder-mjml');
            if (!mjml || mjml.value.indexOf('<mjml') === -1) {
                const theme = document.querySelector('[name="emailform[template]"]');
                Mautic.setThemeHtml(theme && theme.value ? theme.value : 'blank');
            }
        JS);
        $I->waitForJS("return !!(document.querySelector('textarea.builder-mjml') && document.querySelector('textarea.builder-mjml').value.indexOf('<mjml') !== -1);", 30);
        $I->waitForJS("return document.querySelector('#emailform_buttons_builder') !== null && document.querySelector('#emailform_buttons_builder').disabled === false;", 30);
        $this->launchBuilder($I, '#emailform_buttons_builder');
    }

    protected function launchBuilder(AcceptanceTester $I, string $button): void
    {
        $I->executeJS(<<<'JS'
            window.__lfMediaAssetsEditor = null;
            window.mQuery(document).on('builder:show', '.builder', function (_event, editor) {
                window.__lfMediaAssetsEditor = editor;
            });
        JS);
        $I->executeJS('document.querySelector('.json_encode($button).').click();');
        $I->waitForElementVisible('.builder.builder-active', AcceptanceTester::TIMEOUT);
        $I->waitForElementVisible('iframe.gjs-frame', AcceptanceTester::TIMEOUT);
    }

    protected function openCustomImagePicker(AcceptanceTester $I): void
    {
        $this->openPicker($I, 'image');
        $I->waitForText('Images', AcceptanceTester::TIMEOUT, '.lf-media-assets');
    }

    protected function openBuiltInAssetManager(AcceptanceTester $I): void
    {
        $I->waitForJS('return typeof window.LeuchtfeuerMediaAssetsBasic === "undefined";', 15);
        $this->openPicker($I, 'image', '.gjs-am-assets');
    }

    protected function openPicker(AcceptanceTester $I, string $kind, string $readySelector = '.lf-media-assets'): void
    {
        if ('.lf-media-assets' === $readySelector) {
            $I->waitForJS('return window.__lfMediaAssetsBasicInstalled === true && !!(window.LeuchtfeuerMediaAssetsBasic && window.LeuchtfeuerMediaAssetsBasic.listUrl);', 15);
        }
        $I->waitForElementVisible('iframe.gjs-frame', AcceptanceTester::TIMEOUT);
        $I->waitForJS('return !!(window.__lfMediaAssetsEditor && window.__lfMediaAssetsEditor.getWrapper);', 15);
        $kindJson = json_encode($kind, JSON_THROW_ON_ERROR);
        // Block clicks do not insert components. These calls are the handlers behind
        // double-click, an empty image, and the background file buttons.
        $I->executeJS(<<<JS
            (() => {
              const kind = {$kindJson};
              const editor = window.__lfMediaAssetsEditor;
              const wrapper = editor.getWrapper();

              const componentOf = (type) => {
                const found = wrapper.findType(type);
                if (found && found.length) {
                  return found[0];
                }
                let parent = wrapper;
                if (type === 'mj-section' || type === 'mj-hero') {
                  const body = wrapper.findType('mj-body');
                  if (body && body.length) {
                    parent = body[0];
                  }
                }
                if (type === 'mj-column') {
                  parent = componentOf('mj-section');
                }
                if (type === 'mj-image') {
                  const columns = wrapper.findType('mj-column');
                  parent = columns && columns.length ? columns[0] : componentOf('mj-column');
                }
                const created = parent.append({ type });
                const component = Array.isArray(created) ? created[0] : created;
                if (!component) {
                  throw new Error('Could not create ' + type);
                }
                return component;
              };

              const openActive = (type) => {
                const view = componentOf(type).getView();
                if (!view || typeof view.onActive !== 'function') {
                  throw new Error(type + ' has no active handler');
                }
                view.onActive();
              };

              const openStyleFile = (component, propertyName) => {
                editor.select(component);
                const sectors = editor.StyleManager.getSectors();
                const sectorModels = sectors.models || sectors;
                let foundFile = false;
                for (let i = 0; i < sectorModels.length; i++) {
                  if (typeof sectorModels[i].set === 'function') {
                    sectorModels[i].set('open', true);
                  }
                  const properties = sectorModels[i].getProperties();
                  const propertyModels = properties.models || properties;
                  for (let j = 0; j < propertyModels.length; j++) {
                    const children = typeof propertyModels[j].getProperties === 'function' ? propertyModels[j].getProperties() : null;
                    const candidates = [propertyModels[j]].concat((children && (children.models || children)) || []);
                    for (let k = 0; k < candidates.length; k++) {
                      if (candidates[k].get('property') !== propertyName || candidates[k].get('type') !== 'file') {
                        continue;
                      }
                      foundFile = true;
                      if (candidates[k].view && typeof candidates[k].view.openAssetManager === 'function') {
                        candidates[k].view.openAssetManager();
                        return;
                      }
                    }
                  }
                }
                if (!foundFile) {
                  throw new Error('The builder has no ' + propertyName + ' file control');
                }
                editor.Assets.open({
                  types: ['image'],
                  accept: 'image/*',
                  select(asset, complete) {
                    if (complete) {
                      editor.Assets.close();
                    }
                  },
                });
              };

              if (kind === 'image' || kind === 'mj-image') {
                openActive(kind);
                return;
              }
              if (kind === 'page-background') {
                const sections = wrapper.findType('section');
                openStyleFile(sections && sections.length ? sections[0] : wrapper, 'background-image');
                return;
              }
              if (kind === 'mj-section' || kind === 'mj-hero') {
                openStyleFile(componentOf(kind), 'background-url');
                return;
              }
              throw new Error('Unknown picker path ' + kind);
            })();
        JS);
        $I->waitForElementVisible($readySelector, AcceptanceTester::TIMEOUT);
    }

    protected function seeCustomPicker(AcceptanceTester $I): void
    {
        $I->waitForElementVisible('.lf-media-assets', AcceptanceTester::TIMEOUT);
        $I->dontSeeElement('.gjs-am-assets');
        $I->see('Import from URL', '.lf-media-assets__header');
        $I->see('Upload', '.lf-media-assets__header');
    }

    protected function closeCustomPicker(AcceptanceTester $I): void
    {
        $I->click('.lf-media-assets__close');
        $I->waitForElementNotVisible('.lf-media-assets', AcceptanceTester::TIMEOUT);
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
