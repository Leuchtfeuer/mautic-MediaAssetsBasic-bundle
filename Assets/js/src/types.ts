export interface MediaImage {
  name: string;
  path: string;
  url: string;
  thumbUrl?: string | null;
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

export type ConflictType = 'checksum_duplicate' | 'name_clash';
export type UploadDecision = 'use_existing' | 'keep_both' | 'replace';

export interface UploadConflict {
  type: ConflictType;
  intendedName: string;
  intendedPath: string;
  existing: {
    name: string;
    path: string;
    url: string;
    dateAdded: string | null;
  };
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
  searchPlaceholder: string;
  searchClear: string;
  searchScope: string;
  searchScopeEverywhere: string;
  searchEverywhere: string;
  searchEmpty: string;
  searchEmptyEverywhere: string;
  conflictAttention: string;
  conflictChecksum: string;
  conflictName: string;
  conflictUseExisting: string;
  conflictKeepBoth: string;
  conflictReplace: string;
  conflictCancel: string;
  conflictUseExistingAll: string;
  conflictReplaceAllNames: string;
  conflictKeepBothAll: string;
  conflictCancelAll: string;
  conflictCloseConfirm: string;
}

export interface PickerConfig {
  listUrl: string;
  uploadUrl: string;
  uploadCheckUrl: string;
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
  file?: File;
  importSource?: string;
  conflict?: UploadConflict;
  usedExisting?: boolean;
}

export interface AssetSession {
  select: (src: string) => void;
  close: () => void;
}
