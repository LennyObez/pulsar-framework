/**
 * Custom element that renders and manages toast notifications.
 *
 * Positioned fixed top-right, renders toast items with slide-in animation,
 * auto-dismiss with progress bar, and ARIA live region for accessibility.
 */

interface ToastItem {
  readonly id: string;
  readonly message: string;
  readonly type: 'success' | 'error' | 'info' | 'warning';
  readonly duration: number;
}

interface ActiveToast {
  readonly item: ToastItem;
  readonly element: HTMLElement;
  timer: ReturnType<typeof setTimeout> | null;
  progressAnimation: Animation | null;
  remainingTime: number;
  paused: boolean;
  pausedAt: number;
}

const MAX_VISIBLE = 5;

class CmsToastContainer extends HTMLElement {
  private readonly queue: ToastItem[] = [];
  private readonly active: ActiveToast[] = [];
  private idCounter = 0;
  private prefersReducedMotion = false;
  private mql: MediaQueryList | null = null;
  private readonly mqlHandler = (e: MediaQueryListEvent): void => {
    this.prefersReducedMotion = e.matches;
  };

  connectedCallback(): void {
    this.setAttribute('role', 'status');
    this.setAttribute('aria-live', 'polite');
    this.setAttribute('aria-relevant', 'additions');
    this.classList.add('cms-toast-container');

    this.mql = window.matchMedia('(prefers-reduced-motion: reduce)');
    this.prefersReducedMotion = this.mql.matches;
    this.mql.addEventListener('change', this.mqlHandler);
  }

  disconnectedCallback(): void {
    if (this.mql !== null) {
      this.mql.removeEventListener('change', this.mqlHandler);
      this.mql = null;
    }
  }

  /** Add a toast notification to display. */
  addToast(message: string, type: ToastItem['type'], duration: number): void {
    const item: ToastItem = {
      id: `toast-${++this.idCounter}`,
      message,
      type,
      duration,
    };

    if (this.active.length >= MAX_VISIBLE) {
      this.queue.push(item);
      return;
    }

    this.showToast(item);
  }

  private showToast(item: ToastItem): void {
    const el = document.createElement('div');
    el.className = `cms-toast cms-toast--${item.type}`;
    el.setAttribute('role', item.type === 'error' ? 'alert' : 'status');
    el.id = item.id;

    const content = document.createElement('div');
    content.className = 'cms-toast__content';
    content.textContent = item.message;

    const closeBtn = document.createElement('button');
    closeBtn.className = 'cms-toast__close';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Dismiss notification');
    closeBtn.textContent = '\u00D7';
    closeBtn.addEventListener('click', () => {
      this.dismissToast(item.id);
    });

    const progress = document.createElement('div');
    progress.className = 'cms-toast__progress';

    const progressBar = document.createElement('div');
    progressBar.className = 'cms-toast__progress-bar';
    progress.appendChild(progressBar);

    el.appendChild(content);
    el.appendChild(closeBtn);
    el.appendChild(progress);

    // Insert at top (newest first)
    this.prepend(el);

    // Enter animation
    let progressAnimation: Animation | null = null;

    if (!this.prefersReducedMotion) {
      el.classList.add('cms-toast--entering');
      el.addEventListener(
        'animationend',
        () => {
          el.classList.remove('cms-toast--entering');
        },
        { once: true },
      );
    }

    // Progress bar animation
    if (item.duration > 0) {
      progressAnimation = progressBar.animate(
        [{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }],
        {
          duration: item.duration,
          easing: 'linear',
          fill: 'forwards',
        },
      );
    }

    const activeToast: ActiveToast = {
      item,
      element: el,
      timer: null,
      progressAnimation,
      remainingTime: item.duration,
      paused: false,
      pausedAt: 0,
    };

    // Auto-dismiss timer
    if (item.duration > 0) {
      activeToast.timer = setTimeout(() => {
        this.dismissToast(item.id);
      }, item.duration);
    }

    // Pause on hover, resume on leave
    el.addEventListener('mouseenter', () => {
      this.pauseToast(activeToast);
    });
    el.addEventListener('mouseleave', () => {
      this.resumeToast(activeToast);
    });

    this.active.push(activeToast);
  }

  private pauseToast(toast: ActiveToast): void {
    if (toast.paused) return;
    toast.paused = true;
    toast.pausedAt = Date.now();

    if (toast.timer !== null) {
      clearTimeout(toast.timer);
      toast.timer = null;
    }

    if (toast.progressAnimation !== null) {
      toast.progressAnimation.pause();
    }
  }

  private resumeToast(toast: ActiveToast): void {
    if (!toast.paused) return;
    toast.paused = false;

    const elapsed = Date.now() - toast.pausedAt;
    toast.remainingTime = Math.max(0, toast.remainingTime - elapsed);

    if (toast.remainingTime > 0) {
      toast.timer = setTimeout(() => {
        this.dismissToast(toast.item.id);
      }, toast.remainingTime);
    }

    if (toast.progressAnimation !== null) {
      toast.progressAnimation.play();
    }
  }

  private dismissToast(id: string): void {
    const index = this.active.findIndex((t) => t.item.id === id);
    if (index === -1) return;

    const toast = this.active[index]!;

    if (toast.timer !== null) {
      clearTimeout(toast.timer);
    }

    if (toast.progressAnimation !== null) {
      toast.progressAnimation.cancel();
    }

    if (!this.prefersReducedMotion) {
      toast.element.classList.add('cms-toast--exiting');
      toast.element.addEventListener(
        'animationend',
        () => {
          this.removeToastElement(index, toast);
        },
        { once: true },
      );
    } else {
      this.removeToastElement(index, toast);
    }
  }

  private removeToastElement(index: number, toast: ActiveToast): void {
    toast.element.remove();
    const currentIndex = this.active.indexOf(toast);
    if (currentIndex !== -1) {
      this.active.splice(currentIndex, 1);
    }

    // Show queued toast if available
    if (this.queue.length > 0 && this.active.length < MAX_VISIBLE) {
      const next = this.queue.shift();
      if (next !== undefined) {
        this.showToast(next);
      }
    }
  }
}

customElements.define('cms-toast-container', CmsToastContainer);

export { CmsToastContainer };
export type { ToastItem };
