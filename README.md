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

Double-click a tile to insert it into the builder. One successful upload selects the new tile(s) and does not insert until you double-click. Images are stored in `media/images`, so they keep rendering after the plugin is unpublished.

### Selection and bulk move

| Action | What it does |
|--------|----------------|
| **Click** | Select only that tile. |
| **Ctrl+click** (Windows/Linux) / **Cmd+click** (macOS) | Add or remove that tile from the selection. |
| **Shift+click** | Select the range from the last clicked tile to this one (on the current page). |
| **Drag** a selected tile onto a **folder** in the sidebar | Move all selected images into that folder. Dragging an unselected tile moves only that image. |
| **Double-click** | Insert that one image into the builder (selection does not change what gets inserted). |

While a bulk move runs, the picker shows a progress bar (`Moving X of Y…`). Changing folder (sidebar or breadcrumb) clears the selection.

## File & folder index (design)

The filesystem remains the canonical, human-readable layout. The **picker and list API read only from the database index** — no `scandir` or Symfony Finder on the HTTP request path.

### Data source: database vs disk

| Context | Folder tree and file list |
|--------|---------------------------|
| **Picker, list API (HTTP)** | **Database only.** Recursive file counts in the tree also come from the database. |
| **Upload, move, create folder, delete** | Write to disk and **update the index in the same request**. |
| **Console index command** | Walks **disk** — fill gaps, reconcile, optional full reindex (`--all`). |

Manual disk changes (copying a file, `mkdir` outside the API) appear in the UI after the index command runs (default adds missing rows; `--all` performs a full reconcile).

### Index on upload and other operations

After a successful write to disk:

- **Upload:** upsert folders along the path, insert/upsert the file; metadata from `ImageInspector` (**mime, width, height, bytes**, plus **file mtime** for change detection).
- **Move:** update `relative_path` / folder relation in the database.
- **Create folder:** insert the folder row after `mkdir`.
- **Delete** file or folder (when exposed in the API): soft-delete in the database in the same request (see below).

Dimensions and technical metadata are **not** read during picker requests — they come from the index (populated on upload and by the command).

### Soft delete

- File missing on disk: **do not** remove the index row; set **`missing_since`** (nullable datetime).
- File back on disk: clear **`missing_since`** and refresh metadata (upload, move, or command).
- **Picker:** only rows with **`missing_since IS NULL`**. Soft-deleted files are not listed.

### Console index command

Command name: `leuchtfeuer:media:index-media`.

**Default (without `--all`):**

1. Walk the media root (include **empty** folders; **do not** follow symlinks).
2. **On disk, not in DB** → index (inspect including dimensions).
3. **In DB, missing on disk** → soft delete (`missing_since`).
4. **Both exist** but **mtime/bytes** differ (or stored dimensions missing) → re-index that file only.
5. **Folders on disk without a DB row** → insert (including empty folders).
6. Non-images, dotfiles, unreadable files → **skip** without aborting the run.

**`--all`:** full tree walk; re-inspect all files; align folder tree with disk; apply or clear soft delete according to disk state.

**`--dry-run`:** same logic as the active mode (default or `--all`) but **no database writes** — log a summary of changes only.

The command is a **safety net and initial backfill**, not the primary mechanism for day-to-day operation (that is the request path plus upload).

### Schema (minimum for the index story)

Unique key: **`relative_path`** (separate folder and file tables, or equivalent model).

**Folder:** `id`, `parent_id`, `relative_path` (unique), `name`, timestamps.

**File:** `id`, `folder_id`, `relative_path` (unique), `filename`, `mime`, `width`, `height`, `bytes`, `file_mtime`, `missing_since` (nullable), timestamps.

**Later (out of scope for the first index story):** UUID, checksum, usage references, path aliases, renditions root outside the browsable tree.

### Picker / API

- Files in a folder: from the database, same pagination as today.
- Folder tree: from the database; **`count` is recursive** (files in the whole subtree, as today).
- SVG in the picker: existing context rule (Referer / landing-page builder). SVG may exist on disk and in the index even when the picker filters it out. **Folder counts** follow the same rule: SVGs are included only in the landing-page picker; email builders exclude them so the badge matches the visible grid.

### Acceptance criteria (agreed)

1. After **upload**, the file is in the index with dimensions; the picker shows it **without** filesystem inspect on the request.
2. **Default command:** adds disk files/folders missing from the DB; soft-deletes index rows whose files are missing on disk; when nothing changed, **no logical state change**.
3. A **second default run** with unchanged disk and DB → no logical change to state or metadata.
4. **`--all`:** full reindex including soft delete; metadata may be re-read but stays the same if files did not change.
5. **`--dry-run`:** does not write to the database.
6. Picker list/tree: **database only**; functional tests use a **mocked repository** (no Finder/scandir).
7. Soft-deleted files **do not** appear in the picker.

### Out of scope (first index story)

- Event-based indexing, on-demand re-scan from the UI.
- Checksum, deduplication, upload duplicate resolution.
- Renditions, versions, usage, path aliases.
- UI changes beyond the data source (same folder/file listing as today).

### Performance

No formal performance acceptance criteria in the first story — expensive dimension reads happen on **upload** and during the **command**, not in the picker.

## Troubleshooting

Make sure the plugin is installed and published. If it does not appear after adding the folder, run `bin/console cache:clear` and then `bin/console mautic:plugins:reload`.

## Development

After pulling changes that add or alter Doctrine entities, reload plugins so Mautic creates or updates the index tables:

```bash
bin/console cache:clear
bin/console mautic:plugins:reload
```

### Media index command

`leuchtfeuer:media:index-media` walks `media/images` on disk and reconciles the database index. Day-to-day uploads and folder operations update the index in the same request; this command is mainly for initial migration, manual disk changes, and repair.

| Command | What it does |
|--------|----------------|
| `bin/console leuchtfeuer:media:index-media` | Default reconcile: index files/folders that exist on disk but not in the DB; soft-delete index rows missing on disk; re-inspect only when mtime/bytes (or missing dimensions) differ. |
| `bin/console leuchtfeuer:media:index-media --dry-run` | Same logic as the mode you combine it with (default or `--all`), but **no database writes** — prints a summary of what would change. |
| `bin/console leuchtfeuer:media:index-media --all` | Full reindex: re-inspect every file on disk, align the folder tree, and apply or clear soft delete according to disk state. |
| `bin/console leuchtfeuer:media:index-media --all --dry-run` | Preview a full `--all` run without writing to the database. |

See [Console index command](#console-index-command) above for the full reconcile rules.

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
