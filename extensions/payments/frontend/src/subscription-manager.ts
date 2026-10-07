/**
 * Subscription management UI for Pulsar Payments.
 *
 * Provides a self-service subscription portal where customers can:
 * - View their active subscription and plan details
 * - Cancel, pause, or resume subscriptions
 * - View billing history and download invoices
 */

interface Subscription {
  id: string;
  plan_id: string;
  status: string;
  billing_cycle: string;
  amount: number;
  currency: string;
  has_access: boolean;
  current_period_end: string | null;
  trial_end: string | null;
  cancelled_at: string | null;
  created_at: string;
}

interface SubscriptionManagerConfig {
  apiBase: string;
}

const DEFAULT_CONFIG: SubscriptionManagerConfig = {
  apiBase: '/payments/subscriptions',
};

/**
 * Initialize the subscription manager UI.
 */
export function initSubscriptionManager(
  container: HTMLElement,
  config: Partial<SubscriptionManagerConfig> = {},
): void {
  const cfg = { ...DEFAULT_CONFIG, ...config };

  loadSubscriptions(container, cfg);

  container.addEventListener('click', (e: Event) => {
    const target = e.target as HTMLElement;
    const action = target.dataset.action;
    const subscriptionId = target.dataset.subscriptionId;

    if (!action || !subscriptionId) return;

    e.preventDefault();

    switch (action) {
      case 'cancel':
        handleAction(container, cfg, subscriptionId, 'cancel');
        break;
      case 'pause':
        handleAction(container, cfg, subscriptionId, 'pause');
        break;
      case 'resume':
        handleAction(container, cfg, subscriptionId, 'resume');
        break;
    }
  });
}

async function loadSubscriptions(
  container: HTMLElement,
  config: SubscriptionManagerConfig,
): Promise<void> {
  try {
    const response = await fetch(config.apiBase);
    const data = (await response.json()) as {
      subscriptions: Subscription[];
    };

    renderSubscriptions(container, data.subscriptions);
  } catch {
    container.textContent = 'Failed to load subscriptions.';
  }
}

async function handleAction(
  container: HTMLElement,
  config: SubscriptionManagerConfig,
  subscriptionId: string,
  action: string,
): Promise<void> {
  const response = await fetch(`${config.apiBase}/${subscriptionId}/${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
  });

  if (response.ok) {
    loadSubscriptions(container, config);
  } else {
    const data = (await response.json()) as { error?: string };
    alert(data.error ?? `Failed to ${action} subscription`);
  }
}

function renderSubscriptions(container: HTMLElement, subscriptions: Subscription[]): void {
  // Clear existing content safely
  while (container.firstChild) {
    container.removeChild(container.firstChild);
  }

  if (subscriptions.length === 0) {
    const p = document.createElement('p');
    p.textContent = 'No active subscriptions.';
    container.appendChild(p);
    return;
  }

  for (const sub of subscriptions) {
    const card = document.createElement('div');
    card.className = 'pulsar-subscription-card';

    // Header
    const header = document.createElement('div');
    header.className = 'pulsar-subscription-header';

    const title = document.createElement('h3');
    title.textContent = sub.plan_id;
    header.appendChild(title);

    const badge = document.createElement('span');
    const statusClass = sub.has_access ? 'success' : 'danger';
    badge.className = `pulsar-badge pulsar-badge-${statusClass}`;
    badge.textContent = sub.status;
    header.appendChild(badge);

    card.appendChild(header);

    // Details
    const details = document.createElement('div');
    details.className = 'pulsar-subscription-details';

    const billing = document.createElement('div');
    billing.textContent = `Billing: ${sub.billing_cycle}`;
    details.appendChild(billing);

    const periodEnd = document.createElement('div');
    const periodEndDate = sub.current_period_end
      ? new Date(sub.current_period_end).toLocaleDateString()
      : 'N/A';
    periodEnd.textContent = `Next billing: ${periodEndDate}`;
    details.appendChild(periodEnd);

    const amount = document.createElement('div');
    amount.textContent = `Amount: ${(sub.amount / 100).toFixed(2)} ${sub.currency}`;
    details.appendChild(amount);

    card.appendChild(details);

    // Actions
    const actions = document.createElement('div');
    actions.className = 'pulsar-subscription-actions';

    if (sub.status === 'active' || sub.status === 'trialing') {
      actions.appendChild(
        createActionButton('Cancel', 'cancel', sub.id, 'pulsar-btn pulsar-btn-sm'),
      );
      actions.appendChild(createActionButton('Pause', 'pause', sub.id, 'pulsar-btn pulsar-btn-sm'));
    }

    if (sub.status === 'paused') {
      actions.appendChild(
        createActionButton(
          'Resume',
          'resume',
          sub.id,
          'pulsar-btn pulsar-btn-sm pulsar-btn-primary',
        ),
      );
    }

    card.appendChild(actions);
    container.appendChild(card);
  }
}

function createActionButton(
  text: string,
  action: string,
  subscriptionId: string,
  className: string,
): HTMLButtonElement {
  const btn = document.createElement('button');
  btn.className = className;
  btn.textContent = text;
  btn.dataset.action = action;
  btn.dataset.subscriptionId = subscriptionId;
  return btn;
}

// Auto-initialize
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('.pulsar-subscription-manager').forEach((el) => {
    initSubscriptionManager(el);
  });
});
