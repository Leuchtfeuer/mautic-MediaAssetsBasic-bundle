# Plugin: Media & Assets (Basic) by Leuchtfeuer

Powerful Media & Assets Manager for organizing and picking images etc. in the Mautic builders.

## Overview / Purpose / Features

The plugin can be installed and published. The media picker is not implemented yet.

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

Nothing to use yet. Publishing the plugin only registers it.

## Troubleshooting

Make sure the plugin is installed and published. If it does not appear after adding the folder, run `bin/console cache:clear` and then `bin/console mautic:plugins:reload`.

## Credits

Leuchtfeuer Digital Marketing GmbH

## Author

Leuchtfeuer Digital Marketing GmbH

Please raise issues on GitHub. For everything else, email mautic-plugins@leuchtfeuer.com.

## License

This plugin is licensed under the GPL v3 License.
