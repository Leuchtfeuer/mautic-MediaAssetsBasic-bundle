export interface MediaImage {
  name: string;
  url: string;
  width: number;
  height: number;
  bytes: number;
}

export interface PickerConfig {
  listUrl: string;
  uploadUrl: string;
  importUrl: string;
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
