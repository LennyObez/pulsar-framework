@extends('cms::admin.layout')

@section('title', isset($product) ? 'Edit Product' : 'Create Product')

@section('cms-content')
<div class="cms-content-form">
    <form method="POST"
          action="{{ isset($product) ? '/admin/cms/products/' . $product['id'] : '/admin/cms/products' }}"
          class="cms-content-form__form">
        @csrf
        @if (isset($product))
            @method('PUT')
        @endif

        <header class="cms-content-list__header">
            <h1 class="cms-content-list__title">{{ isset($product) ? 'Edit Product' : 'Create Product' }}</h1>
            <div class="cms-content-list__actions">
                <a href="/admin/cms/products" class="cms-btn cms-btn--outline">Cancel</a>
                <button type="submit" class="cms-btn cms-btn--primary">Save Product</button>
            </div>
        </header>

        <div class="cms-content-form__layout">
            <div class="cms-content-form__main">
                {{-- Locale tabs for per-locale fields --}}
                @if (isset($locales) && count($locales) > 1)
                    @include('cms::admin._partials.locale-tabs', [
                        'locales' => $locales,
                        'activeLocale' => $activeLocale ?? $locales[0] ?? 'en',
                        'baseUrl' => isset($product) ? '/admin/cms/products/' . $product['id'] . '/edit' : '/admin/cms/products/create',
                    ])
                @endif

                <input type="hidden" name="locale" value="{{ $activeLocale ?? 'en' }}">

                {{-- Per-locale fields --}}
                <div class="cms-form-group">
                    <label for="product-name" class="cms-form-group__label">Name <span class="cms-required" aria-label="required">*</span></label>
                    <input type="text"
                           id="product-name"
                           name="name"
                           value="{{ $translation['name'] ?? '' }}"
                           class="cms-form-group__input"
                           required
                           maxlength="255">
                </div>

                <div class="cms-form-group">
                    <label for="product-description" class="cms-form-group__label">Description</label>
                    <textarea id="product-description"
                              name="description"
                              class="cms-form-group__textarea"
                              rows="6">{{ $translation['description'] ?? '' }}</textarea>
                </div>

                <div class="cms-form-group">
                    <label for="product-slug" class="cms-form-group__label">Slug</label>
                    <input type="text"
                           id="product-slug"
                           name="slug"
                           value="{{ $translation['slug'] ?? '' }}"
                           class="cms-form-group__input"
                           maxlength="200"
                           pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?">
                </div>

                {{-- General fields --}}
                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Product Details</legend>

                    <div class="cms-form-group">
                        <label for="product-sku" class="cms-form-group__label">SKU <span class="cms-required" aria-label="required">*</span></label>
                        <input type="text"
                               id="product-sku"
                               name="sku"
                               value="{{ $product['sku'] ?? '' }}"
                               class="cms-form-group__input"
                               required
                               maxlength="100">
                    </div>

                    <div class="cms-form-group">
                        <label for="product-price" class="cms-form-group__label">Price (minor units) <span class="cms-required" aria-label="required">*</span></label>
                        <div class="cms-input-group">
                            <input type="number"
                                   id="product-price"
                                   name="price_amount"
                                   value="{{ $product['price_amount'] ?? '' }}"
                                   class="cms-form-group__input"
                                   required
                                   min="0"
                                   step="1">
                            <span class="cms-input-group__addon">{{ strtoupper($product['price_currency'] ?? 'USD') }}</span>
                        </div>
                        <span class="cms-form-group__hint">Enter price in minor units (e.g. 1999 = 19.99)</span>
                    </div>

                    <div class="cms-form-group">
                        <label for="product-currency" class="cms-form-group__label">Currency <span class="cms-required" aria-label="required">*</span></label>
                        <input type="text"
                               id="product-currency"
                               name="price_currency"
                               value="{{ $product['price_currency'] ?? 'USD' }}"
                               class="cms-form-group__input"
                               required
                               maxlength="3"
                               pattern="[A-Z]{3}"
                               placeholder="USD">
                    </div>

                    <div class="cms-form-group">
                        <label for="product-tax-category" class="cms-form-group__label">Tax Category</label>
                        <select id="product-tax-category" name="tax_category" class="cms-form-group__select">
                            <option value="">None</option>
                            @foreach ($taxCategories ?? [] as $cat)
                                <option value="{{ $cat }}" @if (($product['tax_category'] ?? '') === $cat) selected @endif>{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="cms-form-group">
                        <label for="product-stock" class="cms-form-group__label">Stock Quantity</label>
                        <input type="number"
                               id="product-stock"
                               name="stock_quantity"
                               value="{{ $product['stock_quantity'] ?? '' }}"
                               class="cms-form-group__input"
                               min="0"
                               step="1">
                        <span class="cms-form-group__hint">Leave empty for unlimited stock.</span>
                    </div>

                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox"
                                   name="digital"
                                   value="1"
                                   class="cms-form-group__checkbox"
                                   @if ($product['digital'] ?? false) checked @endif>
                            Digital product (no physical shipping)
                        </label>
                    </div>
                </fieldset>

                {{-- Content link --}}
                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Content Link</legend>
                    <div class="cms-form-group">
                        <label for="product-content-id" class="cms-form-group__label">Linked Content</label>
                        <select id="product-content-id" name="content_id" class="cms-form-group__select">
                            <option value="">None</option>
                            @foreach ($contentItems ?? [] as $item)
                                <option value="{{ $item['id'] }}" @if (($product['content_id'] ?? '') === $item['id']) selected @endif>{{ $item['title'] ?? $item['id'] }}</option>
                            @endforeach
                        </select>
                        <span class="cms-form-group__hint">Optionally link this product to a CMS content item.</span>
                    </div>
                </fieldset>

                {{-- Variants (edit mode only) --}}
                @if (isset($product))
                    <fieldset class="cms-fieldset">
                        <legend class="cms-fieldset__legend">Variants</legend>

                        <div class="cms-form-group">
                            <label for="variant-axes" class="cms-form-group__label">Attribute Axes</label>
                            <input type="text"
                                   id="variant-axes"
                                   name="variant_axes"
                                   value="{{ implode(', ', $variantAxes ?? []) }}"
                                   class="cms-form-group__input"
                                   placeholder="e.g. size, color">
                            <span class="cms-form-group__hint">Comma-separated attribute names for variant generation.</span>
                        </div>

                        <button type="button" class="cms-btn cms-btn--outline" data-cms-generate-variants>Bulk Generate Variants</button>

                        @if (!empty($variants))
                            <table class="cms-table cms-table--compact">
                                <thead class="cms-table__head">
                                    <tr>
                                        <th class="cms-table__th">SKU Suffix</th>
                                        <th class="cms-table__th">Price Modifier</th>
                                        <th class="cms-table__th">Stock</th>
                                        <th class="cms-table__th">Media</th>
                                        <th class="cms-table__th">Active</th>
                                    </tr>
                                </thead>
                                <tbody class="cms-table__body">
                                    @foreach ($variants as $varIndex => $variant)
                                        <tr class="cms-table__row">
                                            <td class="cms-table__td">
                                                <input type="text"
                                                       name="variants[{{ $varIndex }}][sku_suffix]"
                                                       value="{{ $variant['sku_suffix'] ?? '' }}"
                                                       class="cms-form-group__input cms-form-group__input--sm">
                                            </td>
                                            <td class="cms-table__td">
                                                <input type="number"
                                                       name="variants[{{ $varIndex }}][price_modifier]"
                                                       value="{{ $variant['price_modifier'] ?? 0 }}"
                                                       class="cms-form-group__input cms-form-group__input--sm"
                                                       step="1">
                                            </td>
                                            <td class="cms-table__td">
                                                <input type="number"
                                                       name="variants[{{ $varIndex }}][stock]"
                                                       value="{{ $variant['stock'] ?? '' }}"
                                                       class="cms-form-group__input cms-form-group__input--sm"
                                                       min="0">
                                            </td>
                                            <td class="cms-table__td">
                                                <input type="text"
                                                       name="variants[{{ $varIndex }}][media]"
                                                       value="{{ $variant['media'] ?? '' }}"
                                                       class="cms-form-group__input cms-form-group__input--sm"
                                                       placeholder="Media ID">
                                            </td>
                                            <td class="cms-table__td">
                                                <input type="checkbox"
                                                       name="variants[{{ $varIndex }}][active]"
                                                       value="1"
                                                       class="cms-form-group__checkbox"
                                                       @if ($variant['active'] ?? true) checked @endif>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </fieldset>
                @endif
            </div>

            {{-- Sidebar --}}
            <aside class="cms-content-form__sidebar">
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Status</h3>
                    <div class="cms-sidebar-panel__body">
                        <div class="cms-form-group">
                            <label for="product-status" class="cms-form-group__label">Product Status</label>
                            <select id="product-status" name="status" class="cms-form-group__select">
                                @foreach ($statuses ?? [] as $statusOption)
                                    <option value="{{ $statusOption['value'] }}" @if (($product['status'] ?? 'draft') === $statusOption['value']) selected @endif>{{ $statusOption['label'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if (isset($product))
                            <dl class="cms-detail-list">
                                <dt class="cms-detail-list__term">Created</dt>
                                <dd class="cms-detail-list__value">
                                    <time datetime="{{ $product['created_at'] ?? '' }}">{{ $product['created_at'] ?? '' }}</time>
                                </dd>
                                <dt class="cms-detail-list__term">Updated</dt>
                                <dd class="cms-detail-list__value">
                                    <time datetime="{{ $product['updated_at'] ?? '' }}">{{ $product['updated_at'] ?? '' }}</time>
                                </dd>
                            </dl>
                        @endif
                    </div>
                </div>

                @if (isset($product) && ($product['digital'] ?? false))
                    <div class="cms-sidebar-panel">
                        <h3 class="cms-sidebar-panel__title">Digital Assets</h3>
                        <div class="cms-sidebar-panel__body">
                            <a href="/admin/cms/products/{{ $product['id'] }}/digital-assets" class="cms-btn cms-btn--outline cms-btn--full">
                                Manage Digital Assets
                            </a>
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </form>
</div>
@endsection
