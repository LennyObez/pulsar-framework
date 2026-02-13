@extends('admin.layout')

@section('title', isset($taxonomy) ? 'Edit Taxonomy' : 'Create Taxonomy')

@section('content')
<div class="cms-taxonomy-form">
    <header class="cms-taxonomy-form__header">
        <h1 class="cms-taxonomy-form__title">{{ isset($taxonomy) ? 'Edit Taxonomy' : 'Create Taxonomy' }}</h1>
        <a href="/admin/cms/taxonomy" class="cms-btn cms-btn--outline">Back to Taxonomies</a>
    </header>

    <div class="cms-taxonomy-form__layout">
        {{-- Taxonomy settings --}}
        <div class="cms-taxonomy-form__main">
            <form method="POST"
                  action="{{ isset($taxonomy) ? '/admin/cms/taxonomy/' . $taxonomy['slug'] : '/admin/cms/taxonomy' }}"
                  class="cms-taxonomy-form__form">
                @csrf
                @if (isset($taxonomy))
                    @method('PUT')
                @endif

                {{-- Locale tabs --}}
                @if (isset($locales) && count($locales) > 1)
                    @include('cms::admin._partials.locale-tabs', [
                        'locales' => $locales,
                        'activeLocale' => $activeLocale ?? 'en',
                        'baseUrl' => isset($taxonomy) ? '/admin/cms/taxonomy/' . $taxonomy['slug'] . '/edit' : '/admin/cms/taxonomy/create',
                    ])
                @endif

                <input type="hidden" name="locale" value="{{ $activeLocale ?? 'en' }}">

                <div class="cms-form-group">
                    <label for="taxonomy-name" class="cms-form-group__label">Name <span class="cms-required" aria-label="required">*</span></label>
                    <input type="text"
                           id="taxonomy-name"
                           name="name"
                           value="{{ $taxonomyTranslation['name'] ?? '' }}"
                           class="cms-form-group__input"
                           required
                           aria-required="true"
                           maxlength="200">
                </div>

                <div class="cms-form-group">
                    <label for="taxonomy-slug" class="cms-form-group__label">Slug <span class="cms-required" aria-label="required">*</span></label>
                    <input type="text"
                           id="taxonomy-slug"
                           name="slug"
                           value="{{ $taxonomy['slug'] ?? '' }}"
                           class="cms-form-group__input"
                           required
                           aria-required="true"
                           maxlength="100"
                           pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?"
                           @if (isset($taxonomy)) readonly @endif>
                </div>

                <div class="cms-form-group">
                    <label for="taxonomy-description" class="cms-form-group__label">Description</label>
                    <textarea id="taxonomy-description"
                              name="description"
                              class="cms-form-group__textarea"
                              rows="3">{{ $taxonomyTranslation['description'] ?? '' }}</textarea>
                </div>

                @if (!isset($taxonomy))
                    <div class="cms-form-group">
                        <label class="cms-form-group__label">
                            <input type="checkbox" name="hierarchical" value="1" class="cms-form-group__checkbox">
                            Hierarchical (like categories, with parent-child relationships)
                        </label>
                    </div>
                @else
                    <div class="cms-form-group">
                        <span class="cms-form-group__label">Type</span>
                        <span class="cms-type-label">{{ ($taxonomy['hierarchical'] ?? false) ? 'Hierarchical' : 'Flat' }}</span>
                    </div>
                @endif

                <button type="submit" class="cms-btn cms-btn--primary">{{ isset($taxonomy) ? 'Update Taxonomy' : 'Create Taxonomy' }}</button>
            </form>
        </div>

        {{-- Terms management (edit mode only) --}}
        @if (isset($taxonomy))
            <div class="cms-taxonomy-form__terms">
                <h2 class="cms-taxonomy-form__section-title">Terms</h2>

                {{-- Add term form --}}
                <form method="POST" action="/admin/cms/taxonomy/{{ $taxonomy['slug'] }}/terms" class="cms-term-add-form">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $activeLocale ?? 'en' }}">

                    <div class="cms-form-group">
                        <label for="term-name" class="cms-form-group__label">Term Name</label>
                        <input type="text"
                               id="term-name"
                               name="term_name"
                               class="cms-form-group__input"
                               required
                               aria-required="true"
                               maxlength="200"
                               placeholder="Enter term name">
                    </div>

                    <div class="cms-form-group">
                        <label for="term-slug" class="cms-form-group__label">Term Slug</label>
                        <input type="text"
                               id="term-slug"
                               name="term_slug"
                               class="cms-form-group__input"
                               maxlength="200"
                               pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?"
                               placeholder="Auto-generated from name">
                    </div>

                    @if ($taxonomy['hierarchical'] ?? false)
                        <div class="cms-form-group">
                            <label for="term-parent" class="cms-form-group__label">Parent Term</label>
                            <select id="term-parent" name="parent_id" class="cms-form-group__select">
                                <option value="">None (Top Level)</option>
                                @foreach ($terms ?? [] as $term)
                                    <option value="{{ $term['id'] }}">
                                        {{ str_repeat('— ', $term['depth'] ?? 0) }}{{ $term['name'] ?? '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <button type="submit" class="cms-btn cms-btn--primary">Add Term</button>
                </form>

                {{-- Terms tree/list --}}
                <div class="cms-term-tree" data-cms-term-tree>
                    @if (empty($terms))
                        <p class="cms-widget__empty">No terms yet. Add your first term above.</p>
                    @else
                        <ul class="cms-term-tree__list" data-cms-sortable-list>
                            @foreach ($terms as $term)
                                <li class="cms-term-tree__item" style="padding-left: {{ ($term['depth'] ?? 0) * 1.5 }}rem" data-cms-term-id="{{ $term['id'] }}" draggable="true">
                                    <span class="cms-term-tree__handle" role="button" tabindex="0" aria-label="Drag to reorder" aria-roledescription="sortable">&#9776;</span>
                                    <span class="cms-term-tree__name">{{ $term['name'] ?? '' }}</span>
                                    <span class="cms-term-tree__slug"><code>{{ $term['slug'] ?? '' }}</code></span>
                                    <div class="cms-term-tree__actions">
                                        <button type="button" class="cms-btn cms-btn--sm cms-btn--outline" data-cms-edit-term="{{ $term['id'] }}">Edit</button>
                                        <form method="POST" action="/admin/cms/taxonomy/{{ $taxonomy['slug'] }}/terms/{{ $term['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this term?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
