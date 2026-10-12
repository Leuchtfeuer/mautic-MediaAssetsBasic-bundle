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

function FolderRows({
  node,
  depth,
  selected,
  expanded,
  messages,
  draggingImagePath,
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
  draggingImagePath: string | null;
  dropTargetFolder: string | null;
  onSelect: (path: string) => void;
  onToggle: (path: string) => void;
  onDropTarget: (path: string | null) => void;
  onMoveToFolder: (folderPath: string, imagePath: string) => void;
}): JSX.Element {
  const hasChildren = node.children.length > 0;
  const isExpanded = expanded.has(node.path);
  const canDrop = null !== draggingImagePath && parentFolderPath(draggingImagePath) !== node.path;
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
          if (!canDrop || !draggingImagePath) {
            return;
          }
          event.preventDefault();
          onDropTarget(null);
          onMoveToFolder(node.path, draggingImagePath);
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
          draggingImagePath={draggingImagePath}
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
  const [selected, setSelected] = useState<string | null>(null);
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
  const [draggingImagePath, setDraggingImagePath] = useState<string | null>(null);
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
    if (stored.length > 0) {
      await refreshImages(1);
      setTreeVersion((value) => value + 1);
      if (stored.length === 1) {
        setSelected(stored[0].url);
      }
    }
  }

  async function fetchUrl(): Promise<void> {
    const id = `url-${url}`;
    setProgress((current) => [...current, { id, name: url, progress: 0.4 }]);
    try {
      const image = await importUrl(config, url, path);
      setProgress((current) => current.map((row) => (row.id === id ? { ...row, progress: 1 } : row)));
      await refreshImages(1);
      setTreeVersion((value) => value + 1);
      setSelected(image.url);
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
    setSelected(null);
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

  async function moveImageToFolder(folderPath: string, imagePath: string): Promise<void> {
    try {
      const moved = await moveImage(config, imagePath, folderPath);
      setTreeVersion((value) => value + 1);
      setError('');
      if (parentFolderPath(imagePath) === path) {
        await refreshImages(page);
      }
      const movedFrom = images.find((row) => row.path === imagePath);
      if (movedFrom && selected === movedFrom.url) {
        setSelected(moved.url);
      }
    } catch (reason: unknown) {
      setError(reason instanceof Error ? reason.message : messages.moveFailed);
    } finally {
      setDraggingImagePath(null);
      setDropTargetFolder(null);
    }
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
            accept="image/*"
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
              <div className="lf-media-assets__progress-row" key={row.id}>
                <strong>{row.name}</strong>
                {row.error ? <div className="lf-media-assets__error">{row.error}</div> : (
                  <div className="lf-media-assets__bar"><span style={{ width: `${Math.round(row.progress * 100)}%` }} /></div>
                )}
              </div>
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
                {folderError && <div className="lf-media-assets__error">{folderError}</div>}
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
                  draggingImagePath={draggingImagePath}
                  dropTargetFolder={dropTargetFolder}
                  onSelect={selectFolder}
                  onToggle={toggleFolder}
                  onDropTarget={setDropTargetFolder}
                  onMoveToFolder={(folderPath, imagePath) => void moveImageToFolder(folderPath, imagePath)}
                />
              )}
            </div>
          </aside>
          <div
            className="lf-media-assets__body"
            onDragOver={(event) => {
              event.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(event) => {
              event.preventDefault();
              setDragging(false);
              void sendFiles(Array.from(event.dataTransfer.files));
            }}
          >
            <div className="lf-media-assets__breadcrumb" aria-label={crumbs.join(' / ')}>
              {crumbs.map((crumb, index) => (
                <span key={`${crumb}-${index}`}>
                  {index > 0 && <span className="lf-media-assets__breadcrumb-sep"> / </span>}
                  <span className={index === crumbs.length - 1 ? 'is-current' : undefined}>{crumb}</span>
                </span>
              ))}
            </div>
            {error && <p className="lf-media-assets__error">{error}</p>}
            {images.length === 0 && !error && <p className="lf-media-assets__empty">{messages.empty}</p>}
            <div className="lf-media-assets__grid">
              {images.map((image) => (
                <button
                  type="button"
                  key={image.url}
                  className={selected === image.url ? 'lf-media-assets__tile is-selected' : 'lf-media-assets__tile'}
                  draggable
                  onDragStart={(event) => {
                    event.dataTransfer.setData('text/plain', image.path);
                    event.dataTransfer.effectAllowed = 'move';
                    setDraggingImagePath(image.path);
                  }}
                  onDragEnd={() => {
                    setDraggingImagePath(null);
                    setDropTargetFolder(null);
                  }}
                  onClick={() => setSelected(image.url)}
                  onDoubleClick={() => session.select(image.url)}
                >
                  <span className="lf-media-assets__preview"><img src={image.url} alt="" /></span>
                  <span className="lf-media-assets__meta">
                    {image.name}
                    <small>{`${image.width}×${image.height} · ${formatBytes(image.bytes, messages)}`}</small>
                  </span>
                </button>
              ))}
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
