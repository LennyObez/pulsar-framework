/**
 * Individual form field component.
 */
import type { FieldDefinition } from '../types.js';

export interface FormFieldConfig {
  field: FieldDefinition;
  value: unknown;
  error?: string;
}

export function renderFormField(container: HTMLElement, config: FormFieldConfig): void {
  const { field, value, error } = config;

  const group = document.createElement('div');
  group.className = `admin-form__group${error ? ' admin-form__group--error' : ''}`;

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

  let input: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;

  if (field.type === 'text') {
    input = document.createElement('textarea');
    input.className = 'admin-form__textarea';
    input.textContent = value != null ? String(value) : '';
  } else if (field.type === 'enum' && field.enumValues.length > 0) {
    input = document.createElement('select');
    input.className = 'admin-form__select';
    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = 'Select...';
    input.appendChild(defaultOpt);
    for (const enumVal of field.enumValues) {
      const opt = document.createElement('option');
      opt.value = enumVal;
      opt.textContent = enumVal;
      if (String(value) === enumVal) opt.selected = true;
      input.appendChild(opt);
    }
  } else if (field.type === 'boolean') {
    input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'admin-form__checkbox';
    (input as HTMLInputElement).checked = Boolean(value);
  } else {
    input = document.createElement('input');
    input.className = 'admin-form__input';
    switch (field.type) {
      case 'integer':
      case 'float':
        input.type = 'number';
        break;
      case 'email':
        input.type = 'email';
        break;
      case 'url':
        input.type = 'url';
        break;
      case 'date':
        input.type = 'date';
        break;
      case 'datetime':
        input.type = 'datetime-local';
        break;
      default:
        input.type = 'text';
    }
    input.value = value != null ? String(value) : '';
  }

  input.id = `field-${field.name}`;
  input.name = field.name;
  if (field.placeholder) {
    if ('placeholder' in input) {
      input.placeholder = field.placeholder;
    }
  }
  group.appendChild(input);

  if (error) {
    const errorEl = document.createElement('span');
    errorEl.className = 'admin-form__error';
    errorEl.textContent = error;
    group.appendChild(errorEl);
  }

  container.appendChild(group);
}
