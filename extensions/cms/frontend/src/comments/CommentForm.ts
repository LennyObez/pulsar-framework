/**
 * Comment submission form custom element.
 *
 * Supports guest and authenticated modes, reply context, honeypot anti-spam
 * field, character counter, and CSRF-protected submission.
 *
 * @example
 * ```html
 * <!-- Guest mode (no user attributes) -->
 * <cms-comment-form
 *   data-content-id="abc-123"
 *   data-api-url="/api/cms/comments">
 * </cms-comment-form>
 *
 * <!-- Authenticated mode -->
 * <cms-comment-form
 *   data-content-id="abc-123"
 *   data-api-url="/api/cms/comments"
 *   data-user-name="Jane Doe"
 *   data-user-avatar="/avatars/jane.jpg">
 * </cms-comment-form>
 *
 * <!-- Reply mode -->
 * <cms-comment-form
 *   data-content-id="abc-123"
 *   data-parent-id="parent-456"
 *   data-api-url="/api/cms/comments">
 * </cms-comment-form>
 * ```
 */

import { cmsApi } from '../utils/api';

const MAX_BODY_LENGTH = 5000;

export class CommentForm extends HTMLElement {
  private contentId = '';
  private parentId: string | null = null;
  private apiUrl = '/api/cms/comments';
  private userName: string | null = null;
  private userAvatar: string | null = null;
  private formEl: HTMLFormElement | null = null;
  private textareaEl: HTMLTextAreaElement | null = null;
  private counterEl: HTMLElement | null = null;
  private errorEl: HTMLElement | null = null;
  private submitBtn: HTMLButtonElement | null = null;
  private isSubmitting = false;

  connectedCallback(): void {
    this.contentId = this.dataset.contentId ?? '';
    this.parentId = this.dataset.parentId ?? null;
    this.apiUrl = this.dataset.apiUrl ?? '/api/cms/comments';
    this.userName = this.dataset.userName ?? null;
    this.userAvatar = this.dataset.userAvatar ?? null;

    this.buildForm();
  }

  disconnectedCallback(): void {
    // Listeners are on elements inside this custom element; GC handles cleanup.
  }

  private get isAuthenticated(): boolean {
    return this.userName !== null;
  }

  private get isReply(): boolean {
    return this.parentId !== null;
  }

  private buildForm(): void {
    this.classList.add('cms-comment-form');

    this.formEl = document.createElement('form');
    this.formEl.className = 'cms-comment-form__form';
    this.formEl.noValidate = true;

    // Authenticated user banner
    if (this.isAuthenticated) {
      const userBanner = document.createElement('div');
      userBanner.className = 'cms-comment-form__user';

      if (this.userAvatar) {
        const avatar = document.createElement('img');
        avatar.className = 'cms-comment-form__user-avatar';
        avatar.src = this.userAvatar;
        avatar.alt = '';
        avatar.width = 32;
        avatar.height = 32;
        userBanner.appendChild(avatar);
      }

      const nameEl = document.createElement('span');
      nameEl.className = 'cms-comment-form__user-name';
      nameEl.textContent = this.userName ?? '';
      userBanner.appendChild(nameEl);

      this.formEl.appendChild(userBanner);
    } else {
      // Guest fields: name and email
      const guestFields = document.createElement('div');
      guestFields.className = 'cms-comment-form__guest-fields';

      const nameGroup = this.createFieldGroup('comment-name', 'Name', 'text', 'name', true);
      guestFields.appendChild(nameGroup);

      const emailGroup = this.createFieldGroup('comment-email', 'Email', 'email', 'email', true);
      guestFields.appendChild(emailGroup);

      this.formEl.appendChild(guestFields);
    }

    // Textarea
    const textareaGroup = document.createElement('div');
    textareaGroup.className = 'cms-comment-form__group';

    const textareaLabel = document.createElement('label');
    textareaLabel.className = 'cms-comment-form__label';
    textareaLabel.htmlFor = this.fieldId('comment-body');
    textareaLabel.textContent = this.isReply ? 'Write a reply\u2026' : 'Write a comment\u2026';

    this.textareaEl = document.createElement('textarea');
    this.textareaEl.className = 'cms-comment-form__textarea';
    this.textareaEl.id = this.fieldId('comment-body');
    this.textareaEl.name = 'body';
    this.textareaEl.rows = this.isReply ? 3 : 5;
    this.textareaEl.maxLength = MAX_BODY_LENGTH;
    this.textareaEl.required = true;
    this.textareaEl.placeholder = this.isReply ? 'Write your reply...' : 'Share your thoughts...';
    this.textareaEl.setAttribute('aria-describedby', this.fieldId('comment-counter'));

    this.textareaEl.addEventListener('input', () => {
      this.updateCounter();
    });

    this.counterEl = document.createElement('div');
    this.counterEl.className = 'cms-comment-form__counter';
    this.counterEl.id = this.fieldId('comment-counter');
    this.counterEl.setAttribute('aria-live', 'polite');
    this.counterEl.textContent = `0 / ${MAX_BODY_LENGTH}`;

    textareaGroup.appendChild(textareaLabel);
    textareaGroup.appendChild(this.textareaEl);
    textareaGroup.appendChild(this.counterEl);
    this.formEl.appendChild(textareaGroup);

    // Honeypot field (hidden from real users, traps bots)
    const honeypot = document.createElement('div');
    honeypot.className = 'cms-comment__honeypot';
    honeypot.setAttribute('aria-hidden', 'true');

    const honeypotLabel = document.createElement('label');
    honeypotLabel.htmlFor = this.fieldId('comment-website');
    honeypotLabel.textContent = 'Website';

    const honeypotInput = document.createElement('input');
    honeypotInput.type = 'text';
    honeypotInput.id = this.fieldId('comment-website');
    honeypotInput.name = 'website';
    honeypotInput.tabIndex = -1;
    honeypotInput.autocomplete = 'off';

    honeypot.appendChild(honeypotLabel);
    honeypot.appendChild(honeypotInput);
    this.formEl.appendChild(honeypot);

    // Error display
    this.errorEl = document.createElement('div');
    this.errorEl.className = 'cms-comment-form__error';
    this.errorEl.setAttribute('role', 'alert');
    this.errorEl.hidden = true;
    this.formEl.appendChild(this.errorEl);

    // Actions row
    const actionsRow = document.createElement('div');
    actionsRow.className = 'cms-comment-form__actions';

    this.submitBtn = document.createElement('button');
    this.submitBtn.type = 'submit';
    this.submitBtn.className = 'cms-comment-form__submit';
    this.submitBtn.textContent = this.isReply ? 'Post Reply' : 'Post Comment';
    actionsRow.appendChild(this.submitBtn);

    if (this.isReply) {
      const cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'cms-comment-form__cancel';
      cancelBtn.textContent = 'Cancel';

      cancelBtn.addEventListener('click', () => {
        this.remove();
      });

      actionsRow.appendChild(cancelBtn);
    }

    this.formEl.appendChild(actionsRow);

    this.formEl.addEventListener('submit', (e) => {
      e.preventDefault();
      void this.handleSubmit();
    });

    this.appendChild(this.formEl);
  }

  private fieldId(base: string): string {
    const suffix = this.parentId ?? this.contentId;

    return `${base}-${suffix}`;
  }

  private createFieldGroup(
    id: string,
    labelText: string,
    type: string,
    name: string,
    required: boolean,
  ): HTMLElement {
    const group = document.createElement('div');
    group.className = 'cms-comment-form__group';

    const label = document.createElement('label');
    label.className = 'cms-comment-form__label';
    label.htmlFor = this.fieldId(id);
    label.textContent = labelText;

    const input = document.createElement('input');
    input.className = 'cms-comment-form__input';
    input.type = type;
    input.id = this.fieldId(id);
    input.name = name;
    input.required = required;

    group.appendChild(label);
    group.appendChild(input);

    return group;
  }

  private updateCounter(): void {
    if (!this.textareaEl || !this.counterEl) {
      return;
    }

    const length = this.textareaEl.value.length;
    this.counterEl.textContent = `${length} / ${MAX_BODY_LENGTH}`;

    if (length > MAX_BODY_LENGTH * 0.9) {
      this.counterEl.classList.add('cms-comment-form__counter--warning');
    } else {
      this.counterEl.classList.remove('cms-comment-form__counter--warning');
    }

    if (length >= MAX_BODY_LENGTH) {
      this.counterEl.classList.add('cms-comment-form__counter--limit');
    } else {
      this.counterEl.classList.remove('cms-comment-form__counter--limit');
    }
  }

  private async handleSubmit(): Promise<void> {
    if (this.isSubmitting || !this.formEl) {
      return;
    }

    this.clearError();

    const formData = new FormData(this.formEl);

    // Honeypot check: if the hidden field has a value, silently succeed
    const honeypotValue = formData.get('website') as string;

    if (honeypotValue !== '') {
      this.showSuccess();
      return;
    }

    const body = (formData.get('body') as string).trim();

    if (body === '') {
      this.showError('Please enter a comment.');
      return;
    }

    if (body.length > MAX_BODY_LENGTH) {
      this.showError(`Comment must be ${MAX_BODY_LENGTH} characters or fewer.`);
      return;
    }

    if (!this.isAuthenticated) {
      const name = (formData.get('name') as string).trim();
      const email = (formData.get('email') as string).trim();

      if (name === '') {
        this.showError('Please enter your name.');
        return;
      }

      if (email === '' || !email.includes('@')) {
        this.showError('Please enter a valid email address.');
        return;
      }
    }

    this.isSubmitting = true;

    if (this.submitBtn) {
      this.submitBtn.disabled = true;
      this.submitBtn.textContent = 'Posting\u2026';
    }

    const payload: Record<string, string | null> = {
      content_id: this.contentId,
      body,
      parent_id: this.parentId,
    };

    if (!this.isAuthenticated) {
      payload.guest_name = (formData.get('name') as string).trim();
      payload.guest_email = (formData.get('email') as string).trim();
    }

    try {
      const response = await cmsApi(this.apiUrl, {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => null);
        const errorMessage =
          (errorData as { error?: string } | null)?.error ?? `Error: ${response.status}`;

        if (response.status === 429) {
          this.showError('You are posting too quickly. Please wait a moment and try again.');
        } else {
          this.showError(errorMessage);
        }

        return;
      }

      const result = await response.json();

      this.showSuccess();

      // Dispatch event for parent CommentsComponent
      this.dispatchEvent(
        new CustomEvent('comment-submitted', {
          detail: {
            comment: result,
            parent_id: this.parentId,
          },
          bubbles: true,
        }),
      );
    } catch {
      this.showError('Failed to post comment. Please check your connection and try again.');
    } finally {
      this.isSubmitting = false;

      if (this.submitBtn) {
        this.submitBtn.disabled = false;
        this.submitBtn.textContent = this.isReply ? 'Post Reply' : 'Post Comment';
      }
    }
  }

  private showError(message: string): void {
    if (!this.errorEl) {
      return;
    }

    this.errorEl.textContent = message;
    this.errorEl.hidden = false;
  }

  private clearError(): void {
    if (!this.errorEl) {
      return;
    }

    this.errorEl.textContent = '';
    this.errorEl.hidden = true;
  }

  private showSuccess(): void {
    if (!this.formEl) {
      return;
    }

    this.formEl.reset();
    this.updateCounter();

    // Brief success indication
    const successMsg = document.createElement('div');
    successMsg.className = 'cms-comment-form__success';
    successMsg.textContent = this.isReply
      ? 'Reply posted! It may require moderation before appearing.'
      : 'Comment posted! It may require moderation before appearing.';
    successMsg.setAttribute('role', 'status');

    this.formEl.appendChild(successMsg);

    setTimeout(() => {
      successMsg.remove();
    }, 5000);
  }
}

customElements.define('cms-comment-form', CommentForm);
