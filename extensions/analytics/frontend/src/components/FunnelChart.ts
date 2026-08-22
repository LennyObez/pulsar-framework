import type { FunnelResult } from '../types';

/**
 * Renders a horizontal funnel chart showing step-by-step conversion
 * with drop-off percentages between each step.
 */
export function renderFunnelChart(container: HTMLElement, result: FunnelResult): void {
  container.textContent = '';

  if (result.steps.length === 0) {
    const empty = document.createElement('p');
    empty.className = 'funnel-empty';
    empty.textContent = 'No funnel data available';
    container.appendChild(empty);
    return;
  }

  // Overall conversion header
  const header = document.createElement('div');
  header.className = 'funnel-header';

  const title = document.createElement('span');
  title.className = 'funnel-header__title';
  title.textContent = 'Overall Conversion';
  header.appendChild(title);

  const rate = document.createElement('span');
  rate.className = 'funnel-header__rate';
  rate.textContent = `${result.overall_conversion_rate.toFixed(1)}%`;
  header.appendChild(rate);

  container.appendChild(header);

  // Steps
  const maxVisitors = Math.max(...result.steps.map((s) => s.visitors), 1);
  const stepsContainer = document.createElement('div');
  stepsContainer.className = 'funnel-steps';

  for (let i = 0; i < result.steps.length; i++) {
    const step = result.steps[i];
    const pct = (step.visitors / maxVisitors) * 100;

    const stepEl = document.createElement('div');
    stepEl.className = 'funnel-step';

    // Step label
    const label = document.createElement('div');
    label.className = 'funnel-step__label';

    const position = document.createElement('span');
    position.className = 'funnel-step__position';
    position.textContent = `Step ${step.position}`;
    label.appendChild(position);

    const name = document.createElement('span');
    name.className = 'funnel-step__name';
    name.textContent = step.name;
    label.appendChild(name);

    stepEl.appendChild(label);

    // Bar
    const barWrap = document.createElement('div');
    barWrap.className = 'funnel-step__bar-wrap';

    const bar = document.createElement('div');
    bar.className = 'funnel-step__bar';
    bar.style.width = `${pct.toFixed(1)}%`;
    barWrap.appendChild(bar);

    stepEl.appendChild(barWrap);

    // Metrics
    const metrics = document.createElement('div');
    metrics.className = 'funnel-step__metrics';

    const visitors = document.createElement('span');
    visitors.className = 'funnel-step__visitors';
    visitors.textContent = `${step.visitors.toLocaleString()} visitors`;
    metrics.appendChild(visitors);

    const convRate = document.createElement('span');
    convRate.className = 'funnel-step__conversion';
    convRate.textContent = `${step.conversion_rate.toFixed(1)}% conversion`;
    metrics.appendChild(convRate);

    stepEl.appendChild(metrics);
    stepsContainer.appendChild(stepEl);

    // Drop-off indicator between steps
    if (i < result.steps.length - 1) {
      const dropOff = document.createElement('div');
      dropOff.className = 'funnel-dropoff';

      const arrow = document.createElement('span');
      arrow.className = 'funnel-dropoff__arrow';
      arrow.textContent = '\u2193';
      dropOff.appendChild(arrow);

      const dropRate = document.createElement('span');
      dropRate.className = 'funnel-dropoff__rate';
      dropRate.textContent = `${step.drop_off_rate.toFixed(1)}% drop-off`;
      dropOff.appendChild(dropRate);

      stepsContainer.appendChild(dropOff);
    }
  }

  container.appendChild(stepsContainer);
}
