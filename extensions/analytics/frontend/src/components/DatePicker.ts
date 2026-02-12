import type { DateRange } from '../types';

type ChangeHandler = (range: DateRange) => void;

const PRESETS: Array<{ label: string; days: number }> = [
  { label: 'Today', days: 0 },
  { label: '7D', days: 7 },
  { label: '30D', days: 30 },
  { label: '12M', days: 365 },
];

export function renderDatePicker(container: HTMLElement, onChange: ChangeHandler): void {
  container.innerHTML = `
    <div class="date-picker__presets">
      ${PRESETS.map(
        (p, i) => `
        <button class="date-picker__btn${i === 1 ? ' date-picker__btn--active' : ''}"
                data-days="${p.days}">${p.label}</button>
      `,
      ).join('')}
    </div>
    <div class="date-picker__custom">
      <input type="date" class="date-picker__input" id="dp-from">
      <span class="date-picker__sep">—</span>
      <input type="date" class="date-picker__input" id="dp-to">
    </div>
  `;

  const buttons = container.querySelectorAll<HTMLButtonElement>('.date-picker__btn');
  const fromInput = container.querySelector<HTMLInputElement>('#dp-from')!;
  const toInput = container.querySelector<HTMLInputElement>('#dp-to')!;

  function setRange(days: number): DateRange {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - days);

    return {
      from: from.toISOString().split('T')[0],
      to: to.toISOString().split('T')[0],
    };
  }

  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      buttons.forEach((b) => b.classList.remove('date-picker__btn--active'));
      btn.classList.add('date-picker__btn--active');
      const days = Number(btn.dataset.days);
      const range = setRange(days);
      fromInput.value = range.from;
      toInput.value = range.to;
      onChange(range);
    });
  });

  const handleCustom = (): void => {
    if (fromInput.value && toInput.value) {
      buttons.forEach((b) => b.classList.remove('date-picker__btn--active'));
      onChange({ from: fromInput.value, to: toInput.value });
    }
  };

  fromInput.addEventListener('change', handleCustom);
  toInput.addEventListener('change', handleCustom);

  // Default: 7 days
  const defaultRange = setRange(7);
  fromInput.value = defaultRange.from;
  toInput.value = defaultRange.to;
  onChange(defaultRange);
}
