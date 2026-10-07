/**
 * Page builder file download block.
 *
 * `<cms-pb-file-download>` renders a file download card with icon, file name,
 * file size, optional description, and a download button.
 */

export class CmsPbFileDownload extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-file-download');
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

    const fileUrl = this.blockData['fileUrl'];
    const fileName = this.blockData['fileName'];
    const fileSize = this.blockData['fileSize'];
    const description = this.blockData['description'];

    const fileUrlStr = typeof fileUrl === 'string' ? fileUrl : '';
    const fileNameStr = typeof fileName === 'string' ? fileName : '';
    const fileSizeStr = typeof fileSize === 'string' ? fileSize : '';
    const descStr = typeof description === 'string' ? description : '';

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-file-download__wrapper';

    if (fileUrlStr !== '' && fileNameStr !== '') {
      // Preview card
      const card = document.createElement('div');
      card.className = 'pb-block-file-download__card';

      const icon = document.createElement('div');
      icon.className = 'pb-block-file-download__icon';
      icon.innerHTML = '<i class="fa fa-file"></i>';
      card.appendChild(icon);

      const info = document.createElement('div');
      info.className = 'pb-block-file-download__info';

      const name = document.createElement('div');
      name.className = 'pb-block-file-download__name';
      name.textContent = fileNameStr;
      info.appendChild(name);

      if (fileSizeStr !== '') {
        const size = document.createElement('div');
        size.className = 'pb-block-file-download__size';
        size.textContent = fileSizeStr;
        info.appendChild(size);
      }

      if (descStr !== '') {
        const desc = document.createElement('div');
        desc.className = 'pb-block-file-download__description';
        desc.textContent = descStr;
        info.appendChild(desc);
      }

      card.appendChild(info);

      const dlBtn = document.createElement('a');
      dlBtn.className = 'cms-btn cms-btn--primary pb-block-file-download__btn';
      dlBtn.textContent = 'Download';
      dlBtn.href = '#';
      dlBtn.addEventListener('click', (e) => e.preventDefault());
      card.appendChild(dlBtn);

      wrapper.appendChild(card);

      // Edit button
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'cms-btn cms-btn--outline';
      editBtn.textContent = 'Edit File Info';
      editBtn.addEventListener('click', () => {
        this.showForm();
      });
      wrapper.appendChild(editBtn);
    } else {
      this.renderForm(wrapper);
    }

    this.appendChild(wrapper);
  }

  private renderForm(parent: HTMLElement): void {
    const form = document.createElement('div');
    form.className = 'pb-block-file-download__form';

    const fields: Array<{
      key: string;
      label: string;
      type: string;
      placeholder: string;
    }> = [
      { key: 'fileUrl', label: 'File URL', type: 'url', placeholder: 'https://...' },
      { key: 'fileName', label: 'File name', type: 'text', placeholder: 'document.pdf' },
      { key: 'fileSize', label: 'File size', type: 'text', placeholder: '2.4 MB' },
      {
        key: 'description',
        label: 'Description',
        type: 'text',
        placeholder: 'Optional description...',
      },
    ];

    const inputs: Record<string, HTMLInputElement> = {};

    for (const field of fields) {
      const group = document.createElement('label');
      group.className = 'pb-block-file-download__field';
      const span = document.createElement('span');
      span.textContent = field.label;
      group.appendChild(span);

      const input = document.createElement('input');
      input.type = field.type;
      input.className = 'cms-input';
      input.placeholder = field.placeholder;
      const existing = this.blockData[field.key];
      input.value = typeof existing === 'string' ? existing : '';
      group.appendChild(input);
      inputs[field.key] = input;

      form.appendChild(group);
    }

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'cms-btn cms-btn--primary';
    saveBtn.textContent = 'Save';
    saveBtn.addEventListener('click', () => {
      for (const [key, input] of Object.entries(inputs)) {
        this.blockData[key] = input.value;
      }
      this.render();
      this.emitUpdate();
    });
    form.appendChild(saveBtn);

    parent.appendChild(form);
  }

  private showForm(): void {
    this.innerHTML = '';
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-file-download__wrapper';
    this.renderForm(wrapper);
    this.appendChild(wrapper);
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

customElements.define('cms-pb-file-download', CmsPbFileDownload);
