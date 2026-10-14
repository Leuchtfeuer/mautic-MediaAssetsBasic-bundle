import { useEffect, useRef, useState } from 'react';
import { createFolder, importUrl, listFolders, listImages, moveImage, uploadFile } from './api';
import type { AssetSession, FileProgress, FolderNode, MediaImage, PickerConfig, PickerMessages } from './types';
import './picker.css';

const fallbackMessages: PickerMessages = {
  images: 'Images',
  importFromUrl: 'Import from URL',
  upload: 'Upload',
  close: 'Close',
  fetch: 'Fetch',
  urlPlaceholder: 'https://example.com/hero.jpg',
  loadFailed: 'Images could not be loaded.',
  importFailed: 'The image could not be imported.',
  empty: 'No images yet. Drop images here or use Upload.',
  drop: 'Drop to upload',
  itemsPerPage: 'Items per page',
  range: '%from%–%to% of %total% items',
  page: 'Page %page% of %pages%',
  byte: 'B',
  kilobyte: 'KB',
  megabyte: 'MB',
  uploadFailed: 'The file could not be uploaded.',
  folders: 'Folders',
  home: 'Home',
  newFolder: 'New folder',
  folderNamePlaceholder: 'Folder name',
  createFolder: 'Create',
  folderCreateFailed: 'The folder could not be created.',
  expandFolder: 'Expand folder',
  collapseFolder: 'Collapse folder',
  moveFailed: 'The image could not be moved.',
  moving: 'Moving %done% of %total%…',
};

function text(config: PickerConfig): PickerMessages {
  return { ...fallbackMessages, ...config.messages };
}

function fill(template: string, values: Record<string, string | number>): string {
  return Object.entries(values).reduce(
    (result, [key, value]) => result.split(`%${key}%`).join(String(value)),
    template,
  );
}

function uploadAccept(allowSvg: boolean | undefined): string {
  const raster = 'image/jpeg,image/png,image/gif,image/webp';

  return allowSvg ? `${raster},image/svg+xml` : raster;
}

function formatBytes(bytes: number, messages: PickerMessages): string {
  if (bytes < 1024) {
    return `${bytes} ${messages.byte}`;
  }
  if (bytes < 1024 * 1024) {
    return `${Math.round(bytes / 1024)} ${messages.kilobyte}`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} ${messages.megabyte}`;
}

function breadcrumb(path: string, home: string): string[] {
  if ('' === path) {
    return [home];
  }

  return [home, ...path.split('/')];
}

function ancestorPaths(folderPath: string): string[] {
  if ('' === folderPath) {
    return [''];
  }

  const parts = folderPath.split('/');
  const paths = [''];
  for (let index = 0; index < parts.length; index += 1) {
    paths.push(parts.slice(0, index + 1).join('/'));
  }

  return paths;
}

function parentFolderPath(imagePath: string): string {
  const slash = imagePath.lastIndexOf('/');

  return slash < 0 ? '' : imagePath.slice(0, slash);
}

function DismissibleAlert({
  message,
  onDismiss,
  closeLabel,
  title,
}: {
  message: string;
  onDismiss: () => void;
  closeLabel: string;
  title?: string;
}): JSX.Element {
  return (
    <div className="lf-media-assets__alert" role="alert">
      <div className="lf-media-assets__alert-content">
        {title ? <strong>{title}</strong> : null}
        <p className="lf-media-assets__error">{message}</p>
      </div>
      <button type="button" className="lf-media-assets__alert-dismiss" aria-label={closeLabel} onClick={onDismiss}>
        ×
      </button>
    </div>
  );
}

function parseDraggedImagePaths(raw: string): string[] {
  if ('' === raw) {
    return [];
  }
  try {
    const parsed: unknown = JSON.parse(raw);
    if (Array.isArray(parsed)) {
      return parsed.filter((value): value is string => typeof value === 'string' && '' !== value);
    }
  } catch {
    // Legacy / plain single path from text/plain.
  }

  return [raw];
}

function FolderRows({
  node,
  depth,
  selected,
  expanded,
  messages,
  draggingImagePaths,
  dropTargetFolder,
  onSelect,
  onToggle,
  onDropTarget,
  onMoveToFolder,
}: {
  node: FolderNode;
  depth: number;
  selected: string;
  expanded: Set<string>;
  messages: PickerMessages;
  draggingImagePaths: string[];
  dropTargetFolder: string | null;
  onSelect: (path: string) => void;
  onToggle: (path: string) => void;
  onDropTarget: (path: string | null) => void;
  onMoveToFolder: (folderPath: string, imagePaths: string[]) => void;
}): JSX.Element {
  const hasChildren = node.children.length > 0;
  const isExpanded = expanded.has(node.path);
  const canDrop = draggingImagePaths.some((imagePath) => parentFolderPath(imagePath) !== node.path);
  const isDropTarget = canDrop && dropTargetFolder === node.path;

  return (
    <>
      <div
        className={isDropTarget ? 'lf-media-assets__folder-row is-drop-target' : 'lf-media-assets__folder-row'}
        style={{ paddingLeft: `${4 + depth * 16}px` }}
        onDragOver={(event) => {
          if (!canDrop) {
            return;
          }
          event.preventDefault();
          event.dataTransfer.dropEffect = 'move';
          onDropTarget(node.path);
        }}
        onDragLeave={() => {
          if (dropTargetFolder === node.path) {
            onDropTarget(null);
          }
        }}
        onDrop={(event) => {
          const paths = draggingImagePaths.length > 0
            ? draggingImagePaths
            : parseDraggedImagePaths(event.dataTransfer.getData('text/plain'));
          const movable = paths.filter((imagePath) => parentFolderPath(imagePath) !== node.path);
          if (0 === movable.length) {
            return;
          }
          event.preventDefault();
          onDropTarget(null);
          onMoveToFolder(node.path, movable);
        }}
      >
        {hasChildren ? (
          <button
            type="button"
            className={isExpanded ? 'lf-media-assets__folder-toggle is-open' : 'lf-media-assets__folder-toggle'}
            aria-expanded={isExpanded}
            aria-label={isExpanded ? messages.collapseFolder : messages.expandFolder}
            onClick={() => onToggle(node.path)}
          />
        ) : (
          <span className="lf-media-assets__folder-toggle-slot" aria-hidden="true" />
        )}
        <button
          type="button"
          className={selected === node.path ? 'lf-media-assets__folder is-selected' : 'lf-media-assets__folder'}
          onClick={() => onSelect(node.path)}
        >
          <span className="lf-media-assets__folder-label">
            <span className="lf-media-assets__folder-icon" aria-hidden="true" />
            <span>{node.name}</span>
          </span>
          <span className="lf-media-assets__folder-count">{node.count}</span>
        </button>
      </div>
      {hasChildren && isExpanded && node.children.map((child) => (
        <FolderRows
          key={child.path}
          node={child}
          depth={depth + 1}
          selected={selected}
          expanded={expanded}
          messages={messages}
          draggingImagePaths={draggingImagePaths}
          dropTargetFolder={dropTargetFolder}
          onSelect={onSelect}
          onToggle={onToggle}
          onDropTarget={onDropTarget}
          onMoveToFolder={onMoveToFolder}
        />
      ))}
    </>
  );
}

export function Picker({ config, session }: { config: PickerConfig; session: AssetSession }): JSX.Element {
  const messages = text(config);
  const [folder, setFolder] = useState<FolderNode | null>(null);
  const [path, setPath] = useState('');
  const [images, setImages] = useState<MediaImage[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [limit, setLimit] = useState(24);
  const [selectedPaths, setSelectedPaths] = useState<Set<string>>(() => new Set());
  const [selectionAnchor, setSelectionAnchor] = useState<string | null>(null);
  const [urlOpen, setUrlOpen] = useState(false);
  const [url, setUrl] = useState('');
  const urlInput = useRef<HTMLInputElement>(null);
  const folderInput = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState<FileProgress[]>([]);
  const [error, setError] = useState('');
  const [creating, setCreating] = useState(false);
  const [folderName, setFolderName] = useState('');
  const [folderError, setFolderError] = useState('');
  const [treeVersion, setTreeVersion] = useState(0);
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set(['']));
  const [draggingImagePaths, setDraggingImagePaths] = useState<string[]>([]);
  const [dropTargetFolder, setDropTargetFolder] = useState<string | null>(null);

  useEffect(() => {
    setExpanded((current) => {
      const next = new Set(current);
      ancestorPaths(path).forEach((folderPath) => next.add(folderPath));

      return next;
    });
  }, [path, treeVersion]);

  useEffect(() => {
    let active = true;
    listFolders(config)
      .then((tree) => {
        if (active) {
          setFolder(tree);
        }
      })
      .catch((reason: unknown) => {
        if (active) {
          setError(reason instanceof Error ? reason.message : messages.loadFailed);
        }
      });

    return () => {
      active = false;
    };
  }, [config, treeVersion, messages.loadFailed]);

  useEffect(() => {
    let active = true;
    listImages(config, page, limit, path)
      .then((result) => {
        if (!active) {
          return;
        }
        setImages(result.images);
        setTotal(result.total);
        setError('');
      })
      .catch((reason: unknown) => {
        if (active) {
          setError(reason instanceof Error ? reason.message : messages.loadFailed);
        }
      });

    return () => {
      active = false;
    };
  }, [config, page, limit, path, messages.loadFailed]);

  const pages = Math.max(1, Math.ceil(total / limit));
  const from = total === 0 ? 0 : (page - 1) * limit + 1;
  const to = Math.min(page * limit, total);
  const crumbs = breadcrumb(path, messages.home);
  const crumbPaths = ancestorPaths(path);

  async function refreshImages(nextPage = 1): Promise<MediaImage[]> {
    setPage(nextPage);
    const refreshed = await listImages(config, nextPage, limit, path);
    setImages(refreshed.images);
    setTotal(refreshed.total);

    return refreshed.images;
  }

  async function sendFiles(files: File[]): Promise<void> {
    const rows = files.map((file) => ({ id: `${file.name}-${file.size}-${file.lastModified}`, name: file.name, progress: 0 }));
    setProgress(rows);
    const stored: MediaImage[] = [];
    for (const file of files) {
      const id = `${file.name}-${file.size}-${file.lastModified}`;
      const result = await uploadFile(config, file, path, (value) => {
        setProgress((current) => current.map((row) => (row.id === id ? { ...row, progress: value } : row)));
      });
      setProgress((current) => current.map((row) => (row.id === id ? { ...row, progress: 1, error: result.error } : row)));
      if (result.image) {
        stored.push(result.image);
      }
    }
    setProgress((current) => current.filter((row) => row.error));
    if (stored.length > 0) {
      await refreshImages(1);
      setTreeVersion((value) => value + 1);
      setSelectedPaths(new Set(stored.map((image) => image.path)));
      setSelectionAnchor(stored[stored.length - 1].path);
    }
  }

  async function fetchUrl(): Promise<void> {
    const id = `url-${url}`;
    setProgress((current) => [...current, { id, name: url, progress: 0.4 }]);
    try {
      const image = await importUrl(config, url, path);
      setProgress((current) => current.filter((row) => row.id !== id));
      await refreshImages(1);
      setTreeVersion((value) => value + 1);
      setSelectedPaths(new Set([image.path]));
      setSelectionAnchor(image.path);
      setUrl('');
      setUrlOpen(false);
    } catch (reason: unknown) {
      const message = reason instanceof Error ? reason.message : messages.importFailed;
      setProgress((current) => current.map((row) => (row.id === id ? { ...row, error: message } : row)));
    }
  }

  async function submitFolder(): Promise<void> {
    if ('' === folderName.trim()) {
      setFolderError(messages.folderNamePlaceholder);
      return;
    }
    try {
      const created = await createFolder(config, path, folderName.trim());
      setFolderName('');
      setCreating(false);
      setFolderError('');
      setTreeVersion((value) => value + 1);
      setPath(created.path);
      setPage(1);
    } catch (reason: unknown) {
      setFolderError(reason instanceof Error ? reason.message : messages.folderCreateFailed);
    }
  }

  useEffect(() => {
    if (urlOpen) {
      urlInput.current?.focus();
    }
  }, [urlOpen]);

  useEffect(() => {
    if (creating) {
      folderInput.current?.focus();
    }
  }, [creating]);

  function onFetch(): void {
    setUrlOpen((open) => !open);
  }

  function selectFolder(next: string): void {
    setPath(next);
    setPage(1);
    setSelectedPaths(new Set());
    setSelectionAnchor(null);
  }

  function toggleFolder(folderPath: string): void {
    setExpanded((current) => {
      const next = new Set(current);
      if (next.has(folderPath)) {
        next.delete(folderPath);
      } else {
        next.add(folderPath);
      }

      return next;
    });
  }

  function selectImage(image: MediaImage, index: number, event: { metaKey: boolean; ctrlKey: boolean; shiftKey: boolean }): void {
    const additive = event.metaKey || event.ctrlKey;
    if (additive) {
      setSelectedPaths((current) => {
        const next = new Set(current);
        if (next.has(image.path)) {
          next.delete(image.path);
        } else {
          next.add(image.path);
        }

        return next;
      });
      setSelectionAnchor(image.path);

      return;
    }

    if (event.shiftKey && null !== selectionAnchor) {
      const anchorIndex = images.findIndex((row) => row.path === selectionAnchor);
      if (anchorIndex >= 0) {
        const from = Math.min(anchorIndex, index);
        const to = Math.max(anchorIndex, index);
        setSelectedPaths(new Set(images.slice(from, to + 1).map((row) => row.path)));

        return;
      }
    }

    setSelectedPaths(new Set([image.path]));
    setSelectionAnchor(image.path);
  }

  async function moveImagesToFolder(folderPath: string, imagePaths: string[]): Promise<void> {
    const toMove = imagePaths.filter((imagePath) => parentFolderPath(imagePath) !== folderPath);
    if (0 === toMove.length) {
      setDraggingImagePaths([]);
      setDropTargetFolder(null);

      return;
    }

    const moveId = `move-${Date.now()}`;
    const total = toMove.length;
    setProgress((current) => [
      ...current,
      { id: moveId, name: fill(messages.moving, { done: 0, total }), progress: 0 },
    ]);
    setDraggingImagePaths([]);
    setDropTargetFolder(null);

    let done = 0;
    try {
      for (const imagePath of toMove) {
        await moveImage(config, imagePath, folderPath);
        done += 1;
        setProgress((current) => current.map((row) => (
          row.id === moveId
            ? { ...row, name: fill(messages.moving, { done, total }), progress: done / total }
            : row
        )));
      }
      setProgress((current) => current.filter((row) => row.id !== moveId));
      setTreeVersion((value) => value + 1);
      setError('');
      setSelectedPaths((current) => {
        const next = new Set(current);
        toMove.forEach((imagePath) => next.delete(imagePath));

        return next;
      });
      if (toMove.some((imagePath) => parentFolderPath(imagePath) === path) || path === folderPath) {
        await refreshImages(page);
      }
    } catch (reason: unknown) {
      const message = reason instanceof Error ? reason.message : messages.moveFailed;
      setProgress((current) => current.map((row) => (
        row.id === moveId
          ? { ...row, name: fill(messages.moving, { done, total }), progress: total > 0 ? done / total : 0, error: message }
          : row
      )));
      setTreeVersion((value) => value + 1);
      if (done > 0 && toMove.some((imagePath) => parentFolderPath(imagePath) === path)) {
        await refreshImages(page);
      }
    }
  }

  function dismissProgress(id: string): void {
    setProgress((current) => current.filter((row) => row.id !== id));
  }

  return (
    <div className="lf-media-assets" role="presentation">
      <div className="lf-media-assets__dialog" role="dialog" aria-label={messages.images}>
        <header className="lf-media-assets__header">
          <h2>{messages.images}</h2>
          <div className="lf-media-assets__actions">
            <button type="button" className="lf-media-assets__fetch" onClick={onFetch}>{messages.importFromUrl}</button>
            <button type="button" onClick={() => document.getElementById('lf-media-assets-file')?.click()}>{messages.upload}</button>
            <button type="button" className="lf-media-assets__close" aria-label={messages.close} onClick={session.close}>×</button>
          </div>
          <input
            id="lf-media-assets-file"
            className="lf-media-assets__file"
            type="file"
            accept={uploadAccept(config.allowSvg)}
            multiple
            onChange={(event) => {
              const files = Array.from(event.target.files ?? []);
              event.target.value = '';
              void sendFiles(files);
            }}
          />
        </header>
        {urlOpen && (
          <div className="lf-media-assets__url">
            <input
              ref={urlInput}
              value={url}
              placeholder={messages.urlPlaceholder}
              onChange={(event) => setUrl(event.target.value)}
              onKeyDown={(event) => {
                if ('Enter' === event.key && '' !== url.trim()) {
                  void fetchUrl();
                }
              }}
            />
            <button type="button" onClick={() => void fetchUrl()}>{messages.fetch}</button>
          </div>
        )}
        {progress.length > 0 && (
          <div className="lf-media-assets__progress">
            {progress.map((row) => (
              row.error ? (
                <DismissibleAlert
                  key={row.id}
                  title={row.name}
                  message={row.error}
                  closeLabel={messages.close}
                  onDismiss={() => dismissProgress(row.id)}
                />
              ) : (
                <div className="lf-media-assets__progress-row" key={row.id}>
                  <strong>{row.name}</strong>
                  <div className="lf-media-assets__bar"><span style={{ width: `${Math.round(row.progress * 100)}%` }} /></div>
                </div>
              )
            ))}
          </div>
        )}
        <div className="lf-media-assets__main">
          <aside className="lf-media-assets__sidebar">
            <div className="lf-media-assets__folders-head">
              <span>{messages.folders}</span>
              <button
                type="button"
                className="lf-media-assets__folder-add"
                aria-label={messages.newFolder}
                onClick={() => {
                  setCreating(true);
                  setFolderError('');
                }}
              >
                +
              </button>
            </div>
            {creating && (
              <div className="lf-media-assets__folder-create">
                <input
                  ref={folderInput}
                  value={folderName}
                  placeholder={messages.folderNamePlaceholder}
                  onChange={(event) => setFolderName(event.target.value)}
                  onKeyDown={(event) => {
                    if ('Enter' === event.key) {
                      void submitFolder();
                    }
                    if ('Escape' === event.key) {
                      setCreating(false);
                      setFolderName('');
                      setFolderError('');
                    }
                  }}
                />
                <button type="button" onClick={() => void submitFolder()}>{messages.createFolder}</button>
                {folderError && (
                  <DismissibleAlert
                    message={folderError}
                    closeLabel={messages.close}
                    onDismiss={() => setFolderError('')}
                  />
                )}
              </div>
            )}
            <div className="lf-media-assets__folders">
              {folder && (
                <FolderRows
                  node={folder}
                  depth={0}
                  selected={path}
                  expanded={expanded}
                  messages={messages}
                  draggingImagePaths={draggingImagePaths}
                  dropTargetFolder={dropTargetFolder}
                  onSelect={selectFolder}
                  onToggle={toggleFolder}
                  onDropTarget={setDropTargetFolder}
                  onMoveToFolder={(folderPath, imagePaths) => void moveImagesToFolder(folderPath, imagePaths)}
                />
              )}
            </div>
          </aside>
          <div
            className="lf-media-assets__body"
            onDragOver={(event) => {
              if (draggingImagePaths.length > 0 || !Array.from(event.dataTransfer.types).includes('Files')) {
                return;
              }
              event.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(event) => {
              event.preventDefault();
              setDragging(false);
              if (draggingImagePaths.length > 0 || 0 === event.dataTransfer.files.length) {
                return;
              }
              void sendFiles(Array.from(event.dataTransfer.files));
            }}
          >
            <div className="lf-media-assets__scroll">
              <nav className="lf-media-assets__breadcrumb" aria-label={crumbs.join(' / ')}>
                {crumbs.map((crumb, index) => {
                  const crumbPath = crumbPaths[index] ?? '';
                  const isCurrent = index === crumbs.length - 1;

                  return (
                    <span key={`${crumbPath}-${index}`}>
                      {index > 0 && <span className="lf-media-assets__breadcrumb-sep"> / </span>}
                      {isCurrent ? (
                        <span className="is-current" aria-current="page">{crumb}</span>
                      ) : (
                        <button
                          type="button"
                          className="lf-media-assets__breadcrumb-link"
                          onClick={() => selectFolder(crumbPath)}
                        >
                          {crumb}
                        </button>
                      )}
                    </span>
                  );
                })}
              </nav>
              {error && (
                <DismissibleAlert message={error} closeLabel={messages.close} onDismiss={() => setError('')} />
              )}
              {images.length === 0 && !error && <p className="lf-media-assets__empty">{messages.empty}</p>}
              <div className="lf-media-assets__grid">
                {images.map((image, index) => (
                  <button
                    type="button"
                    key={image.url}
                    className={selectedPaths.has(image.path) ? 'lf-media-assets__tile is-selected' : 'lf-media-assets__tile'}
                    aria-selected={selectedPaths.has(image.path)}
                    draggable
                    onDragStart={(event) => {
                      const paths = selectedPaths.has(image.path) ? Array.from(selectedPaths) : [image.path];
                      if (!selectedPaths.has(image.path)) {
                        setSelectedPaths(new Set([image.path]));
                        setSelectionAnchor(image.path);
                      }
                      event.dataTransfer.setData('text/plain', JSON.stringify(paths));
                      event.dataTransfer.effectAllowed = 'move';
                      setDraggingImagePaths(paths);
                      if (paths.length > 1) {
                        const badge = document.createElement('div');
                        badge.className = 'lf-media-assets__drag-count';
                        badge.textContent = String(paths.length);
                        document.body.appendChild(badge);
                        event.dataTransfer.setDragImage(badge, 16, 16);
                        requestAnimationFrame(() => badge.remove());
                      }
                    }}
                    onDragEnd={() => {
                      setDraggingImagePaths([]);
                      setDropTargetFolder(null);
                    }}
                    onClick={(event) => selectImage(image, index, event)}
                    onDoubleClick={() => session.select(image.url)}
                  >
                    <span className="lf-media-assets__preview"><img src={image.url} alt="" /></span>
                    <span className="lf-media-assets__meta">
                      <span className="lf-media-assets__meta-name">{image.name}</span>
                      <span className="lf-media-assets__meta-size">
                        {`${image.width}×${image.height} · ${formatBytes(image.bytes, messages)}`}
                      </span>
                    </span>
                  </button>
                ))}
              </div>
            </div>
            {dragging && <div className="lf-media-assets__drop">{messages.drop}</div>}
          </div>
        </div>
        <footer className="lf-media-assets__footer">
          <label className="lf-media-assets__limit">
            <span>{messages.itemsPerPage}</span>
            <select value={limit} onChange={(event) => { setPage(1); setLimit(Number(event.target.value)); }}>
              <option value={12}>12</option>
              <option value={24}>24</option>
              <option value={50}>50</option>
            </select>
          </label>
          <span>{fill(messages.range, { from, to, total })}</span>
          <div className="lf-media-assets__pager">
            <span>{fill(messages.page, { page, pages })}</span>
            <button type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>‹</button>
            <button type="button" disabled={page >= pages} onClick={() => setPage((current) => current + 1)}>›</button>
          </div>
        </footer>
      </div>
    </div>
  );
}
