import { createRoot, type Root } from 'react-dom/client';
import { Picker } from './picker';
import type { AssetSession, PickerConfig } from './types';

interface GrapesAssetProps {
  open?: boolean;
  select?: (asset: { src: string; type: string }) => void;
  close?: () => void;
}

interface GrapesEditor {
  AssetManager?: {
    getConfig?: () => { custom?: boolean; openAssetsOnDrop?: boolean | number };
    add?: (asset: { src: string; type: string }) => void;
    close?: () => void;
  };
  getConfig?: () => { assetManager?: { custom?: boolean; openAssetsOnDrop?: boolean | number } };
  getSelected?: () => { addAttributes?: (attributes: { src: string }) => void } | undefined;
  Modal?: { close?: () => void };
  on: (event: string, handler: (props: GrapesAssetProps) => void) => void;
  __lfMediaAssetsAttached?: boolean;
}

declare global {
  interface Window {
    LeuchtfeuerMediaAssetsBasic?: PickerConfig;
    mauticAjaxCsrf?: string;
    mQuery?: (target: Document | string) => {
      on: (event: string, selector: string, handler: (event: unknown, editor: GrapesEditor) => void) => void;
    };
    __lfMediaAssetsBasicInstalled?: boolean;
  }
}

let root: Root | null = null;
/** True only when the user intentionally closes/selects — ignore GrapesJS spurious closes. */
let userClosing = false;
let previousOpenAssetsOnDrop: boolean | number | undefined;
let dropGuardAttached = false;

function host(): HTMLElement {
  const existing = document.getElementById('lf-media-assets-basic');
  if (existing) {
    return existing;
  }
  const element = document.createElement('div');
  element.id = 'lf-media-assets-basic';
  document.body.appendChild(element);

  return element;
}

function isFileDrag(event: DragEvent): boolean {
  return Array.from(event.dataTransfer?.types ?? []).includes('Files');
}

function dropGuard(event: DragEvent): void {
  const pickerHost = document.getElementById('lf-media-assets-basic');
  if (!pickerHost || !isFileDrag(event)) {
    return;
  }
  const target = event.target;
  const insidePicker = target instanceof Node && pickerHost.contains(target);
  if (insidePicker) {
    // Let React handlers on the picker run; still prevent the browser navigating away.
    if ('dragover' === event.type || 'dragenter' === event.type) {
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'copy';
      }
    }

    return;
  }
  // File drag over the builder canvas while our picker is open — do not let GrapesJS embed the file.
  event.preventDefault();
  event.stopPropagation();
}

function attachDropGuard(): void {
  if (dropGuardAttached) {
    return;
  }
  dropGuardAttached = true;
  // Capture phase so we run before GrapesJS document/canvas listeners.
  document.addEventListener('dragenter', dropGuard, true);
  document.addEventListener('dragover', dropGuard, true);
  document.addEventListener('drop', dropGuard, true);
}

function detachDropGuard(): void {
  if (!dropGuardAttached) {
    return;
  }
  dropGuardAttached = false;
  document.removeEventListener('dragenter', dropGuard, true);
  document.removeEventListener('dragover', dropGuard, true);
  document.removeEventListener('drop', dropGuard, true);
}

function disableOpenAssetsOnDrop(editor: GrapesEditor): void {
  const assetConfig = editor.AssetManager?.getConfig?.();
  const rootConfig = editor.getConfig?.().assetManager;
  if (assetConfig && previousOpenAssetsOnDrop === undefined) {
    previousOpenAssetsOnDrop = assetConfig.openAssetsOnDrop;
  }
  if (assetConfig) {
    assetConfig.openAssetsOnDrop = false;
  }
  if (rootConfig) {
    rootConfig.openAssetsOnDrop = false;
  }
}

function restoreOpenAssetsOnDrop(editor: GrapesEditor): void {
  const assetConfig = editor.AssetManager?.getConfig?.();
  const rootConfig = editor.getConfig?.().assetManager;
  const previous = previousOpenAssetsOnDrop ?? 1;
  if (assetConfig) {
    assetConfig.openAssetsOnDrop = previous;
  }
  if (rootConfig) {
    rootConfig.openAssetsOnDrop = previous;
  }
  previousOpenAssetsOnDrop = undefined;
}

function unmount(editor?: GrapesEditor): void {
  detachDropGuard();
  if (editor) {
    restoreOpenAssetsOnDrop(editor);
  }
  root?.unmount();
  root = null;
  document.getElementById('lf-media-assets-basic')?.remove();
}

function mount(config: PickerConfig, session: AssetSession, editor: GrapesEditor): void {
  // Keep the React tree if we are already open (spurious remounts).
  if (null === root) {
    root = createRoot(host());
  } else if (!document.getElementById('lf-media-assets-basic')) {
    root = createRoot(host());
  }
  disableOpenAssetsOnDrop(editor);
  attachDropGuard();
  root.render(<Picker config={config} session={session} />);
}

function attach(editor: GrapesEditor): void {
  const config = window.LeuchtfeuerMediaAssetsBasic;
  if (!config || editor.__lfMediaAssetsAttached) {
    return;
  }
  editor.__lfMediaAssetsAttached = true;

  const assetConfig = editor.AssetManager?.getConfig?.();
  if (assetConfig) {
    assetConfig.custom = true;
  }
  const rootConfig = editor.getConfig?.().assetManager;
  if (rootConfig) {
    rootConfig.custom = true;
  }

  editor.on('asset:custom', (props) => {
    if (!props.open) {
      // Canvas file-drop / openAssetsOnDrop often emits a close while our picker should stay up.
      if (!userClosing && null !== root && document.getElementById('lf-media-assets-basic')) {
        return;
      }
      userClosing = false;
      unmount(editor);
      editor.AssetManager?.close?.();
      editor.Modal?.close?.();

      return;
    }

    userClosing = false;
    mount(config, {
      select: (src) => {
        userClosing = true;
        props.select?.({ src, type: 'image' });
        props.close?.();
        unmount(editor);
      },
      close: () => {
        userClosing = true;
        props.close?.();
        unmount(editor);
        editor.AssetManager?.close?.();
        editor.Modal?.close?.();
      },
    }, editor);
  });
}

function install(): void {
  if (window.__lfMediaAssetsBasicInstalled || !window.mQuery || !window.LeuchtfeuerMediaAssetsBasic) {
    return;
  }
  window.__lfMediaAssetsBasicInstalled = true;
  window.mQuery(document).on('builder:show', '.builder', (_event, editor) => {
    attach(editor);
  });
}

install();
