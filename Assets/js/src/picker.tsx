import { useEffect, useRef, useState } from 'react';
import { importUrl, listImages, uploadFile } from './api';
import type { AssetSession, FileProgress, MediaImage, PickerConfig, PickerMessages } from './types';
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

export function Picker({ config, session }: { config: PickerConfig; session: AssetSession }): JSX.Element {
  const messages = text(config);
  const [images, setImages] = useState<MediaImage[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [limit, setLimit] = useState(24);
  const [selected, setSelected] = useState<string | null>(null);
  const [urlOpen, setUrlOpen] = useState(false);
  const [url, setUrl] = useState('');
  const urlInput = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);
  const [progress, setProgress] = useState<FileProgress[]>([]);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    listImages(config, page, limit)
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
  }, [config, page, limit]);

  const pages = Math.max(1, Math.ceil(total / limit));
  const from = total === 0 ? 0 : (page - 1) * limit + 1;
  const to = Math.min(page * limit, total);

  async function sendFiles(files: File[]): Promise<void> {
    const rows = files.map((file) => ({ id: `${file.name}-${file.size}-${file.lastModified}`, name: file.name, progress: 0 }));
    setProgress(rows);
    const stored: MediaImage[] = [];
    for (const file of files) {
      const id = `${file.name}-${file.size}-${file.lastModified}`;
      const result = await uploadFile(config, file, (value) => {
        setProgress((current) => current.map((row) => (row.id === id ? { ...row, progress: value } : row)));
      });
      setProgress((current) => current.map((row) => (row.id === id ? { ...row, progress: 1, error: result.error } : row)));
      if (result.image) {
        stored.push(result.image);
      }
    }
    if (stored.length > 0) {
      setPage(1);
      const refreshed = await listImages(config, 1, limit);
      setImages(refreshed.images);
      setTotal(refreshed.total);
      if (stored.length === 1) {
        setSelected(stored[0].url);
      }
    }
  }

  async function fetchUrl(): Promise<void> {
    const id = `url-${url}`;
    setProgress((current) => [...current, { id, name: url, progress: 0.4 }]);
    try {
      const image = await importUrl(config, url);
      setProgress((current) => current.map((row) => (row.id === id ? { ...row, progress: 1 } : row)));
      setPage(1);
      const refreshed = await listImages(config, 1, limit);
      setImages(refreshed.images);
      setTotal(refreshed.total);
      setSelected(image.url);
      setUrl('');
      setUrlOpen(false);
    } catch (reason: unknown) {
      const message = reason instanceof Error ? reason.message : messages.importFailed;
      setProgress((current) => current.map((row) => (row.id === id ? { ...row, error: message } : row)));
    }
  }

  useEffect(() => {
    if (urlOpen) {
      urlInput.current?.focus();
    }
  }, [urlOpen]);

  function onFetch(): void {
    setUrlOpen((open) => !open);
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
          {error && <p className="lf-media-assets__error">{error}</p>}
          {images.length === 0 && !error && <p className="lf-media-assets__empty">{messages.empty}</p>}
          <div className="lf-media-assets__grid">
            {images.map((image) => (
              <button
                type="button"
                key={image.url}
                className={selected === image.url ? 'lf-media-assets__tile is-selected' : 'lf-media-assets__tile'}
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
