@extends('cms::admin.layout')

@section('title', isset($promotion) ? 'Edit Promotion' : 'Create Promotion')

@section('cms-content')
<div class="cms-content-form">
    <form method="POST"
          action="{{ isset($promotion) ? '/admin/cms/promotions/' . $promotion['id'] : '/admin/cms/promotions' }}"
          class="cms-content-form__form">
        @csrf
        @if (isset($promotion))
            @method('PUT')
        @endif

        <header class="cms-content-list__header">
            <h1 class="cms-content-list__title">{{ isset($promotion) ? 'Edit Promotion' : 'Create Promotion' }}</h1>
            <div class="cms-content-list__actions">
                <a href="/admin/cms/promotions" class="cms-btn cms-btn--outline">Cancel</a>
                <button type="submit" class="cms-btn cms-btn--primary">Save Promotion</button>
            </div>
        </header>

        <div class="cms-content-form__layout">
            <div class="cms-content-form__main">
                <div class="cms-form-group">
                    <label for="promo-name" class="cms-form-group__label">Name <span class="cms-required" aria-label="required">*</span></label>
                    <input type="text"
                           id="promo-name"
                           name="name"
                           value="{{ $promotion['name'] ?? '' }}"
                           class="cms-form-group__input"
                           required
                           aria-required="true"
                           maxlength="255">
                </div>

                <div class="cms-form-group">
                    <label for="promo-type" class="cms-form-group__label">Type <span class="cms-required" aria-label="required">*</span></label>
                    <select id="promo-type" name="type" class="cms-form-group__select" required aria-required="true" data-cms-promo-type>
                        <option value="">Select type...</option>
                        @foreach ($types ?? [] as $typeOption)
                            <option value="{{ $typeOption['value'] }}" @if (($promotion['type'] ?? '') === $typeOption['value']) selected @endif>{{ $typeOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="cms-form-group">
                    <label for="promo-value" class="cms-form-group__label">Value <span class="cms-required" aria-label="required">*</span></label>
                    <input type="number"
                           id="promo-value"
                           name="value"
                           value="{{ $promotion['value'] ?? '' }}"
                           class="cms-form-group__input"
                           required
                           aria-required="true"
                           min="1">
                    <span class="cms-form-group__hint" data-cms-promo-value-hint>Enter the promotion value (percentage, fixed amount in minor units, or quantity for buy-X-get-Y).</span>
                </div>

                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Limits</legend>

                    <div class="cms-form-group">
                        <label for="promo-min-order" class="cms-form-group__label">Minimum Order Amount (minor units)</label>
                        <input type="number"
                               id="promo-min-order"
                               name="min_order_amount"
                               value="{{ $promotion['min_order_amount'] ?? '' }}"
                               class="cms-form-group__input"
                               min="0">
                    </div>

                    <div class="cms-form-group">
                        <label for="promo-max-uses" class="cms-form-group__label">Max Total Uses</label>
                        <input type="number"
                               id="promo-max-uses"
                               name="max_uses"
                               value="{{ $promotion['max_uses'] ?? '' }}"
                               class="cms-form-group__input"
                               min="1">
                        <span class="cms-form-group__hint">Leave empty for unlimited uses.</span>
                    </div>

                    <div class="cms-form-group">
                        <label for="promo-max-per-customer" class="cms-form-group__label">Max Uses Per Customer</label>
                        <input type="number"
                               id="promo-max-per-customer"
                               name="max_uses_per_customer"
                               value="{{ $promotion['max_uses_per_customer'] ?? '' }}"
                               class="cms-form-group__input"
                               min="1">
                    </div>
                </fieldset>

                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Schedule</legend>

                    <div class="cms-form-group">
                        <label for="promo-starts" class="cms-form-group__label">Starts At</label>
                        <input type="datetime-local"
                               id="promo-starts"
                               name="starts_at"
                               value="{{ $promotion['starts_at'] ?? '' }}"
                               class="cms-form-group__input">
                    </div>

                    <div class="cms-form-group">
                        <label for="promo-expires" class="cms-form-group__label">Expires At</label>
                        <input type="datetime-local"
                               id="promo-expires"
                               name="expires_at"
                               value="{{ $promotion['expires_at'] ?? '' }}"
                               class="cms-form-group__input">
                    </div>
                </fieldset>

                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Scope</legend>

                    <div class="cms-form-group">
                        <label for="promo-products" class="cms-form-group__label">Product Scope</label>
                        <select id="promo-products"
                                name="applicable_product_ids[]"
                                class="cms-form-group__select"
                                multiple
                                size="5">
                            @foreach ($availableProducts ?? [] as $prod)
                                <option value="{{ $prod['id'] }}" @if (in_array($prod['id'], $promotion['applicable_product_ids'] ?? [], true)) selected @endif>{{ $prod['name'] ?? $prod['sku'] ?? $prod['id'] }}</option>
                            @endforeach
                        </select>
                        <span class="cms-form-group__hint">Leave empty to apply to all products.</span>
                    </div>

                    <div class="cms-form-group">
                        <label for="promo-categories" class="cms-form-group__label">Category Scope</label>
                        <select id="promo-categories"
                                name="applicable_category_ids[]"
                                class="cms-form-group__select"
                                multiple
                                size="5">
                            @foreach ($availableCategories ?? [] as $cat)
                                <option value="{{ $cat['id'] }}" @if (in_array($cat['id'], $promotion['applicable_category_ids'] ?? [], true)) selected @endif>{{ $cat['name'] ?? $cat['id'] }}</option>
                            @endforeach
                        </select>
                        <span class="cms-form-group__hint">Leave empty to apply to all categories.</span>
                    </div>
                </fieldset>

                {{-- Coupon Codes --}}
                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Coupon Codes</legend>

                    @if (!empty($coupons))
                        <ul class="cms-list">
                            @foreach ($coupons as $coupon)
                                <li class="cms-list__item">
                                    <code>{{ $coupon['code'] ?? '' }}</code>
                                    @if ($coupon['is_single_use'] ?? false)
                                        <span class="cms-badge cms-badge--info">Single use</span>
                                    @endif
                                    @if ($coupon['used_at'] ?? null)
                                        <span class="cms-badge cms-badge--archived">Used</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="cms-form-group">
                        <label for="coupon-code" class="cms-form-group__label">Add Coupon Code</label>
                        <div class="cms-input-group">
                            <input type="text"
                                   id="coupon-code"
                                   name="coupons[0][code]"
                                   class="cms-form-group__input"
                                   placeholder="Enter coupon code"
                                   maxlength="50">
                        </div>
                    </div>

                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox"
                                   name="coupons[0][single_use]"
                                   value="1"
                                   class="cms-form-group__checkbox">
                            Single use only
                        </label>
                    </div>
                </fieldset>
            </div>

            {{-- Sidebar --}}
            <aside class="cms-content-form__sidebar">
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Status</h3>
                    <div class="cms-sidebar-panel__body">
                        <div class="cms-form-group">
                            <label class="cms-form-group__label">
                                <input type="checkbox"
                                       name="is_active"
                                       value="1"
                                       class="cms-form-group__checkbox"
                                       @if ($promotion['is_active'] ?? true) checked @endif>
                                Active
                            </label>
                        </div>

                        @if (isset($promotion))
                            <dl class="cms-detail-list">
                                <dt class="cms-detail-list__term">Current Uses</dt>
                                <dd class="cms-detail-list__value">{{ $promotion['current_uses'] ?? 0 }}</dd>
                            </dl>
                        @endif
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>
@endsection
