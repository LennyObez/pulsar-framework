/**
 * CSS-only responsive viewport preview for the page builder canvas.
 *
 * Constrains the canvas width to simulate desktop, tablet, and mobile
 * viewports. Transitions smoothly between modes.
 */

export type PreviewMode = 'desktop' | 'tablet' | 'mobile';

const MODE_WIDTHS: Record<PreviewMode, string> = {
  desktop: '100%',
  tablet: '768px',
  mobile: '375px',
};

export class ResponsivePreview {
  private mode: PreviewMode = 'desktop';
  private readonly canvas: HTMLElement;

  constructor(canvas: HTMLElement) {
    this.canvas = canvas;
    this.applyMode();
  }

  setMode(mode: PreviewMode): void {
    if (mode === this.mode) {
      return;
    }

    this.mode = mode;
    this.applyMode();
  }

  getMode(): PreviewMode {
    return this.mode;
  }

  private applyMode(): void {
    this.canvas.style.maxWidth = MODE_WIDTHS[this.mode];
    this.canvas.style.margin = this.mode === 'desktop' ? '0' : '0 auto';
    this.canvas.dataset['previewMode'] = this.mode;
  }
}
