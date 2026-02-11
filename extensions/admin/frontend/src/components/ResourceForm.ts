/**
 * Resource form component for create/edit.
 */
import { api } from '../api.js';
import type { FieldDefinition } from '../types.js';

export interface ResourceFormConfig {
  container: HTMLElement;
  resource: string;
  fields: FieldDefinition[];
  data: Record<string, unknown>;
  mode: 'create' | 'edit';
  id?: string;
  onSuccess?: () => void;
  onError?: (message: string) => void;
}

function inputTypeFor(fieldType: string): string {
  switch (fieldType) {
    case 'integer':
    case 'float':
      return 'number';
    case 'email':
      return 'email';
    case 'url':
      return 'url';
    case 'date':
      return 'date';
    case 'datetime':
      return 'datetime-local';
    case 'boolean':
      return 'checkbox';
    default:
      return 'text';
  }
}

export function renderResourceForm(config: ResourceFormConfig): void {
  const { container, resource, fields, data, mode, id, onSuccess, onError } = config;
  const formFields = fields.filter((f) => f.visibleOnForm && f.editable);

  const form = document.createElement('form');
  form.className = 'admin-form';

  for (const field of formFields) {
    const group = document.createElement('div');
    group.className = 'admin-form__group';

    const label = document.createElement('label');
    label.htmlFor = `field-${field.name}`;
    label.className = 'admin-form__label';
    label.textContent = field.label;
    group.appendChild(label);

    if (field.helpText) {
      const help = document.createElement('small');
      help.className = 'admin-form__help';
      help.textContent = field.helpText;
      group.appendChild(help);
    }

    const value = data[field.name];

    if (field.type === 'text') {
      const textarea = document.createElement('textarea');
      textarea.id = `field-${field.name}`;
      textarea.name = field.name;
      textarea.className = 'admin-form__textarea';
      textarea.textContent = value != null ? String(value) : '';
      if (field.placeholder) textarea.placeholder = field.placeholder;
      group.appendChild(textarea);
    } else if (field.type === 'enum' && field.enumValues.length > 0) {
      const select = document.createElement('select');
      select.id = `field-${field.name}`;
      select.name = field.name;
      select.className = 'admin-form__select';

      const defaultOpt = document.createElement('option');
      defaultOpt.value = '';
      defaultOpt.textContent = 'Select...';
      select.appendChild(defaultOpt);

      for (const enumVal of field.enumValues) {
        const opt = document.createElement('option');
        opt.value = enumVal;
        opt.textContent = enumVal;
        if (String(value) === enumVal) opt.selected = true;
        select.appendChild(opt);
      }
      group.appendChild(select);
    } else {
      const input = document.createElement('input');
      input.type = inputTypeFor(field.type);
      input.id = `field-${field.name}`;
      input.name = field.name;
      input.className = 'admin-form__input';
      if (field.type === 'boolean') {
        input.checked = Boolean(value);
      } else {
        input.value = value != null ? String(value) : '';
      }
      if (field.placeholder) input.placeholder = field.placeholder;
      group.appendChild(input);
    }

    form.appendChild(group);
  }

  const actions = document.createElement('div');
  actions.className = 'admin-form__actions';

  const submitBtn = document.createElement('button');
  submitBtn.type = 'submit';
  submitBtn.className = 'admin-btn admin-btn--primary';
  submitBtn.textContent = mode === 'create' ? 'Create' : 'Save Changes';
  actions.appendChild(submitBtn);
  form.appendChild(actions);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(form);
    const payload: Record<string, unknown> = {};

    for (const field of formFields) {
      if (field.type === 'boolean') {
        payload[field.name] = formData.has(field.name);
      } else {
        payload[field.name] = formData.get(field.name) ?? '';
      }
    }

    try {
      if (mode === 'create') {
        await api.createRecord(resource, payload);
      } else {
        await api.updateRecord(resource, id!, payload);
      }
      onSuccess?.();
    } catch (err) {
      onError?.(err instanceof Error ? err.message : 'Unknown error');
    }
  });

  container.innerHTML = '';
  container.appendChild(form);
}
