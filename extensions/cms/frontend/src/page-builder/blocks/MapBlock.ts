/**
 * Page builder map block.
 *
 * `<cms-pb-map>` renders an embedded map iframe using the configured address.
 * Uses OpenStreetMap embed as the default provider.
 */

export class CmsPbMap extends HTMLElement {
  private blockData: Record<string, unknown> = {};

  connectedCallback(): void {
    this.classList.add('pb-block-map');
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

    const address = this.blockData['address'];
    const zoom = this.blockData['zoom'];
    const height = this.blockData['height'];

    const addressStr = typeof address === 'string' ? address : '';
    const zoomNum = typeof zoom === 'number' && zoom >= 1 && zoom <= 20 ? zoom : 14;
    const heightNum = typeof height === 'number' && height > 0 ? height : 400;

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-map__wrapper';

    if (addressStr !== '') {
      // Map iframe using OpenStreetMap embed
      const container = document.createElement('div');
      container.className = 'pb-block-map__container';
      container.style.height = `${heightNum}px`;

      const iframe = document.createElement('iframe');
      iframe.className = 'pb-block-map__iframe';
      const query = encodeURIComponent(addressStr);
      iframe.src = `https://www.openstreetmap.org/export/embed.html?bbox=&layer=mapnik&marker=&query=${query}`;
      iframe.title = `Map of ${addressStr}`;
      iframe.setAttribute('loading', 'lazy');
      iframe.sandbox.add('allow-scripts', 'allow-same-origin');
      container.appendChild(iframe);

      wrapper.appendChild(container);

      // Address display with edit
      const footer = document.createElement('div');
      footer.className = 'pb-block-map__footer';

      const addressDisplay = document.createElement('span');
      addressDisplay.className = 'pb-block-map__address';
      addressDisplay.textContent = addressStr;
      footer.appendChild(addressDisplay);

      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'cms-btn cms-btn--outline';
      editBtn.textContent = 'Change Address';
      editBtn.addEventListener('click', () => {
        this.showAddressForm();
      });
      footer.appendChild(editBtn);

      wrapper.appendChild(footer);
    } else {
      this.renderAddressForm(wrapper);
    }

    this.appendChild(wrapper);
  }

  private renderAddressForm(parent: HTMLElement): void {
    const form = document.createElement('div');
    form.className = 'pb-block-map__form';

    const label = document.createElement('span');
    label.className = 'pb-block-map__form-label';
    label.textContent = 'Enter an address to display on the map:';
    form.appendChild(label);

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'cms-input';
    input.placeholder = '123 Main St, City, Country';
    input.value =
      typeof this.blockData['address'] === 'string' ? (this.blockData['address'] as string) : '';
    form.appendChild(input);

    const row = document.createElement('div');
    row.className = 'pb-block-map__form-row';

    const zoomLabel = document.createElement('label');
    zoomLabel.className = 'pb-block-map__field';
    const zoomSpan = document.createElement('span');
    zoomSpan.textContent = 'Zoom';
    zoomLabel.appendChild(zoomSpan);
    const zoomInput = document.createElement('input');
    zoomInput.type = 'number';
    zoomInput.className = 'cms-input';
    zoomInput.min = '1';
    zoomInput.max = '20';
    zoomInput.value = String(
      typeof this.blockData['zoom'] === 'number' ? this.blockData['zoom'] : 14,
    );
    zoomLabel.appendChild(zoomInput);
    row.appendChild(zoomLabel);

    const heightLabel = document.createElement('label');
    heightLabel.className = 'pb-block-map__field';
    const heightSpan = document.createElement('span');
    heightSpan.textContent = 'Height (px)';
    heightLabel.appendChild(heightSpan);
    const heightInput = document.createElement('input');
    heightInput.type = 'number';
    heightInput.className = 'cms-input';
    heightInput.min = '100';
    heightInput.value = String(
      typeof this.blockData['height'] === 'number' ? this.blockData['height'] : 400,
    );
    heightLabel.appendChild(heightInput);
    row.appendChild(heightLabel);

    form.appendChild(row);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--primary';
    addBtn.textContent = 'Show Map';
    addBtn.addEventListener('click', () => {
      if (input.value.trim() !== '') {
        this.blockData['address'] = input.value.trim();
        this.blockData['zoom'] = Number(zoomInput.value) || 14;
        this.blockData['height'] = Number(heightInput.value) || 400;
        this.render();
        this.emitUpdate();
      }
    });
    form.appendChild(addBtn);

    parent.appendChild(form);
  }

  private showAddressForm(): void {
    this.innerHTML = '';
    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-map__wrapper';
    this.renderAddressForm(wrapper);
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

customElements.define('cms-pb-map', CmsPbMap);
