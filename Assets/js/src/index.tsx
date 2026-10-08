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
    getConfig?: () => { custom?: boolean };
    add?: (asset: { src: string; type: string }) => void;
    close?: () => void;
  };
  getConfig?: () => { assetManager?: { custom?: boolean } };
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

function unmount(): void {
  root?.unmount();
  root = null;
  document.getElementById('lf-media-assets-basic')?.remove();
}

function mount(config: PickerConfig, session: AssetSession): void {
  unmount();
  root = createRoot(host());
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
      unmount();
      return;
    }
    mount(config, {
      select: (src) => {
        props.select?.({ src, type: 'image' });
        props.close?.();
        unmount();
      },
      close: () => {
        props.close?.();
        unmount();
      },
    });
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
