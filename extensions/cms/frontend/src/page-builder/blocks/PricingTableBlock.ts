/**
 * Page builder pricing table block.
 *
 * `<cms-pb-pricing>` renders pricing columns with plan name, price,
 * feature list, CTA button, and highlight option. Supports add/remove plans.
 */

import { escapeHtml } from '../../utils/escapeHtml.js';

interface PricingPlan {
  name: string;
  price: string;
  features: string[];
  buttonText: string;
  buttonUrl: string;
  highlighted?: boolean;
}

export class CmsPbPricing extends HTMLElement {
  private blockData: Record<string, unknown> = {};
  private editingPlanIndex: number | null = null;

  connectedCallback(): void {
    this.classList.add('pb-block-pricing');
    this.render();
  }

  setData(data: Record<string, unknown>): void {
    this.blockData = { ...data };
    this.render();
  }

  getData(): Record<string, unknown> {
    return { ...this.blockData };
  }

  private getPlans(): PricingPlan[] {
    const plans = this.blockData['plans'];
    if (!Array.isArray(plans)) return [];
    return plans.filter(
      (p): p is PricingPlan =>
        typeof p === 'object' &&
        p !== null &&
        typeof p.name === 'string' &&
        typeof p.price === 'string',
    );
  }

  private render(): void {
    this.innerHTML = '';

    const plans = this.getPlans();

    const wrapper = document.createElement('div');
    wrapper.className = 'pb-block-pricing__wrapper';

    // Preview grid
    const grid = document.createElement('div');
    grid.className = 'pb-block-pricing__grid';
    grid.style.gridTemplateColumns = `repeat(${Math.max(1, plans.length)}, 1fr)`;

    for (let i = 0; i < plans.length; i++) {
      const plan = plans[i];
      if (!plan) continue;
      const card = document.createElement('div');
      card.className = `pb-block-pricing__card${plan.highlighted ? ' pb-block-pricing__card--highlighted' : ''}`;

      const name = document.createElement('div');
      name.className = 'pb-block-pricing__name';
      name.textContent = plan.name;
      card.appendChild(name);

      const price = document.createElement('div');
      price.className = 'pb-block-pricing__price';
      price.textContent = plan.price;
      card.appendChild(price);

      const features = document.createElement('ul');
      features.className = 'pb-block-pricing__features';
      for (const feat of plan.features ?? []) {
        const li = document.createElement('li');
        li.textContent = feat;
        features.appendChild(li);
      }
      card.appendChild(features);

      const btn = document.createElement('a');
      btn.className = 'pb-block-pricing__button';
      btn.textContent = plan.buttonText || 'Choose';
      btn.href = '#';
      btn.addEventListener('click', (e) => e.preventDefault());
      card.appendChild(btn);

      // Edit/Remove controls
      const actions = document.createElement('div');
      actions.className = 'pb-block-pricing__actions';

      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'cms-btn cms-btn--outline';
      editBtn.textContent = 'Edit';
      const idx = i;
      editBtn.addEventListener('click', () => {
        this.editingPlanIndex = idx;
        this.render();
      });
      actions.appendChild(editBtn);

      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'cms-btn cms-btn--outline';
      removeBtn.textContent = 'Remove';
      removeBtn.addEventListener('click', () => {
        const current = this.getPlans();
        current.splice(idx, 1);
        this.blockData['plans'] = current;
        this.render();
        this.emitUpdate();
      });
      actions.appendChild(removeBtn);

      card.appendChild(actions);
      grid.appendChild(card);
    }

    wrapper.appendChild(grid);

    // Edit form for selected plan
    if (this.editingPlanIndex !== null && this.editingPlanIndex < plans.length) {
      this.renderPlanEditor(wrapper, plans, this.editingPlanIndex);
    }

    // Add plan button
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'cms-btn cms-btn--outline pb-block-pricing__add';
    addBtn.textContent = '+ Add Plan';
    addBtn.addEventListener('click', () => {
      const current = this.getPlans();
      current.push({
        name: 'New Plan',
        price: '$0/mo',
        features: ['Feature 1'],
        buttonText: 'Choose',
        buttonUrl: '#',
        highlighted: false,
      });
      this.blockData['plans'] = current;
      this.editingPlanIndex = current.length - 1;
      this.render();
      this.emitUpdate();
    });
    wrapper.appendChild(addBtn);

    this.appendChild(wrapper);
  }

  private renderPlanEditor(parent: HTMLElement, plans: PricingPlan[], index: number): void {
    const plan = plans[index];
    if (!plan) return;
    const form = document.createElement('div');
    form.className = 'pb-block-pricing__editor';

    const title = document.createElement('h4');
    title.textContent = `Edit: ${escapeHtml(plan.name)}`;
    form.appendChild(title);

    // Name
    form.appendChild(
      this.createField('Name', plan.name, (v) => {
        plan.name = v;
      }),
    );

    // Price
    form.appendChild(
      this.createField('Price', plan.price, (v) => {
        plan.price = v;
      }),
    );

    // Button text
    form.appendChild(
      this.createField('Button text', plan.buttonText, (v) => {
        plan.buttonText = v;
      }),
    );

    // Button URL
    form.appendChild(
      this.createField('Button URL', plan.buttonUrl, (v) => {
        plan.buttonUrl = v;
      }),
    );

    // Highlighted
    const hlGroup = document.createElement('label');
    hlGroup.className = 'pb-block-pricing__field pb-block-pricing__field--inline';
    const hlCheck = document.createElement('input');
    hlCheck.type = 'checkbox';
    hlCheck.checked = plan.highlighted === true;
    hlCheck.addEventListener('change', () => {
      plan.highlighted = hlCheck.checked;
    });
    hlGroup.appendChild(hlCheck);
    const hlLabel = document.createElement('span');
    hlLabel.textContent = 'Highlighted';
    hlGroup.appendChild(hlLabel);
    form.appendChild(hlGroup);

    // Features list
    const featLabel = document.createElement('div');
    featLabel.className = 'pb-block-pricing__feat-label';
    featLabel.textContent = 'Features (one per line):';
    form.appendChild(featLabel);

    const featArea = document.createElement('textarea');
    featArea.className = 'cms-input';
    featArea.rows = 4;
    featArea.value = (plan.features ?? []).join('\n');
    form.appendChild(featArea);

    // Save/close
    const btnRow = document.createElement('div');
    btnRow.className = 'pb-block-pricing__btn-row';

    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'cms-btn cms-btn--primary';
    saveBtn.textContent = 'Save Plan';
    saveBtn.addEventListener('click', () => {
      plan.features = featArea.value
        .split('\n')
        .map((f) => f.trim())
        .filter((f) => f !== '');
      const current = this.getPlans();
      current[index] = { ...plan };
      this.blockData['plans'] = current;
      this.editingPlanIndex = null;
      this.render();
      this.emitUpdate();
    });
    btnRow.appendChild(saveBtn);

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'cms-btn cms-btn--outline';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', () => {
      this.editingPlanIndex = null;
      this.render();
    });
    btnRow.appendChild(cancelBtn);

    form.appendChild(btnRow);
    parent.appendChild(form);
  }

  private createField(label: string, value: string, onInput: (v: string) => void): HTMLElement {
    const group = document.createElement('label');
    group.className = 'pb-block-pricing__field';
    const span = document.createElement('span');
    span.textContent = label;
    group.appendChild(span);
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'cms-input';
    input.value = value;
    input.addEventListener('input', () => onInput(input.value));
    group.appendChild(input);
    return group;
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

customElements.define('cms-pb-pricing', CmsPbPricing);
