/**
 * Newsletter signup custom element.
 *
 * Renders an email subscription form with honeypot spam protection,
 * CSRF-aware API submission, and accessible state transitions.
 *
 * @example
 * ```html
 * <cms-newsletter-signup
 *   data-locale="en"
 *   data-source="footer"
 *   data-heading="Stay updated"
 *   data-description="Get the latest news delivered to your inbox."
 *   data-button-text="Join now"
 *   data-success-message="You're in! Check your inbox."
 *   data-api-url="/api/cms/newsletter/subscribe">
 * </cms-newsletter-signup>
 * ```
 */

import { cmsApi } from '../utils/api';
import { escapeHtml } from '../utils/escapeHtml';

type SignupState = 'idle' | 'loading' | 'success' | 'error';

interface ValidationErrorResponse {
  readonly errors?: Record<string, string[]>;
  readonly message?: string;
}

export class NewsletterSignup extends HTMLElement {
  private state: SignupState = 'idle';
  private errorMessage = '';
  private formEl: HTMLFormElement | null = null;
  private submitBtn: HTMLButtonElement | null = null;
  private errorEl: HTMLElement | null = null;

  connectedCallback(): void {
    this.render();
  }

  disconnectedCallback(): void {
    this.formEl = null;
    this.submitBtn = null;
    this.errorEl = null;
  }

  private get locale(): string {
    return this.dataset.locale ?? '';
  }

  private get source(): string {
    return this.dataset.source ?? 'signup_form';
  }

  private get successMessage(): string {
    return this.dataset.successMessage ?? 'Check your email to confirm your subscription!';
  }

  private get heading(): string {
    return this.dataset.heading ?? 'Subscribe to our newsletter';
  }

  private get description(): string | undefined {
    return this.dataset.description;
  }

  private get buttonText(): string {
    return this.dataset.buttonText ?? 'Subscribe';
  }

  private get apiUrl(): string {
    return this.dataset.apiUrl ?? '/api/cms/newsletter/subscribe';
  }

  private render(): void {
    this.classList.add('cms-newsletter-signup');

    const wrapper = document.createElement('div');
    wrapper.className = 'cms-newsletter-signup__inner';

    // Heading
    const headingEl = document.createElement('h3');
    headingEl.className = 'cms-newsletter-signup__heading';
    headingEl.textContent = this.heading;
    wrapper.appendChild(headingEl);

    // Description (optional)
    const descText = this.description;
    if (descText !== undefined && descText !== '') {
      const descEl = document.createElement('p');
      descEl.className = 'cms-newsletter-signup__description';
      descEl.textContent = descText;
      wrapper.appendChild(descEl);
    }

    // Form
    const form = document.createElement('form');
    form.className = 'cms-newsletter-signup__form';
    form.setAttribute('novalidate', '');
    form.addEventListener('submit', (e) => {
      void this.handleSubmit(e);
    });

    // Email input
    const emailLabel = document.createElement('label');
    emailLabel.className = 'cms-newsletter-signup__label';
    emailLabel.setAttribute('for', 'cms-newsletter-email');

    const emailLabelText = document.createElement('span');
    emailLabelText.className = 'cms-newsletter-signup__label-text';
    emailLabelText.textContent = 'Email address';
    emailLabel.appendChild(emailLabelText);

    const emailInput = document.createElement('input');
    emailInput.className = 'cms-newsletter-signup__input';
    emailInput.type = 'email';
    emailInput.id = 'cms-newsletter-email';
    emailInput.name = 'email';
    emailInput.required = true;
    emailInput.placeholder = 'Enter your email';
    emailInput.setAttribute('autocomplete', 'email');
    emailInput.setAttribute('aria-required', 'true');

    emailLabel.appendChild(emailInput);
    form.appendChild(emailLabel);

    // Honeypot field — accessible-hidden, invisible to real users
    const honeypotWrapper = document.createElement('div');
    honeypotWrapper.className = 'cms-newsletter-signup__honeypot';
    honeypotWrapper.setAttribute('aria-hidden', 'true');

    const honeypotLabel = document.createElement('label');
    honeypotLabel.textContent = 'Website URL';

    const honeypotInput = document.createElement('input');
    honeypotInput.type = 'text';
    honeypotInput.name = 'website_url';
    honeypotInput.tabIndex = -1;
    honeypotInput.setAttribute('autocomplete', 'off');

    honeypotLabel.appendChild(honeypotInput);
    honeypotWrapper.appendChild(honeypotLabel);
    form.appendChild(honeypotWrapper);

    // Submit button
    const submitBtn = document.createElement('button');
    submitBtn.className = 'cms-newsletter-signup__button';
    submitBtn.type = 'submit';
    submitBtn.textContent = this.buttonText;
    form.appendChild(submitBtn);
    this.submitBtn = submitBtn;

    wrapper.appendChild(form);
    this.formEl = form;

    // Error display
    const errorEl = document.createElement('div');
    errorEl.className = 'cms-newsletter-signup__error';
    errorEl.setAttribute('role', 'alert');
    errorEl.setAttribute('aria-live', 'assertive');
    errorEl.style.display = 'none';
    wrapper.appendChild(errorEl);
    this.errorEl = errorEl;

    this.appendChild(wrapper);
  }

  private async handleSubmit(event: Event): Promise<void> {
    event.preventDefault();

    if (this.state === 'loading' || this.state === 'success') {
      return;
    }

    if (!this.formEl) {
      return;
    }

    const formData = new FormData(this.formEl);
    const email = (formData.get('email') as string | null) ?? '';
    const honeypot = (formData.get('website_url') as string | null) ?? '';

    // Client-side email validation
    if (email === '' || !email.includes('@')) {
      this.showError('Please enter a valid email address.');
      return;
    }

    // Honeypot check — if filled, silently show success to not reveal detection
    if (honeypot !== '') {
      this.showSuccess();
      return;
    }

    this.setState('loading');

    try {
      const response = await cmsApi(this.apiUrl, {
        method: 'POST',
        body: JSON.stringify({
          email,
          locale: this.locale,
          source: this.source,
        }),
      });

      if (response.ok) {
        this.showSuccess();
        this.dispatchEvent(
          new CustomEvent('subscribed', {
            detail: { email },
            bubbles: true,
          }),
        );
        return;
      }

      if (response.status === 422) {
        const data: ValidationErrorResponse = (await response.json()) as ValidationErrorResponse;
        const firstError = this.extractValidationError(data);
        this.showError(firstError);
        return;
      }

      if (response.status === 429) {
        this.showError('Too many attempts. Please try again later.');
        return;
      }

      this.showError('Something went wrong. Please try again.');
    } catch {
      this.showError('Something went wrong. Please try again.');
    }
  }

  private extractValidationError(data: ValidationErrorResponse): string {
    if (data.errors) {
      const firstField = Object.keys(data.errors)[0];
      if (firstField !== undefined) {
        const fieldErrors = data.errors[firstField];
        if (fieldErrors && fieldErrors.length > 0 && fieldErrors[0] !== undefined) {
          return fieldErrors[0];
        }
      }
    }

    if (data.message) {
      return data.message;
    }

    return 'Please check your input and try again.';
  }

  private setState(newState: SignupState): void {
    this.state = newState;

    if (this.submitBtn) {
      this.submitBtn.disabled = newState === 'loading';
      this.submitBtn.classList.toggle(
        'cms-newsletter-signup__button--loading',
        newState === 'loading',
      );
    }
  }

  private showSuccess(): void {
    this.setState('success');

    // Replace form contents with success message
    const inner = this.querySelector('.cms-newsletter-signup__inner');
    if (!inner) {
      return;
    }

    // Clear the inner container
    while (inner.firstChild) {
      inner.removeChild(inner.firstChild);
    }

    const successEl = document.createElement('div');
    successEl.className = 'cms-newsletter-signup__success';
    successEl.setAttribute('role', 'status');

    const checkmark = document.createElement('span');
    checkmark.className = 'cms-newsletter-signup__checkmark';
    checkmark.textContent = '\u2713'; // checkmark
    checkmark.setAttribute('aria-hidden', 'true');
    successEl.appendChild(checkmark);

    const messageEl = document.createElement('p');
    messageEl.className = 'cms-newsletter-signup__success-message';
    messageEl.textContent = this.successMessage;
    successEl.appendChild(messageEl);

    inner.appendChild(successEl);

    this.formEl = null;
    this.submitBtn = null;
    this.errorEl = null;
  }

  private showError(message: string): void {
    this.setState('error');
    this.errorMessage = message;

    if (this.errorEl) {
      this.errorEl.textContent = escapeHtml(this.errorMessage);
      this.errorEl.style.display = '';
    }
  }
}

customElements.define('cms-newsletter-signup', NewsletterSignup);
