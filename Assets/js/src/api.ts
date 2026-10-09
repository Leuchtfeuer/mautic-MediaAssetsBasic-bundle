import type { FolderNode, MediaImage, PickerConfig } from './types';

interface ListResponse {
  images: MediaImage[];
  total: number;
  path?: string;
}

interface UploadResponse {
  files?: Array<{ ok: boolean; name?: string; error?: string; image?: MediaImage }>;
  error?: string;
}

function csrfToken(): string {
  const token = (window as unknown as { mauticAjaxCsrf?: string }).mauticAjaxCsrf;

  return typeof token === 'string' ? token : '';
}

function headers(json = false): Headers {
  const result = new Headers({
    'X-CSRF-Token': csrfToken(),
    'X-Requested-With': 'XMLHttpRequest',
  });
  if (json) {
    result.set('Content-Type', 'application/json');
  }

  return result;
}

export async function listImages(config: PickerConfig, page: number, limit: number, path: string): Promise<ListResponse> {
  const url = new URL(config.listUrl, window.location.origin);
  url.searchParams.set('page', String(page));
  url.searchParams.set('limit', String(limit));
  if ('' !== path) {
    url.searchParams.set('path', path);
  }
  const response = await fetch(url.toString(), { headers: headers() });
  if (!response.ok) {
    throw new Error(config.messages?.loadFailed ?? 'Images could not be loaded.');
  }

  return response.json() as Promise<ListResponse>;
}

export async function listFolders(config: PickerConfig): Promise<FolderNode> {
  const response = await fetch(config.foldersUrl, { headers: headers() });
  const payload = await response.json() as { folder?: FolderNode; error?: string };
  if (!response.ok || !payload.folder) {
    throw new Error(payload.error || config.messages?.loadFailed || 'Images could not be loaded.');
  }

  return payload.folder;
}

export async function createFolder(config: PickerConfig, parent: string, name: string): Promise<FolderNode> {
  const response = await fetch(config.createFolderUrl, {
    method: 'POST',
    headers: headers(true),
    body: JSON.stringify({ parent, name }),
  });
  const payload = await response.json() as { folder?: FolderNode; error?: string };
  if (!response.ok || !payload.folder) {
    throw new Error(payload.error || config.messages?.folderCreateFailed || 'The folder could not be created.');
  }

  return payload.folder;
}

export function uploadFile(
  config: PickerConfig,
  file: File,
  path: string,
  onProgress: (progress: number) => void,
): Promise<{ ok: boolean; error?: string; image?: MediaImage }> {
  return new Promise((resolve) => {
    const body = new FormData();
    body.append('files[]', file);
    body.append('path', path);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', config.uploadUrl);
    xhr.setRequestHeader('X-CSRF-Token', csrfToken());
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable) {
        onProgress(event.loaded / event.total);
      }
    };
    xhr.onload = () => {
      let payload: UploadResponse = {};
      try {
        payload = JSON.parse(xhr.responseText) as UploadResponse;
      } catch {
        resolve({ ok: false, error: config.messages?.uploadFailed ?? 'The file could not be uploaded.' });
        return;
      }
      const result = payload.files?.[0];
      if (!result || !result.ok || !result.image) {
        resolve({ ok: false, error: result?.error || payload.error || config.messages?.uploadFailed || 'The file could not be uploaded.' });
        return;
      }
      resolve({ ok: true, image: result.image });
    };
    xhr.onerror = () => resolve({ ok: false, error: config.messages?.uploadFailed ?? 'The file could not be uploaded.' });
    xhr.send(body);
  });
}

export async function importUrl(config: PickerConfig, url: string, path: string): Promise<MediaImage> {
  const response = await fetch(config.importUrl, {
    method: 'POST',
    headers: headers(true),
    body: JSON.stringify({ url, path }),
  });
  const payload = await response.json() as { image?: MediaImage; error?: string };
  if (!response.ok || !payload.image) {
    throw new Error(payload.error || config.messages?.importFailed || 'The image could not be imported.');
  }

  return payload.image;
}
