<?php

declare(strict_types=1);

require_once __DIR__.'/Support/MediaAssetsAcceptanceTrait.php';

/**
 * E2E: picker thumbnails / placeholders and builder insert of the standard rendition.
 */
final class MediaAssetsRenditionCest
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

    public function pickerShowsPlaceholderWhenThumbIsMissing(AcceptanceTester $I): void
    {
        $this->seedName = 'lf-media-placeholder-'.bin2hex(random_bytes(4)).'.png';
        $this->writeImage($I, $this->seedName);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->waitForText($this->seedName, AcceptanceTester::TIMEOUT, '.lf-media-assets__grid');

        $tile = '//button[contains(@class,"lf-media-assets__tile") and contains(., "'.$this->seedName.'")]';
        $I->seeElement($tile.'//span[contains(@class,"lf-media-assets__thumb-placeholder")]');
        $I->dontSeeElement($tile.'//img');
    }

    public function pickerTileShowsThumbAndInsertUsesStandard(AcceptanceTester $I): void
    {
        $this->seedName = 'lf-media-rendition-'.bin2hex(random_bytes(4)).'.jpg';
        $this->writeLargeImageWithRenditions($I, $this->seedName);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->waitForText($this->seedName, AcceptanceTester::TIMEOUT, '.lf-media-assets__grid');

        $tile = '//button[contains(@class,"lf-media-assets__tile") and contains(., "'.$this->seedName.'")]';
        $I->seeElement($tile.'//img[contains(@src,"_renditions/") and contains(@src,"_400.")]');
        $I->dontSeeElement($tile.'//span[contains(@class,"lf-media-assets__thumb-placeholder")]');

        $I->doubleClick($tile);
        $I->waitForElementNotVisible('.lf-media-assets', AcceptanceTester::TIMEOUT);
        $I->switchToIFrame('iframe.gjs-frame');
        $I->waitForElement('img[src*="_renditions/"][src*="_1600."]', AcceptanceTester::TIMEOUT);
        $I->dontSeeElement('img[src$="/'.$this->seedName.'"]');
        $I->switchToIFrame();
    }

    public function uploadCreatesThumbPreviewInThePicker(AcceptanceTester $I): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD is required for the large upload acceptance fixture.');
        }

        $this->uploadName = 'lf-media-upload-rendition-'.bin2hex(random_bytes(4)).'.jpg';
        $dataDir          = codecept_data_dir();
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('The Codeception data directory could not be created.');
        }

        $image = imagecreatetruecolor(1800, 900);
        $I->assertNotFalse($image);
        $background = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        $I->assertNotFalse($background);
        imagefilledrectangle($image, 0, 0, 1800, 900, $background);
        // Extra unique pixels avoid checksum_duplicate conflicts across runs.
        for ($x = 0; $x < 80; ++$x) {
            $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            $I->assertNotFalse($color);
            imagesetpixel($image, $x, random_int(0, 899), $color);
        }
        imagejpeg($image, $dataDir.$this->uploadName, 90);
        imagedestroy($image);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->executeJS("const input = document.querySelector('#lf-media-assets-file'); input.hidden = false; input.style.display = 'block';");
        $I->attachFile('#lf-media-assets-file', $this->uploadName);

        $I->waitForJS(<<<'JS'
            return !!(
              document.querySelector('.lf-media-assets__tile.is-selected')
              || document.querySelector('.lf-media-assets__conflict-actions')
            );
        JS, AcceptanceTester::TIMEOUT);
        if ($I->executeJS('return !!document.querySelector(".lf-media-assets__conflict-actions");')) {
            $I->click('//div[contains(@class,"lf-media-assets__conflict-actions")]/button[contains(., "Keep both")]');
        }

        $I->waitForElementVisible('.lf-media-assets__tile.is-selected img[src*="_renditions/"][src*="_400."]', AcceptanceTester::TIMEOUT);
        $I->dontSeeElement('.lf-media-assets__tile.is-selected .lf-media-assets__thumb-placeholder');
    }
}
