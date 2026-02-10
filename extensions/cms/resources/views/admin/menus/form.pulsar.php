@extends('admin.layout')

@section('title', isset($menu) ? 'Edit Menu: ' . ($menu['location'] ?? '') : 'Create Menu')

@section('content')
<div class="cms-menu-form">
    <header class="cms-menu-form__header">
        <h1 class="cms-menu-form__title">{{ isset($menu) ? 'Edit Menu' : 'Create Menu' }}</h1>
        <a href="/admin/cms/menus" class="cms-btn cms-btn--outline">Back to Menus</a>
    </header>

    {{-- Menu settings --}}
    <form method="POST"
          action="{{ isset($menu) ? '/admin/cms/menus/' . $menu['location'] : '/admin/cms/menus' }}"
          class="cms-menu-form__settings">
        @csrf
        @if (isset($menu))
            @method('PUT')
        @endif

        @if (isset($locales) && count($locales) > 1)
            @include('cms::admin._partials.locale-tabs', [
                'locales' => $locales,
                'activeLocale' => $activeLocale ?? 'en',
                'baseUrl' => isset($menu) ? '/admin/cms/menus/' . $menu['location'] . '/edit' : '/admin/cms/menus/create',
            ])
        @endif

        <input type="hidden" name="locale" value="{{ $activeLocale ?? 'en' }}">

        @if (!isset($menu))
            <div class="cms-form-group">
                <label for="menu-location" class="cms-form-group__label">Location <span class="cms-required" aria-label="required">*</span></label>
                <select id="menu-location" name="location" class="cms-form-group__select" required aria-required="true">
                    <option value="">Select location...</option>
                    <option value="primary">Primary Navigation</option>
                    <option value="footer">Footer</option>
                    <option value="sidebar">Sidebar</option>
                    <option value="mega_menu">Mega Menu</option>
                </select>
            </div>
        @else
            <div class="cms-form-group">
                <span class="cms-form-group__label">Location</span>
                <span class="cms-type-label">{{ $menu['location'] }}</span>
            </div>
        @endif

        {{-- Menu Items Tree --}}
        @if (isset($menu))
            <div class="cms-menu-editor" data-cms-menu-editor>
                <h2 class="cms-menu-editor__title">Menu Items</h2>

                <div class="cms-menu-editor__tree" data-cms-menu-tree>
                    @if (empty($menuItems))
                        <p class="cms-widget__empty">No menu items yet. Add your first item below.</p>
                    @else
                        <ul class="cms-menu-tree" data-cms-sortable-list data-cms-nestable>
                            @foreach ($menuItems as $itemIndex => $item)
                                <li class="cms-menu-tree__item" style="padding-left: {{ ($item['depth'] ?? 0) * 1.5 }}rem" data-cms-menu-item="{{ $item['id'] ?? $itemIndex }}" draggable="true">
                                    <div class="cms-menu-tree__handle" aria-label="Drag to reorder" role="button" tabindex="0" aria-roledescription="sortable">&#9776;</div>
                                    <div class="cms-menu-tree__content">
                                        <input type="hidden" name="items[{{ $itemIndex }}][id]" value="{{ $item['id'] ?? '' }}">
                                        <input type="hidden" name="items[{{ $itemIndex }}][parent_id]" value="{{ $item['parent_id'] ?? '' }}">
                                        <input type="hidden" name="items[{{ $itemIndex }}][sort_order]" value="{{ $item['sort_order'] ?? $itemIndex }}">

                                        <div class="cms-menu-tree__fields">
                                            <div class="cms-form-group cms-form-group--inline">
                                                <label class="cms-form-group__label" for="item-label-{{ $itemIndex }}">Label</label>
                                                <input type="text"
                                                       id="item-label-{{ $itemIndex }}"
                                                       name="items[{{ $itemIndex }}][label]"
                                                       value="{{ $item['label'] ?? '' }}"
                                                       class="cms-form-group__input"
                                                       required
                                                       aria-required="true"
                                                       maxlength="200">
                                            </div>

                                            <div class="cms-form-group cms-form-group--inline">
                                                <label class="cms-form-group__label" for="item-url-{{ $itemIndex }}">URL / Content</label>
                                                <input type="text"
                                                       id="item-url-{{ $itemIndex }}"
                                                       name="items[{{ $itemIndex }}][url]"
                                                       value="{{ $item['url'] ?? $item['content_path'] ?? '' }}"
                                                       class="cms-form-group__input"
                                                       placeholder="URL or content path">
                                            </div>

                                            <div class="cms-form-group cms-form-group--inline">
                                                <label class="cms-form-group__label" for="item-target-{{ $itemIndex }}">Target</label>
                                                <select id="item-target-{{ $itemIndex }}" name="items[{{ $itemIndex }}][target]" class="cms-form-group__select">
                                                    <option value="_self" @if (($item['target'] ?? '_self') === '_self') selected @endif>Same Window</option>
                                                    <option value="_blank" @if (($item['target'] ?? '') === '_blank') selected @endif>New Window</option>
                                                </select>
                                            </div>

                                            <div class="cms-form-group cms-form-group--inline">
                                                <label class="cms-form-group__label" for="item-css-{{ $itemIndex }}">CSS Class</label>
                                                <input type="text"
                                                       id="item-css-{{ $itemIndex }}"
                                                       name="items[{{ $itemIndex }}][css_class]"
                                                       value="{{ $item['css_class'] ?? '' }}"
                                                       class="cms-form-group__input"
                                                       placeholder="Optional CSS class">
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button" class="cms-btn cms-btn--sm cms-btn--danger cms-menu-tree__remove" data-cms-remove-item aria-label="Remove item">Remove</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <button type="button" class="cms-btn cms-btn--outline" data-cms-add-menu-item>Add Menu Item</button>
            </div>
        @endif

        <div class="cms-menu-form__submit">
            <button type="submit" class="cms-btn cms-btn--primary">{{ isset($menu) ? 'Update Menu' : 'Create Menu' }}</button>
        </div>
    </form>
</div>
@endsection
