# Plugin: Media & Assets (Basic) by Leuchtfeuer

Powerful Media & Assets Manager for organizing and picking images etc. in the Mautic builders.

## Overview / Purpose / Features

When the plugin is published, GrapesJS opens this plugin's image picker instead of the built-in media manager. The picker lists images from `media/images`, uploads new ones, and can import an image from a URL.

## Requirements

- Mautic **7.x**
- PHP **8.2+**

## Installation

The plugin folder must be named `LeuchtfeuerMediaAssetsBasicBundle`.

### Composer

```bash
composer require leuchtfeuer/mautic-mediaassetsbasic-bundle
```

Then clear the cache and reload plugins:

```bash
bin/console cache:clear
bin/console mautic:plugins:reload
```

### Manual Installation

1. Copy or clone this repository into `plugins/LeuchtfeuerMediaAssetsBasicBundle`.
2. Clear the cache: `bin/console cache:clear`
3. In Mautic go to **Plugins** → **Install/Upgrade Plugins**.
4. Open **Media & Assets (Basic) by Leuchtfeuer** and set **Published** to **Yes**.

## Configuration

**Settings → Plugins → Media & Assets (Basic) by Leuchtfeuer**

The config dialog only has the published toggle. There are no feature settings yet.

## Usage

Publish the plugin. There is no separate media page. The picker replaces the GrapesJS asset manager on every builder that Mautic opens, and only while the plugin is published. CKEditor and the elFinder media manager are unchanged.

It opens in both builders:

- **Landing pages** (Pages → Builder)
- **Emails** (Emails → Builder), both the MJML builder and the legacy HTML builder

Inside those builders it opens wherever GrapesJS asks for an image:

- Dropping or adding an Image block, including an MJML image
- Clicking an empty image, or double-clicking an image that is already in the canvas
- Choosing a background image, including a section's Decorations background and an MJML section or hero `background-url`

Double-click a tile to insert it. A single click only highlights the tile. One successful upload highlights that tile and does not insert it until you double-click. Images are stored in `media/images`, so they keep rendering after the plugin is unpublished.

## Troubleshooting

Make sure the plugin is installed and published. If it does not appear after adding the folder, run `bin/console cache:clear` and then `bin/console mautic:plugins:reload`.

## Development

The picker is a React app in `Assets/js/src`. Mautic loads the built files `Assets/dist/picker.js` and `Assets/dist/picker.css`, so those files are committed and must be rebuilt after a change to the source.

From this plugin directory:

```bash
npm ci
npm run build
```

`npm ci` installs the locked dependencies. `npm run build` runs Vite and writes both files into `Assets/dist`. The build clears that directory first.

Inside a Mautic checkout, run the same scripts with a prefix so they do not hit Mautic's own webpack build:

```bash
npm --prefix plugins/LeuchtfeuerMediaAssetsBasicBundle ci
npm --prefix plugins/LeuchtfeuerMediaAssetsBasicBundle run build
```

## Credits

Leuchtfeuer Digital Marketing GmbH

## Author

Leuchtfeuer Digital Marketing GmbH

Please raise issues on GitHub. For everything else, email mautic-plugins@leuchtfeuer.com.

## License

This plugin is licensed under the GPL v3 License.
