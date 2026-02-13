@extends('admin.layout')

@section('title', 'Custom Fields: ' . ucfirst($contentType ?? ''))

@section('content')
<div class="cms-field-list">
    <header class="cms-field-list__header">
        <h1 class="cms-field-list__title">Custom Fields for {{ ucfirst($contentType ?? 'Content') }}</h1>
        <div class="cms-field-list__actions">
            <a href="/admin/cms/content" class="cms-btn cms-btn--outline">Back to Content</a>
        </div>
    </header>

    {{-- Content type selector --}}
    <div class="cms-field-list__type-selector">
        <label for="field-content-type" class="cms-form-group__label">Content Type</label>
        <div class="cms-field-list__type-nav" role="tablist">
            <a href="/admin/cms/fields/article"
               class="cms-btn cms-btn--outline @if (($contentType ?? '') === 'article') cms-btn--active @endif"
               role="tab"
               aria-selected="{{ ($contentType ?? '') === 'article' ? 'true' : 'false' }}">Article</a>
            <a href="/admin/cms/fields/page"
               class="cms-btn cms-btn--outline @if (($contentType ?? '') === 'page') cms-btn--active @endif"
               role="tab"
               aria-selected="{{ ($contentType ?? '') === 'page' ? 'true' : 'false' }}">Page</a>
        </div>
    </div>

    {{-- Existing fields --}}
    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Field Key</th>
                <th class="cms-table__th" scope="col">Type</th>
                <th class="cms-table__th" scope="col">Required</th>
                <th class="cms-table__th" scope="col">Translatable</th>
                <th class="cms-table__th" scope="col">Searchable</th>
                <th class="cms-table__th" scope="col">Filterable</th>
                <th class="cms-table__th" scope="col">Sort Order</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($fields))
                <tr>
                    <td colspan="8" class="cms-table__empty">No custom fields defined for this content type.</td>
                </tr>
            @endif

            @foreach ($fields as $field)
                <tr class="cms-table__row">
                    <td class="cms-table__td cms-table__td--title"><code>{{ $field['field_key'] ?? '' }}</code></td>
                    <td class="cms-table__td">
                        <span class="cms-type-label">{{ $field['field_type'] ?? '' }}</span>
                    </td>
                    <td class="cms-table__td">{{ ($field['required'] ?? false) ? 'Yes' : 'No' }}</td>
                    <td class="cms-table__td">{{ ($field['translatable'] ?? false) ? 'Yes' : 'No' }}</td>
                    <td class="cms-table__td">{{ ($field['searchable'] ?? false) ? 'Yes' : 'No' }}</td>
                    <td class="cms-table__td">{{ ($field['filterable'] ?? false) ? 'Yes' : 'No' }}</td>
                    <td class="cms-table__td">{{ $field['sort_order'] ?? 0 }}</td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Field actions">
                            @can('cms.content.manage_fields')
                                <button type="button"
                                        class="cms-btn cms-btn--sm cms-btn--outline"
                                        data-cms-edit-field="{{ $field['id'] }}"
                                        data-cms-field-key="{{ $field['field_key'] ?? '' }}"
                                        data-cms-field-type="{{ $field['field_type'] ?? '' }}"
                                        data-cms-field-required="{{ ($field['required'] ?? false) ? '1' : '0' }}"
                                        data-cms-field-translatable="{{ ($field['translatable'] ?? false) ? '1' : '0' }}"
                                        data-cms-field-searchable="{{ ($field['searchable'] ?? false) ? '1' : '0' }}"
                                        data-cms-field-filterable="{{ ($field['filterable'] ?? false) ? '1' : '0' }}"
                                        data-cms-field-sortable="{{ ($field['sortable'] ?? false) ? '1' : '0' }}"
                                        data-cms-field-sort-order="{{ $field['sort_order'] ?? 0 }}">Edit</button>
                                <form method="POST"
                                      action="/admin/cms/fields/{{ $contentType }}/{{ $field['id'] }}"
                                      class="cms-inline-form"
                                      data-cms-confirm="Delete this custom field? All stored values will be lost.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Add field form --}}
    @can('cms.content.manage_fields')
        <fieldset class="cms-fieldset">
            <legend class="cms-fieldset__legend">Add Custom Field</legend>
            <form method="POST" action="/admin/cms/fields/{{ $contentType }}" class="cms-field-add-form" data-cms-field-form>
                @csrf

                <div class="cms-field-add-form__grid">
                    <div class="cms-form-group">
                        <label for="new-field-key" class="cms-form-group__label">Field Key <span class="cms-required" aria-label="required">*</span></label>
                        <input type="text"
                               id="new-field-key"
                               name="field_key"
                               class="cms-form-group__input"
                               required
                               aria-required="true"
                               pattern="[a-z][a-z0-9_]*"
                               placeholder="e.g., featured_image">
                    </div>

                    <div class="cms-form-group">
                        <label for="new-field-type" class="cms-form-group__label">Field Type <span class="cms-required" aria-label="required">*</span></label>
                        <select id="new-field-type" name="field_type" class="cms-form-group__select" required aria-required="true">
                            <option value="">Select type...</option>
                            <option value="string">String</option>
                            <option value="int">Integer</option>
                            <option value="float">Float</option>
                            <option value="bool">Boolean</option>
                            <option value="date">Date</option>
                            <option value="datetime">Date/Time</option>
                            <option value="enum">Enum</option>
                            <option value="relation">Relation</option>
                            <option value="json">JSON</option>
                            <option value="media">Media</option>
                            <option value="rich_text">Rich Text</option>
                            <option value="color">Color</option>
                            <option value="url">URL</option>
                            <option value="email">Email</option>
                        </select>
                    </div>

                    <div class="cms-form-group">
                        <label for="new-field-sort-order" class="cms-form-group__label">Sort Order</label>
                        <input type="number" id="new-field-sort-order" name="sort_order" class="cms-form-group__input" value="0" step="1">
                    </div>
                </div>

                <div class="cms-field-add-form__options">
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="checkbox" name="required" value="1" class="cms-form-group__checkbox"> Required
                    </label>
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="checkbox" name="translatable" value="1" class="cms-form-group__checkbox"> Translatable
                    </label>
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="checkbox" name="searchable" value="1" class="cms-form-group__checkbox"> Searchable
                    </label>
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="checkbox" name="filterable" value="1" class="cms-form-group__checkbox"> Filterable
                    </label>
                    <label class="cms-form-group__label cms-form-group__label--checkbox">
                        <input type="checkbox" name="sortable" value="1" class="cms-form-group__checkbox"> Sortable
                    </label>
                </div>

                <button type="submit" class="cms-btn cms-btn--primary">Add Field</button>
            </form>
        </fieldset>
    @endcan
</div>
@endsection
