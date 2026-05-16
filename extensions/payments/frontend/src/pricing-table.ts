/**
 * Interactive pricing table for Pulsar Payments.
 *
 * Provides billing cycle toggle (monthly/annual) with animated
 * price transitions and highlighted recommended plan.
 */

/**
 * Initialize an interactive pricing table.
 */
export function initPricingTable(container: HTMLElement): void {
  const toggle = container.querySelector('.pulsar-pricing-toggle') as HTMLInputElement | null;

  if (!toggle) return;

  toggle.addEventListener('change', () => {
    const isAnnual = toggle.checked;
    updatePrices(container, isAnnual);
  });
}

function updatePrices(container: HTMLElement, isAnnual: boolean): void {
  container.querySelectorAll<HTMLElement>('.pulsar-pricing-plan').forEach((plan) => {
    const priceEl = plan.querySelector('.pulsar-pricing-price');
    const monthlyPrice = plan.dataset.monthlyPrice;
    const annualPrice = plan.dataset.annualPrice;
    const currency = plan.dataset.currency ?? '$';

    if (priceEl && monthlyPrice && annualPrice) {
      const price = isAnnual ? annualPrice : monthlyPrice;
      const period = isAnnual ? '/year' : '/month';
      priceEl.textContent = `${currency}${price}${period}`;
    }
  });

  // Update toggle label
  const labels = container.querySelectorAll('.pulsar-pricing-toggle-label');
  labels.forEach((label) => {
    const el = label as HTMLElement;
    const isActive =
      (el.dataset.period === 'annual' && isAnnual) ||
      (el.dataset.period === 'monthly' && !isAnnual);
    el.classList.toggle('active', isActive);
  });
}

// Auto-initialize
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('.pulsar-pricing-table-interactive').forEach((el) => {
    initPricingTable(el);
  });
});
