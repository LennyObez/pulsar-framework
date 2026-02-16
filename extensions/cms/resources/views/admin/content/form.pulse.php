@extends('admin.layout')

@section('title', isset($content) ? 'Edit Content' : 'Create Content')

@section('content')
<div class="cms-content-form" data-cms-content-editor>
    <form method="POST"
          action="{{ isset($content) ? '/admin/cms/content/' . $content['id'] : '/admin/cms/content' }}"
          class="cms-content-form__form"
          data-cms-autosave>
        @csrf
        @if (isset($content))
            @method('PUT')
        @endif

        {{-- Lock indicator --}}
        @if (isset($lock))
            <div class="cms-alert cms-alert--warning" role="alert">
                <strong>Locked</strong>: This content is currently being edited by {{ $lock['locked_by'] ?? 'another user' }}.
                Lock expires at <time datetime="{{ $lock['expires_at'] ?? '' }}">{{ $lock['expires_at'] ?? '' }}</time>.
            </div>
        @endif

        <div class="cms-content-form__layout">
            {{-- Main content area --}}
            <div class="cms-content-form__main">
                {{-- Locale tabs --}}
                @if (isset($locales) && count($locales) > 1)
                    @include('cms::admin._partials.locale-tabs', [
                        'locales' => $locales,
                        'activeLocale' => $activeLocale ?? $locales[0] ?? 'en',
                        'baseUrl' => isset($content) ? '/admin/cms/content/' . $content['id'] . '/edit' : '/admin/cms/content/create',
                    ])
                @endif

                <input type="hidden" name="locale" value="{{ $activeLocale ?? 'en' }}">
                <input type="hidden" name="content_type" value="{{ $contentType ?? $content['type'] ?? 'page' }}">

                {{-- Title --}}
                <div class="cms-form-group">
                    <label for="content-title" class="cms-form-group__label">Title <span class="cms-required" aria-label="required">*</span></label>
                    <input type="text"
                           id="content-title"
                           name="title"
                           value="{{ $translation['title'] ?? '' }}"
                           class="cms-form-group__input cms-form-group__input--title"
                           required
                           aria-required="true"
                           maxlength="500"
                           data-cms-slug-source>
                </div>

                {{-- Slug --}}
                <div class="cms-form-group">
                    <label for="content-slug" class="cms-form-group__label">Slug <span class="cms-required" aria-label="required">*</span></label>
                    <div class="cms-slug-field">
                        <span class="cms-slug-field__prefix">{{ $pathPrefix ?? '/' }}</span>
                        <input type="text"
                               id="content-slug"
                               name="slug"
                               value="{{ $translation['slug'] ?? '' }}"
                               class="cms-form-group__input"
                               required
                               aria-required="true"
                               maxlength="200"
                               pattern="[a-z0-9](?:[a-z0-9-]*[a-z0-9])?"
                               data-cms-slug-target>
                    </div>
                </div>

                {{-- Body --}}
                <div class="cms-form-group">
                    <label for="content-body" class="cms-form-group__label">Body</label>
                    <textarea id="content-body"
                              name="body"
                              class="cms-form-group__textarea cms-editor"
                              rows="20"
                              data-cms-rich-editor>{{ $translation['body'] ?? '' }}</textarea>
                </div>

                {{-- Excerpt --}}
                <div class="cms-form-group">
                    <label for="content-excerpt" class="cms-form-group__label">Excerpt</label>
                    <textarea id="content-excerpt"
                              name="excerpt"
                              class="cms-form-group__textarea"
                              rows="3"
                              maxlength="500">{{ $translation['excerpt'] ?? '' }}</textarea>
                </div>

                {{-- Content Blocks: Visual Page Builder --}}
                <fieldset class="cms-fieldset">
                    <legend class="cms-fieldset__legend">Content Blocks</legend>
                    <cms-page-builder content-id="{{ $content['id'] ?? '' }}" locale="{{ $activeLocale ?? 'en' }}">
                        <textarea name="blocks_json" hidden>{{ isset($blocks) ? json_encode($blocks, JSON_UNESCAPED_UNICODE) : '[]' }}</textarea>
                    </cms-page-builder>
                </fieldset>

                {{-- Custom Fields --}}
                @if (!empty($customFieldDefinitions))
                    <fieldset class="cms-fieldset">
                        <legend class="cms-fieldset__legend">Custom Fields</legend>
                        @foreach ($customFieldDefinitions as $fieldDef)
                            <?php /** @var array{field_key: string, field_type: string, default_value: mixed, required: bool, validation_rules?: array<string, mixed>} $fieldDef */ /** @var array<string, mixed> $customFieldValues */ ?>
                            <div class="cms-form-group">
                                <label for="custom-field-{{ $fieldDef['field_key'] }}" class="cms-form-group__label">
                                    {{ ucfirst(str_replace('_', ' ', $fieldDef['field_key'])) }}
                                    @if ($fieldDef['required'] ?? false)
                                        <span class="cms-required" aria-label="required">*</span>
                                    @endif
                                </label>
                                <?php $__fieldValue = $customFieldValues[$fieldDef['field_key']] ?? $fieldDef['default_value'] ?? ''; ?>
                                @if (($fieldDef['field_type'] ?? '') === 'bool')
                                    <input type="checkbox"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="1"
                                           @if ($__fieldValue) checked @endif
                                           class="cms-form-group__checkbox">
                                @elseif (($fieldDef['field_type'] ?? '') === 'enum')
                                    <select id="custom-field-{{ $fieldDef['field_key'] }}"
                                            name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                            class="cms-form-group__select"
                                            @if ($fieldDef['required'] ?? false) required @endif>
                                        <option value="">Select...</option>
                                        @foreach ($fieldDef['validation_rules']['options'] ?? [] as $opt)
                                            <option value="{{ $opt }}" @if ($__fieldValue === $opt) selected @endif>{{ $opt }}</option>
                                        @endforeach
                                    </select>
                                @elseif (in_array($fieldDef['field_type'] ?? '', ['rich_text', 'json'], true))
                                    <textarea id="custom-field-{{ $fieldDef['field_key'] }}"
                                              name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                              class="cms-form-group__textarea"
                                              rows="4"
                                              @if ($fieldDef['required'] ?? false) required @endif>{{ $__fieldValue }}</textarea>
                                @elseif (($fieldDef['field_type'] ?? '') === 'int')
                                    <input type="number"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           step="1"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @elseif (($fieldDef['field_type'] ?? '') === 'float')
                                    <input type="number"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           step="any"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @elseif (($fieldDef['field_type'] ?? '') === 'date')
                                    <input type="date"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @elseif (($fieldDef['field_type'] ?? '') === 'datetime')
                                    <input type="datetime-local"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @elseif (($fieldDef['field_type'] ?? '') === 'url')
                                    <input type="url"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @elseif (($fieldDef['field_type'] ?? '') === 'email')
                                    <input type="email"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @elseif (($fieldDef['field_type'] ?? '') === 'color')
                                    <input type="color"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input">
                                @else
                                    <input type="text"
                                           id="custom-field-{{ $fieldDef['field_key'] }}"
                                           name="custom_fields[{{ $fieldDef['field_key'] }}]"
                                           value="{{ $__fieldValue }}"
                                           class="cms-form-group__input"
                                           @if ($fieldDef['required'] ?? false) required @endif>
                                @endif
                            </div>
                        @endforeach
                    </fieldset>
                @endif

                {{-- SEO Fields --}}
                <fieldset class="cms-fieldset cms-fieldset--collapsible" data-cms-collapsible>
                    <legend class="cms-fieldset__legend" data-cms-collapsible-toggle>SEO Settings</legend>
                    <div class="cms-fieldset__body" data-cms-collapsible-body>
                        <div class="cms-form-group">
                            <label for="seo-meta-title" class="cms-form-group__label">Meta Title</label>
                            <input type="text"
                                   id="seo-meta-title"
                                   name="meta_title"
                                   value="{{ $translation['meta_title'] ?? '' }}"
                                   class="cms-form-group__input"
                                   maxlength="70"
                                   data-cms-char-counter="70">
                            <span class="cms-form-group__hint" data-cms-char-count>0 / 70</span>
                        </div>

                        <div class="cms-form-group">
                            <label for="seo-meta-description" class="cms-form-group__label">Meta Description</label>
                            <textarea id="seo-meta-description"
                                      name="meta_description"
                                      class="cms-form-group__textarea"
                                      rows="2"
                                      maxlength="170"
                                      data-cms-char-counter="170">{{ $translation['meta_description'] ?? '' }}</textarea>
                            <span class="cms-form-group__hint" data-cms-char-count>0 / 170</span>
                        </div>

                        <div class="cms-form-group">
                            <label for="seo-og-image" class="cms-form-group__label">OG Image</label>
                            <input type="text"
                                   id="seo-og-image"
                                   name="og_image"
                                   value="{{ $translation['og_image'] ?? '' }}"
                                   class="cms-form-group__input"
                                   placeholder="Image URL or media ID">
                        </div>

                        <div class="cms-form-group">
                            <label for="seo-robots" class="cms-form-group__label">Robots Override</label>
                            <select id="seo-robots"
                                    name="robots"
                                    class="cms-form-group__select">
                                <option value="" @if (($translation['robots'] ?? '') === '') selected @endif>Default (inherit from site settings)</option>
                                <option value="index, follow" @if (($translation['robots'] ?? '') === 'index, follow') selected @endif>index, follow</option>
                                <option value="noindex, follow" @if (($translation['robots'] ?? '') === 'noindex, follow') selected @endif>noindex, follow</option>
                                <option value="index, nofollow" @if (($translation['robots'] ?? '') === 'index, nofollow') selected @endif>index, nofollow</option>
                                <option value="noindex, nofollow" @if (($translation['robots'] ?? '') === 'noindex, nofollow') selected @endif>noindex, nofollow</option>
                            </select>
                        </div>
                    </div>
                </fieldset>
            </div>

            {{-- Sidebar --}}
            <aside class="cms-content-form__sidebar">
                {{-- Publishing --}}
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Publishing</h3>
                    <div class="cms-sidebar-panel__body">
                        <div class="cms-form-group">
                            <span class="cms-form-group__label">Status</span>
                            @include('cms::admin._partials.status-badge', ['status' => $content['status'] ?? 'draft'])
                        </div>

                        @can('cms.content.publish')
                            @if (($content['status'] ?? 'draft') !== 'published')
                                <button type="submit" name="action" value="publish" class="cms-btn cms-btn--success cms-btn--full">
                                    Publish
                                </button>
                            @endif
                        @endcan

                        @can('cms.content.submit_review')
                            @if (($content['status'] ?? 'draft') === 'draft')
                                <button type="submit" name="action" value="submit_review" class="cms-btn cms-btn--primary cms-btn--full">
                                    Submit for Review
                                </button>
                            @endif
                        @endcan

                        <div class="cms-form-group">
                            <label for="schedule-date" class="cms-form-group__label">Schedule</label>
                            <input type="datetime-local"
                                   id="schedule-date"
                                   name="publish_at"
                                   value="{{ $content['scheduled_publish_at'] ?? '' }}"
                                   class="cms-form-group__input">
                        </div>

                        <button type="submit" name="action" value="save" class="cms-btn cms-btn--primary cms-btn--full">
                            Save Draft
                        </button>
                    </div>
                </div>

                {{-- Template & Parent --}}
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Page Settings</h3>
                    <div class="cms-sidebar-panel__body">
                        <div class="cms-form-group">
                            <label for="content-template" class="cms-form-group__label">Template</label>
                            <select id="content-template" name="template" class="cms-form-group__select">
                                <option value="">Default</option>
                                @foreach ($templates ?? [] as $tpl)
                                    <option value="{{ $tpl }}" @if (($content['template'] ?? '') === $tpl) selected @endif>{{ $tpl }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="cms-form-group">
                            <label for="content-parent" class="cms-form-group__label">Parent Page</label>
                            <select id="content-parent" name="parent_id" class="cms-form-group__select">
                                <option value="">None (Top Level)</option>
                                @foreach ($parentPages ?? [] as $parent)
                                    <option value="{{ $parent['id'] }}" @if (($content['parent_id'] ?? '') === $parent['id']) selected @endif>
                                        {{ str_repeat('-- ', $parent['depth'] ?? 0) }}{{ $parent['title'] ?? '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="cms-form-group">
                            <label for="content-classification" class="cms-form-group__label">Data Classification</label>
                            <select id="content-classification" name="data_classification" class="cms-form-group__select">
                                <option value="public" @if (($content['data_classification'] ?? 'public') === 'public') selected @endif>Public</option>
                                <option value="internal" @if (($content['data_classification'] ?? '') === 'internal') selected @endif>Internal</option>
                                <option value="confidential" @if (($content['data_classification'] ?? '') === 'confidential') selected @endif>Confidential</option>
                                <option value="pii" @if (($content['data_classification'] ?? '') === 'pii') selected @endif>PII</option>
                            </select>
                        </div>

                        <div class="cms-form-group">
                            <label for="content-comments" class="cms-form-group__label">Comment Policy</label>
                            <select id="content-comments" name="comment_policy" class="cms-form-group__select">
                                <option value="inherit" @if (($content['comment_policy'] ?? 'inherit') === 'inherit') selected @endif>Inherit</option>
                                <option value="open" @if (($content['comment_policy'] ?? '') === 'open') selected @endif>Open</option>
                                <option value="moderated" @if (($content['comment_policy'] ?? '') === 'moderated') selected @endif>Moderated</option>
                                <option value="closed" @if (($content['comment_policy'] ?? '') === 'closed') selected @endif>Closed</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- Taxonomy Terms --}}
                @if (!empty($taxonomies))
                    <div class="cms-sidebar-panel">
                        <h3 class="cms-sidebar-panel__title">Taxonomies</h3>
                        <div class="cms-sidebar-panel__body">
                            @foreach ($taxonomies as $taxonomy)
                                <fieldset class="cms-taxonomy-group">
                                    <legend class="cms-taxonomy-group__legend">{{ $taxonomy['name'] ?? $taxonomy['slug'] }}</legend>
                                    @if ($taxonomy['hierarchical'] ?? false)
                                        <div class="cms-taxonomy-group__tree" data-cms-taxonomy="{{ $taxonomy['slug'] }}">
                                            @foreach ($taxonomy['terms'] ?? [] as $term)
                                                <label class="cms-taxonomy-group__term" style="padding-left: {{ ($term['depth'] ?? 0) * 1.25 }}rem">
                                                    <input type="checkbox"
                                                           name="terms[]"
                                                           value="{{ $term['id'] }}"
                                                           @if (in_array($term['id'], $selectedTerms ?? [], true)) checked @endif>
                                                    {{ $term['name'] ?? '' }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="cms-taxonomy-group__tags" data-cms-taxonomy="{{ $taxonomy['slug'] }}">
                                            @foreach ($taxonomy['terms'] ?? [] as $term)
                                                <label class="cms-taxonomy-group__term">
                                                    <input type="checkbox"
                                                           name="terms[]"
                                                           value="{{ $term['id'] }}"
                                                           @if (in_array($term['id'], $selectedTerms ?? [], true)) checked @endif>
                                                    {{ $term['name'] ?? '' }}
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif
                                </fieldset>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Revision History --}}
                @if (isset($content['id']))
                    <div class="cms-sidebar-panel">
                        <h3 class="cms-sidebar-panel__title">Revisions</h3>
                        <div class="cms-sidebar-panel__body">
                            <a href="/admin/cms/content/{{ $content['id'] }}/revisions" class="cms-btn cms-btn--outline cms-btn--full">
                                View Revision History
                            </a>
                        </div>
                    </div>
                @endif
            </aside>
        </div>
    </form>
</div>
@endsection
