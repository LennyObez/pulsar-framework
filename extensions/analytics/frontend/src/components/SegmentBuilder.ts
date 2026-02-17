import type { DateRange, SegmentDefinition } from '../types';
import { countSegmentVisitors, createSegment, deleteSegment } from '../api';

const DIMENSIONS = [
  'country',
  'browser',
  'os',
  'device_type',
  'referrer_source',
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'entry_page',
  'exit_page',
  'page_path',
  'event_name',
] as const;

const OPERATORS = [
  'equals',
  'not_equals',
  'contains',
  'not_contains',
  'starts_with',
  'ends_with',
  'matches',
  'not_matches',
] as const;

/**
 * Renders a segment management UI with a list of existing segments,
 * a creation form for new segments, and visitor count evaluation.
 */
export function renderSegmentBuilder(
  container: HTMLElement,
  segments: SegmentDefinition[],
  siteId: string,
  range: DateRange,
): void {
  container.textContent = '';

  const wrapper = document.createElement('div');
  wrapper.className = 'segment-builder';

  // Existing segments list
  const listSection = document.createElement('div');
  listSection.className = 'segment-list';

  const listTitle = document.createElement('h3');
  listTitle.className = 'segment-list__title';
  listTitle.textContent = 'Audience Segments';
  listSection.appendChild(listTitle);

  if (segments.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'segment-list__empty';
    empty.textContent = 'No segments defined yet';
    listSection.appendChild(empty);
  } else {
    for (const segment of segments) {
      const item = document.createElement('div');
      item.className = 'segment-list__item';

      const nameEl = document.createElement('span');
      nameEl.className = 'segment-list__name';
      nameEl.textContent = segment.name;
      item.appendChild(nameEl);

      const filterCount = document.createElement('span');
      filterCount.className = 'segment-list__filters';
      filterCount.textContent = `${segment.filters.length} filter${segment.filters.length !== 1 ? 's' : ''}`;
      item.appendChild(filterCount);

      // Count button
      const countBtn = document.createElement('button');
      countBtn.className = 'btn btn-sm segment-list__count';
      countBtn.type = 'button';
      countBtn.textContent = 'Count';
      countBtn.addEventListener('click', async () => {
        countBtn.disabled = true;
        countBtn.textContent = '\u2026';
        try {
          const result = await countSegmentVisitors(segment.id, range);
          countBtn.textContent = `${result.visitors.toLocaleString()} visitors`;
        } catch {
          countBtn.textContent = 'Error';
        }
      });
      item.appendChild(countBtn);

      // Delete button
      const delBtn = document.createElement('button');
      delBtn.className = 'btn btn-sm btn-danger segment-list__delete';
      delBtn.type = 'button';
      delBtn.textContent = 'Delete';
      delBtn.addEventListener('click', async () => {
        if (confirm('Delete this segment?')) {
          await deleteSegment(segment.id);
          renderSegmentBuilder(
            container,
            segments.filter((s) => s.id !== segment.id),
            siteId,
            range,
          );
        }
      });
      item.appendChild(delBtn);

      listSection.appendChild(item);
    }
  }

  wrapper.appendChild(listSection);

  // Creation form
  const formSection = document.createElement('div');
  formSection.className = 'segment-form';

  const formTitle = document.createElement('h3');
  formTitle.className = 'segment-form__title';
  formTitle.textContent = 'Create Segment';
  formSection.appendChild(formTitle);

  // Name field
  const nameGroup = document.createElement('div');
  nameGroup.className = 'segment-form__group';

  const nameLabel = document.createElement('label');
  nameLabel.textContent = 'Segment Name';
  nameGroup.appendChild(nameLabel);

  const nameInput = document.createElement('input');
  nameInput.type = 'text';
  nameInput.className = 'segment-form__input';
  nameInput.placeholder = 'e.g., Mobile US visitors';
  nameGroup.appendChild(nameInput);

  formSection.appendChild(nameGroup);

  // Filter rows
  const filtersContainer = document.createElement('div');
  filtersContainer.className = 'segment-form__filters';
  formSection.appendChild(filtersContainer);

  const filters: Array<{ dimension: string; operator: string; value: string }> = [];

  function addFilterRow(): void {
    const filter = { dimension: DIMENSIONS[0], operator: OPERATORS[0], value: '' };
    filters.push(filter);
    const idx = filters.length - 1;

    const row = document.createElement('div');
    row.className = 'segment-filter-row';

    const dimSelect = document.createElement('select');
    dimSelect.className = 'segment-filter__dim';
    for (const dim of DIMENSIONS) {
      const opt = document.createElement('option');
      opt.value = dim;
      opt.textContent = dim.replace(/_/g, ' ');
      dimSelect.appendChild(opt);
    }
    dimSelect.addEventListener('change', () => {
      filters[idx].dimension = dimSelect.value;
    });
    row.appendChild(dimSelect);

    const opSelect = document.createElement('select');
    opSelect.className = 'segment-filter__op';
    for (const op of OPERATORS) {
      const opt = document.createElement('option');
      opt.value = op;
      opt.textContent = op.replace(/_/g, ' ');
      opSelect.appendChild(opt);
    }
    opSelect.addEventListener('change', () => {
      filters[idx].operator = opSelect.value;
    });
    row.appendChild(opSelect);

    const valInput = document.createElement('input');
    valInput.type = 'text';
    valInput.className = 'segment-filter__value';
    valInput.placeholder = 'Value';
    valInput.addEventListener('input', () => {
      filters[idx].value = valInput.value;
    });
    row.appendChild(valInput);

    const removeBtn = document.createElement('button');
    removeBtn.type = 'button';
    removeBtn.className = 'btn btn-sm segment-filter__remove';
    removeBtn.textContent = '\u00D7';
    removeBtn.addEventListener('click', () => {
      filters.splice(idx, 1);
      row.remove();
    });
    row.appendChild(removeBtn);

    filtersContainer.appendChild(row);
  }

  // Add initial filter row
  addFilterRow();

  // Add filter button
  const addFilterBtn = document.createElement('button');
  addFilterBtn.type = 'button';
  addFilterBtn.className = 'btn btn-sm segment-form__add-filter';
  addFilterBtn.textContent = '+ Add Filter';
  addFilterBtn.addEventListener('click', addFilterRow);
  formSection.appendChild(addFilterBtn);

  // Submit
  const submitBtn = document.createElement('button');
  submitBtn.type = 'button';
  submitBtn.className = 'btn segment-form__submit';
  submitBtn.textContent = 'Create Segment';
  submitBtn.addEventListener('click', async () => {
    const name = nameInput.value.trim();
    if (!name) return;

    const validFilters = filters.filter((f) => f.value.trim() !== '');
    if (validFilters.length === 0) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating\u2026';

    try {
      const created = await createSegment({
        site_id: siteId,
        name,
        filters: validFilters,
      });
      renderSegmentBuilder(container, [...segments, created], siteId, range);
    } catch {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Create Segment';
    }
  });
  formSection.appendChild(submitBtn);

  wrapper.appendChild(formSection);
  container.appendChild(wrapper);
}
