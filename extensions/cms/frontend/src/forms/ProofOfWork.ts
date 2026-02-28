/**
 * Proof-of-work computation for form spam prevention.
 *
 * On form load, reads the challenge from a data attribute,
 * finds a nonce such that SHA-256(challenge + nonce) starts with "0000",
 * and sets the result in a hidden form field.
 */

const POW_PREFIX = '0000';

async function sha256Hex(input: string): Promise<string> {
  const encoder = new TextEncoder();
  const data = encoder.encode(input);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));

  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function findNonce(challenge: string): Promise<string> {
  let nonce = 0;

  while (true) {
    const candidate = String(nonce);
    const hash = await sha256Hex(challenge + candidate);

    if (hash.startsWith(POW_PREFIX)) {
      return candidate;
    }

    nonce++;

    // Yield to the event loop every 1000 iterations to avoid blocking
    if (nonce % 1000 === 0) {
      await new Promise<void>((resolve) => {
        setTimeout(resolve, 0);
      });
    }
  }
}

/**
 * Initialize proof-of-work for all forms with [data-pow-challenge].
 *
 * Call this on DOMContentLoaded or after dynamic form insertion.
 */
export function initProofOfWork(root: ParentNode = document): void {
  const forms = root.querySelectorAll<HTMLFormElement>('form[data-pow-challenge]');

  forms.forEach((form) => {
    const challenge = form.dataset.powChallenge;

    if (!challenge) {
      return;
    }

    // Show indicator
    const indicator = document.createElement('span');

    indicator.className = 'cms-pow-indicator';
    indicator.textContent = 'Verifying...';
    indicator.setAttribute('aria-live', 'polite');

    const submitBtn = form.querySelector<HTMLButtonElement>('button[type="submit"]');

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.parentElement?.insertBefore(indicator, submitBtn);
    }

    // Compute PoW
    findNonce(challenge)
      .then((nonce) => {
        // Set hidden field
        let nonceInput = form.querySelector<HTMLInputElement>('input[name="_pow_nonce"]');

        if (!nonceInput) {
          nonceInput = document.createElement('input');
          nonceInput.type = 'hidden';
          nonceInput.name = '_pow_nonce';
          form.appendChild(nonceInput);
        }

        nonceInput.value = nonce;

        // Re-enable submit
        if (submitBtn) {
          submitBtn.disabled = false;
        }

        indicator.textContent = '';
        indicator.style.display = 'none';
      })
      .catch(() => {
        // On failure, still allow submission (server will reject if invalid)
        if (submitBtn) {
          submitBtn.disabled = false;
        }

        indicator.textContent = '';
        indicator.style.display = 'none';
      });
  });
}

// Auto-initialize on DOMContentLoaded
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initProofOfWork());
  } else {
    initProofOfWork();
  }
}
