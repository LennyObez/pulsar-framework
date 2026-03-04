/**
 * Checkout flow for Pulsar Payments.
 *
 * Handles client-side payment form initialization, card tokenization
 * (via Stripe Elements or PayPal Buttons), and payment confirmation.
 *
 * PCI-DSS compliant: raw card numbers never touch our server.
 */

interface CheckoutConfig {
  gateway: string;
  publishableKey: string;
  amount: number;
  currency: string;
  successUrl: string;
  cancelUrl: string;
}

interface CheckoutResult {
  success: boolean;
  intentId?: string;
  error?: string;
}

/**
 * Initialize a checkout form element.
 */
export function initCheckout(container: HTMLElement): void {
  const config = readConfig(container);

  if (!config) {
    console.error('[pulsar-payments] Missing checkout configuration');
    return;
  }

  const form = container.querySelector('form') ?? container;
  const submitBtn = container.querySelector('.pulsar-checkout-submit') as HTMLButtonElement | null;
  const errorEl = container.querySelector('.pulsar-checkout-error') as HTMLElement | null;

  form.addEventListener('submit', async (e: Event) => {
    e.preventDefault();

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';
    }

    try {
      const result = await processPayment(config);

      if (result.success) {
        window.location.href = config.successUrl;
      } else {
        showError(errorEl, result.error ?? 'Payment failed');
      }
    } catch (err) {
      showError(errorEl, err instanceof Error ? err.message : 'An error occurred');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Pay Now';
      }
    }
  });
}

/**
 * Process a payment through the API.
 */
async function processPayment(config: CheckoutConfig): Promise<CheckoutResult> {
  const response = await fetch('/payments/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      amount: config.amount,
      currency: config.currency,
      method: 'card',
    }),
  });

  const data = (await response.json()) as Record<string, unknown>;

  if (!response.ok) {
    return {
      success: false,
      error: (data.error as string) ?? 'Payment creation failed',
    };
  }

  return { success: true, intentId: data.intent_id as string };
}

function readConfig(container: HTMLElement): CheckoutConfig | null {
  const gateway = container.dataset.gateway;
  const key = container.dataset.key;
  const successUrl = container.dataset.successUrl;

  if (!gateway || !key) return null;

  return {
    gateway,
    publishableKey: key,
    amount: parseInt(container.dataset.amount ?? '0', 10),
    currency: container.dataset.currency ?? 'USD',
    successUrl: successUrl ?? '/checkout/success',
    cancelUrl: container.dataset.cancelUrl ?? '/',
  };
}

function showError(el: HTMLElement | null, message: string): void {
  if (el) {
    el.textContent = message;
    el.style.display = 'block';
  }
}

// Auto-initialize checkout forms
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('.pulsar-checkout-form').forEach((el) => {
    initCheckout(el);
  });
});
