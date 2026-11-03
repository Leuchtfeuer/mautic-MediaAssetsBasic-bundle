<?php

declare(strict_types=1);

require_once __DIR__.'/Support/MediaAssetsAcceptanceTrait.php';

/**
 * E2E: drag-and-drop upload into the picker body.
 */
final class MediaAssetsDragDropUploadCest
{
    use MediaAssetsAcceptanceTrait;

    private string $uploadName = '';

    public function _before(AcceptanceTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->ensureMediaAssetsPluginEnabled($I);
    }

    public function _after(AcceptanceTester $I): void
    {
        if ('' !== $this->uploadName) {
            $this->deleteImages($I, pathinfo($this->uploadName, PATHINFO_FILENAME));
            $upload = codecept_data_dir($this->uploadName);
            if (is_file($upload)) {
                unlink($upload);
            }
        }
    }

    public function dragAndDropUploadSelectsTheTile(AcceptanceTester $I): void
    {
        $this->uploadName = 'lf-media-dnd-'.bin2hex(random_bytes(4)).'.png';
        $png              = base64_decode(self::PNG, true);
        $I->assertIsString($png);
        $dataDir = codecept_data_dir();
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('The Codeception data directory could not be created.');
        }
        file_put_contents($dataDir.$this->uploadName, $png);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->waitForElementVisible('.lf-media-assets__body', AcceptanceTester::TIMEOUT);
        $I->dontSee('Images could not be loaded.', '.lf-media-assets');

        $this->dropFileOntoPickerBody($I, $this->uploadName, 'image/png');

        $I->waitForElementVisible('.lf-media-assets__tile.is-selected', AcceptanceTester::TIMEOUT);
        $I->see($this->uploadName, '.lf-media-assets__tile.is-selected');
        // Dialog must stay open after upload (even if a list refresh warns).
        $I->seeElement('.lf-media-assets');
        $I->seeElement('#lf-media-assets-basic');
        $I->dontSeeElement('.gjs-mdl-title');
    }

    public function dragAndDropUploadMultipleSelectsAllTiles(AcceptanceTester $I): void
    {
        $first            = 'lf-media-dnd-a-'.bin2hex(random_bytes(3)).'.png';
        $second           = 'lf-media-dnd-b-'.bin2hex(random_bytes(3)).'.png';
        $this->uploadName = $first; // cleanup uses prefix from first; also delete second below
        $dataDir          = codecept_data_dir();
        if (!is_dir($dataDir) && !mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
            throw new RuntimeException('The Codeception data directory could not be created.');
        }
        // Distinct pixels avoid checksum_duplicate between the two drops.
        $this->writeUniquePngFixture($I, $dataDir.$first, 1, 2, 3);
        $this->writeUniquePngFixture($I, $dataDir.$second, 4, 5, 6);

        $this->openPageBuilder($I);
        $this->openCustomImagePicker($I);
        $I->waitForElementVisible('.lf-media-assets__body', AcceptanceTester::TIMEOUT);

        $this->dropFilesOntoPickerBody($I, [
            ['name' => $first, 'mime' => 'image/png'],
            ['name' => $second, 'mime' => 'image/png'],
        ]);

        $I->waitForText($first, AcceptanceTester::TIMEOUT, '.lf-media-assets__grid');
        $I->waitForText($second, AcceptanceTester::TIMEOUT, '.lf-media-assets__grid');
        $I->seeElement('//button[contains(@class,"lf-media-assets__tile") and contains(@class,"is-selected") and contains(., "'.$first.'")]');
        $I->seeElement('//button[contains(@class,"lf-media-assets__tile") and contains(@class,"is-selected") and contains(., "'.$second.'")]');
        $I->dontSee('Images could not be loaded.', '.lf-media-assets');

        $this->deleteImages($I, pathinfo($second, PATHINFO_FILENAME));
        $path = codecept_data_dir($second);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function writeUniquePngFixture(AcceptanceTester $I, string $path, int $r, int $g, int $b): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD is required for drag-and-drop acceptance fixtures.');
        }
        $image = imagecreatetruecolor(8, 8);
        $I->assertNotFalse($image);
        $color = imagecolorallocate($image, $r, $g, $b);
        $I->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, 8, 8, $color);
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function dropFileOntoPickerBody(AcceptanceTester $I, string $filename, string $mime): void
    {
        $this->dropFilesOntoPickerBody($I, [['name' => $filename, 'mime' => $mime]]);
    }

    /**
     * @param list<array{name: string, mime: string}> $files
     */
    private function dropFilesOntoPickerBody(AcceptanceTester $I, array $files): void
    {
        $items = [];
        foreach ($files as $file) {
            $path = codecept_data_dir($file['name']);
            $I->assertFileExists($path);
            $binary = file_get_contents($path);
            $I->assertIsString($binary);
            $items[] = [
                'name' => $file['name'],
                'mime' => $file['mime'],
                'b64'  => base64_encode($binary),
            ];
        }

        $payload = json_encode($items, JSON_THROW_ON_ERROR);
        $I->executeJS(<<<JS
            const files = {$payload}.map((item) => {
              const binary = atob(item.b64);
              const bytes = new Uint8Array(binary.length);
              for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
              }
              return new File([bytes], item.name, { type: item.mime });
            });
            const dt = new DataTransfer();
            files.forEach((file) => dt.items.add(file));
            const body = document.querySelector('.lf-media-assets__body');
            if (!body) {
              throw new Error('Picker body not found');
            }
            const fire = (type) => {
              const event = new Event(type, { bubbles: true, cancelable: true });
              Object.defineProperty(event, 'dataTransfer', { value: dt });
              body.dispatchEvent(event);
            };
            fire('dragenter');
            fire('dragover');
            fire('drop');
        JS);
    }
}
