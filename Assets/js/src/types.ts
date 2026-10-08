export interface MediaImage {
  name: string;
  url: string;
  width: number;
  height: number;
  bytes: number;
}

export interface PickerMessages {
  images: string;
  importFromUrl: string;
  upload: string;
  close: string;
  fetch: string;
  urlPlaceholder: string;
  loadFailed: string;
  importFailed: string;
  empty: string;
  drop: string;
  itemsPerPage: string;
  range: string;
  page: string;
  byte: string;
  kilobyte: string;
  megabyte: string;
  uploadFailed: string;
}

export interface PickerConfig {
  listUrl: string;
  uploadUrl: string;
  importUrl: string;
  messages?: Partial<PickerMessages>;
}

export interface FileProgress {
  id: string;
  name: string;
  progress: number;
  error?: string;
}

export interface AssetSession {
  select: (src: string) => void;
  close: () => void;
}
