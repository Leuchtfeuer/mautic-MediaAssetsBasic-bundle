<?php

declare(strict_types=1);

require_once __DIR__.'/Support/MediaAssetsAcceptanceTrait.php';

/**
 * The published plugin replaces the GrapesJS asset manager in every builder,
 * not only when an image block is opened on a landing page.
 *
 * CKEditor and elFinder do not use that asset manager, so they stay unchanged.
 */
final class MediaAssetsReplacementCest
{
    use MediaAssetsAcceptanceTrait;

    public function _before(AcceptanceTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->ensureMediaAssetsPluginEnabled($I);
    }

    public function pageImageOpensTheCustomPicker(AcceptanceTester $I): void
    {
        $this->openPageBuilder($I);
        $this->openPicker($I, 'image');
        $this->seeCustomPicker($I);
    }

    public function pageSectionBackgroundOpensTheCustomPicker(AcceptanceTester $I): void
    {
        $this->openPageBuilder($I);
        $this->openPicker($I, 'page-background');
        $this->seeCustomPicker($I);
    }

    public function emailImageOpensTheCustomPicker(AcceptanceTester $I): void
    {
        $this->openEmailBuilder($I);
        $this->openPicker($I, $this->hasComponentType($I, 'mj-image') ? 'mj-image' : 'image');
        $this->seeCustomPicker($I);
    }

    public function emailBackgroundOpensTheCustomPicker(AcceptanceTester $I): void
    {
        $this->openEmailBuilder($I);
        if ($this->hasComponentType($I, 'mj-hero')) {
            $this->openPicker($I, 'mj-hero');
            $this->seeCustomPicker($I);
            $this->closeCustomPicker($I);
            $this->openPicker($I, 'mj-section');
            $this->seeCustomPicker($I);

            return;
        }

        $this->openPicker($I, 'page-background');
        $this->seeCustomPicker($I);
    }

    public function unpublishedEmailKeepsTheBuiltInAssetManager(AcceptanceTester $I): void
    {
        $this->ensureMediaAssetsPluginEnabled($I, false);
        $this->openEmailBuilder($I);
        $this->openPicker($I, $this->hasComponentType($I, 'mj-image') ? 'mj-image' : 'image', '.gjs-am-assets');
        $I->dontSeeElement('.lf-media-assets');
    }

    private function hasComponentType(AcceptanceTester $I, string $type): bool
    {
        $typeJson = json_encode($type, JSON_THROW_ON_ERROR);
        $found    = $I->executeJS('return !!window.__lfMediaAssetsEditor.DomComponents.getType('.$typeJson.');');

        return true === $found || 1 === $found || 'true' === $found;
    }
}
