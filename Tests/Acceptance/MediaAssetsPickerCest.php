<?php

declare(strict_types=1);

require_once __DIR__.'/Support/MediaAssetsAcceptanceTrait.php';

/**
 * E2E: GrapesJS opens this plugin's image picker when the integration is published.
 */
final class MediaAssetsPickerCest
{
    use MediaAssetsAcceptanceTrait;

    private string $seedName = '';

    private string $uploadName = '';

    public function _before(AcceptanceTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->ensureMediaAssetsPluginEnabled($I);
    }

    public function _after(AcceptanceTester $I): void
    {
        if ('' !== $this->seedName) {
            $this->deleteImages($I, pathinfo($this->seedName, PATHINFO_FILENAME));
        }
        if ('' !== $this->uploadName) {
            $this->deleteImages($I, pathinfo($this->uploadName, PATHINFO_FILENAME));
            $upload = codecept_data_dir($this->uploadName);
            if (is_file($upload)) {
                unlink($upload);
            }
        }
    }

    public function doubleClickInsertsAListedImage(AcceptanceTester $I): void
    {
        $this->seedName = 'lf-media-seed-'.uniqid().'.png';
        $this->writeImage($I, $this->seedName);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->waitForText($this->seedName, AcceptanceTester::TIMEOUT, '.lf-media-assets__grid');
        $I->doubleClick('//button[contains(@class,"lf-media-assets__tile") and contains(., "'.$this->seedName.'")]');

        $I->waitForElementNotVisible('.lf-media-assets', AcceptanceTester::TIMEOUT);
        $I->switchToIFrame('iframe.gjs-frame');
        $I->waitForElement('img[src*="'.$this->seedName.'"]', AcceptanceTester::TIMEOUT);
        $I->switchToIFrame();
    }

    public function uploadSelectsTheTileUntilDoubleClick(AcceptanceTester $I): void
    {
        $this->uploadName = 'lf-media-upload-'.uniqid().'.png';
        $png              = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $I->assertIsString($png);
        $dataDir = codecept_data_dir();
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('The Codeception data directory could not be created.');
        }
        file_put_contents($dataDir.$this->uploadName, $png);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->executeJS("const input = document.querySelector('#lf-media-assets-file'); input.hidden = false; input.style.display = 'block';");
        $I->attachFile('#lf-media-assets-file', $this->uploadName);

        $I->waitForElementVisible('.lf-media-assets__tile.is-selected', AcceptanceTester::TIMEOUT);
        $I->see($this->uploadName, '.lf-media-assets__tile.is-selected');
        $I->seeElement('.lf-media-assets');

        $I->switchToIFrame('iframe.gjs-frame');
        $I->dontSeeElement('img[src*="'.$this->uploadName.'"]');
        $I->switchToIFrame();

        $I->doubleClick('.lf-media-assets__tile.is-selected');
        $I->waitForElementNotVisible('.lf-media-assets', AcceptanceTester::TIMEOUT);
        $I->switchToIFrame('iframe.gjs-frame');
        $I->waitForElement('img[src*="'.$this->uploadName.'"]', AcceptanceTester::TIMEOUT);
        $I->switchToIFrame();
    }

    public function unpublishedPluginKeepsTheBuiltInAssetManager(AcceptanceTester $I): void
    {
        $this->ensureMediaAssetsPluginEnabled($I, false);
        $this->openPageBuilder($I);
        $this->openBuiltInAssetManager($I);
        $I->dontSeeElement('.lf-media-assets');
    }
}
