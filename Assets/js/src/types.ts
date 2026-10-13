export interface MediaImage {
  name: string;
  path: string;
  url: string;
  width: number;
  height: number;
  bytes: number;
}

export interface FolderNode {
  name: string;
  path: string;
  count: number;
  children: FolderNode[];
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
  folders: string;
  home: string;
  newFolder: string;
  folderNamePlaceholder: string;
  createFolder: string;
  folderCreateFailed: string;
  expandFolder: string;
  collapseFolder: string;
  moveFailed: string;
  moving: string;
}

export interface PickerConfig {
  listUrl: string;
  uploadUrl: string;
  importUrl: string;
  foldersUrl: string;
  createFolderUrl: string;
  moveUrl: string;
  allowSvg?: boolean;
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
