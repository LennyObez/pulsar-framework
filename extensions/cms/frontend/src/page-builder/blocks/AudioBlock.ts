/**
 * Page builder audio block.
 *
 * `<cms-pb-audio>` renders an audio player with URL input and optional title.
 */

export class CmsPbAudio extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-audio');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private render(): void {
    this.innerHTML = '';

    const src = this.blockData['src'];
    const title = this.blockData['title'];

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-audio__wrapper';

    if (typeof src === 'string' && src !== '') {
      // Title
      if (typeof title === 'string' && title !== '') {
        const titleEl = document.createElement('div');
        titleEl.className = 'pb-block-audio__title';
        titleEl.textContent = title;
        wrapper.appendChild(titleEl);
      }

      // Audio player
      const audio = document.createElement('audio');
      audio.className = 'pb-block-audio__player';
      audio.controls = true;
      audio.preload = 'metadata';
      audio.src = src;
      wrapper.appendChild(audio);

      // Change button
      const changeBtn = document.createElement('button');
      changeBtn.type = 'button';
      changeBtn.className = 'cms-btn cms-btn--outline pb-block-audio__change';
      changeBtn.textContent = 'Change Audio';
      changeBtn.addEventListener('click', () => {
        this.showUrlInput();
      });
      wrapper.appendChild(changeBtn);
    } else {
      this.renderUrlForm(wrapper);
    }

    this.appendChild(wrapper);
  }

  private renderUrlForm(parent: HTMLElement): void {
    const form = document.createElement('div');
    form.className = 'pb-block-audio__form';

    const label = document.createElement('span');
    label.className = 'pb-block-audio__input-label';
    label.textContent = 'Enter audio file URL:';
    form.appendChild(label);

    const input = document.createElement('input');
    input.type = 'url';
    input.className = 'cms-input';
    input.placeholder = 'https://example.com/audio.mp3';
    form.appendChild(input);

    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'cms-input';
    titleInput.placeholder = 'Title (optional)';
    form.appendChild(titleInput);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--primary';
    addBtn.textContent = 'Add Audio';
    addBtn.addEventListener('click', () => {
      if (input.value.trim() !== '') {
        this.blockData['src'] = input.value.trim();
        this.blockData['title'] = titleInput.value.trim();
        this.render();
        this.emitUpdate();
      }
    });
    form.appendChild(addBtn);

    parent.appendChild(form);
  }

  private showUrlInput(): void {
    const newUrl = prompt(
      'Enter audio URL:',
      typeof this.blockData['src'] === 'string' ? this.blockData['src'] : '',
    );
    if (newUrl !== null) {
      this.blockData['src'] = newUrl;
      this.render();
      this.emitUpdate();
    }
  }

  private emitUpdate(): void {
    this.dispatchEvent(
      new CustomEvent('block-update', {
        bubbles: true,
        detail: this.getData(),
      }),
    );
  }
}

customElements.define('cms-pb-audio', CmsPbAudio);
