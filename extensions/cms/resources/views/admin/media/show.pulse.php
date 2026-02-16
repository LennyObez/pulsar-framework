@extends('admin.layout')

@section('title', $asset['filename'] ?? 'Media Asset')

@section('content')
<div class="cms-media-show">
    <header class="cms-media-show__header">
        <div class="cms-media-show__meta">
            <h1 class="cms-media-show__title">{{ $asset['filename'] ?? '(No filename)' }}</h1>
            <span class="cms-badge cms-badge--{{ ($asset['visibility'] ?? 'public') === 'public' ? 'published' : 'draft' }}">
                {{ ucfirst($asset['visibility'] ?? 'public') }}
            </span>
        </div>
        <div class="cms-media-show__actions">
            @can('cms.media.upload')
                <button type="button" class="cms-btn cms-btn--outline" data-cms-media-replace>Replace File</button>
            @endcan
            <button type="button" class="cms-btn cms-btn--outline" data-cms-copy-url="{{ $asset['public_url'] ?? '' }}">Copy URL</button>
            @can('cms.media.delete')
                <form method="POST" action="/admin/cms/media/{{ $asset['id'] }}" class="cms-inline-form" data-cms-confirm="Delete this media asset? All derivatives will also be removed." data-cms-confirm-reason>
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="cms-btn cms-btn--danger">Delete</button>
                </form>
            @endcan
            <a href="/admin/cms/media" class="cms-btn cms-btn--outline">Back to Library</a>
        </div>
    </header>

    <div class="cms-media-show__layout">
        {{-- Preview panel --}}
        <div class="cms-media-show__preview-panel">
            <div class="cms-media-show__preview">
                @if (str_starts_with($asset['mime_type'] ?? '', 'image/') && ($asset['mime_type'] ?? '') !== 'image/svg+xml')
                    <img src="{{ $asset['url'] ?? '' }}"
                         alt="{{ $asset['alt_text'] ?? $asset['filename'] ?? '' }}"
                         class="cms-media-show__image">
                @elseif (($asset['mime_type'] ?? '') === 'image/svg+xml')
                    <img src="{{ $asset['url'] ?? '' }}"
                         alt="{{ $asset['alt_text'] ?? $asset['filename'] ?? '' }}"
                         class="cms-media-show__image cms-media-show__image--svg">
                @elseif (($asset['mime_type'] ?? '') === 'application/pdf')
                    <div class="cms-media-show__file-icon">
                        <span aria-hidden="true">&#128196;</span>
                        <span>PDF Document</span>
                    </div>
                @elseif (str_starts_with($asset['mime_type'] ?? '', 'video/'))
                    <div class="cms-media-show__file-icon">
                        <span aria-hidden="true">&#127910;</span>
                        <span>Video File</span>
                    </div>
                @elseif (str_starts_with($asset['mime_type'] ?? '', 'audio/'))
                    <div class="cms-media-show__file-icon">
                        <span aria-hidden="true">&#127925;</span>
                        <span>Audio File</span>
                    </div>
                @else
                    <div class="cms-media-show__file-icon">
                        <span aria-hidden="true">&#128196;</span>
                        <span>{{ $asset['mime_type'] ?? 'File' }}</span>
                    </div>
                @endif
            </div>

            {{-- Replace file form (hidden) --}}
            @can('cms.media.upload')
                <form method="POST"
                      action="/admin/cms/media/{{ $asset['id'] }}/replace"
                      enctype="multipart/form-data"
                      class="cms-media-show__replace-form"
                      data-cms-replace-form
                      hidden>
                    @csrf
                    @method('PUT')
                    <div class="cms-form-group">
                        <label for="replace-file" class="cms-form-group__label">Select Replacement File</label>
                        <input type="file" id="replace-file" name="file" class="cms-form-group__input" required aria-required="true">
                    </div>
                    <div class="cms-media-show__replace-actions">
                        <button type="submit" class="cms-btn cms-btn--primary">Upload Replacement</button>
                        <button type="button" class="cms-btn cms-btn--outline" data-cms-replace-cancel>Cancel</button>
                    </div>
                </form>
            @endcan
        </div>

        {{-- Detail sidebar --}}
        <aside class="cms-media-show__sidebar">
            {{-- File details --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">File Details</h3>
                <div class="cms-sidebar-panel__body">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Filename</dt>
                        <dd class="cms-detail-list__value"><code>{{ $asset['filename'] ?? '' }}</code></dd>

                        <dt class="cms-detail-list__term">MIME Type</dt>
                        <dd class="cms-detail-list__value">{{ $asset['mime_type'] ?? '' }}</dd>

                        <dt class="cms-detail-list__term">File Size</dt>
                        <dd class="cms-detail-list__value">{{ $asset['human_size'] ?? '' }}</dd>

                        @if (isset($asset['width']) && isset($asset['height']))
                            <dt class="cms-detail-list__term">Dimensions</dt>
                            <dd class="cms-detail-list__value">{{ $asset['width'] }}&times;{{ $asset['height'] }} px</dd>
                        @endif

                        <dt class="cms-detail-list__term">Uploaded By</dt>
                        <dd class="cms-detail-list__value">{{ $asset['uploader_name'] ?? $asset['uploaded_by'] ?? '' }}</dd>

                        <dt class="cms-detail-list__term">Uploaded</dt>
                        <dd class="cms-detail-list__value">
                            <time datetime="{{ $asset['created_at'] ?? '' }}">{{ $asset['created_at_human'] ?? $asset['created_at'] ?? '' }}</time>
                        </dd>

                        <dt class="cms-detail-list__term">Hash</dt>
                        <dd class="cms-detail-list__value"><code class="cms-hash">{{ substr($asset['content_hash'] ?? '', 0, 16) }}...</code></dd>
                    </dl>
                </div>
            </div>

            {{-- Alt text per locale --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Alt Text</h3>
                <div class="cms-sidebar-panel__body">
                    <form method="POST" action="/admin/cms/media/{{ $asset['id'] }}/translations" data-cms-alt-text-form>
                        @csrf
                        @method('PUT')

                        @if (isset($locales) && count($locales) > 0)
                            <div class="cms-media-show__locale-tabs" role="tablist" aria-label="Alt text by locale">
                                @foreach ($locales as $locIndex => $locale)
                                    <button type="button"
                                            class="cms-media-show__locale-tab @if ($locIndex === 0) cms-media-show__locale-tab--active @endif"
                                            role="tab"
                                            aria-selected="{{ $locIndex === 0 ? 'true' : 'false' }}"
                                            data-cms-alt-locale="{{ $locale }}">
                                        {{ strtoupper($locale) }}
                                    </button>
                                @endforeach
                            </div>
                            @foreach ($locales as $locIndex => $locale)
                                <?php /** @var string $locale */ /** @var array<string, array{alt_text: string}> $translations */ $__altText = $translations[$locale]['alt_text'] ?? ''; ?>
                                <div class="cms-media-show__alt-panel @if ($locIndex > 0) cms-media-show__alt-panel--hidden @endif"
                                     data-cms-alt-panel="{{ $locale }}"
                                     role="tabpanel">
                                    <div class="cms-form-group">
                                        <label for="alt-text-{{ $locale }}" class="cms-form-group__label">Alt text ({{ strtoupper($locale) }})</label>
                                        <input type="text"
                                               id="alt-text-{{ $locale }}"
                                               name="alt_text[{{ $locale }}]"
                                               value="{{ $__altText }}"
                                               class="cms-form-group__input"
                                               maxlength="500"
                                               placeholder="Describe the image for accessibility">
                                    </div>
                                    <div class="cms-form-group">
                                        <label for="caption-{{ $locale }}" class="cms-form-group__label">Caption ({{ strtoupper($locale) }})</label>
                                        <input type="text"
                                               id="caption-{{ $locale }}"
                                               name="caption[{{ $locale }}]"
                                               value="{{ $translations[$locale]['caption'] ?? '' }}"
                                               class="cms-form-group__input"
                                               maxlength="1000">
                                    </div>
                                </div>
                            @endforeach
                        @endif

                        <button type="submit" class="cms-btn cms-btn--primary cms-btn--full">Save Translations</button>
                    </form>
                </div>
            </div>

            {{-- Structured EXIF / Photo Metadata --}}
            @if (!empty($metadata))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">
                        <button type="button" class="cms-sidebar-panel__toggle" data-cms-collapsible-toggle aria-expanded="true">
                            Photo Metadata
                        </button>
                    </h3>
                    <div class="cms-sidebar-panel__body" data-cms-collapsible-body>
                        <form method="POST" action="/admin/cms/media/{{ $asset['id'] }}/metadata" data-cms-metadata-form>
                            @csrf
                            @method('PUT')
                            <dl class="cms-detail-list cms-detail-list--compact">
                                @if (!empty($metadata['camera_model']))
                                    <dt class="cms-detail-list__term">Camera</dt>
                                    <dd class="cms-detail-list__value">{{ ($metadata['camera_make'] ?? '') . ' ' . $metadata['camera_model'] }}</dd>
                                @endif

                                @if (!empty($metadata['lens']))
                                    <dt class="cms-detail-list__term">Lens</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['lens'] }}</dd>
                                @endif

                                @if (!empty($metadata['focal_length']))
                                    <dt class="cms-detail-list__term">Focal Length</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['focal_length'] }}</dd>
                                @endif

                                @if (!empty($metadata['aperture']))
                                    <dt class="cms-detail-list__term">Aperture</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['aperture'] }}</dd>
                                @endif

                                @if (!empty($metadata['exposure_time']))
                                    <dt class="cms-detail-list__term">Exposure</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['exposure_time'] }}</dd>
                                @endif

                                @if (!empty($metadata['iso']))
                                    <dt class="cms-detail-list__term">ISO</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['iso'] }}</dd>
                                @endif

                                @if (!empty($metadata['flash']))
                                    <dt class="cms-detail-list__term">Flash</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['flash'] }}</dd>
                                @endif

                                @if (!empty($metadata['white_balance']))
                                    <dt class="cms-detail-list__term">White Balance</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['white_balance'] }}</dd>
                                @endif

                                @if (!empty($metadata['color_space']))
                                    <dt class="cms-detail-list__term">Color Space</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['color_space'] }}</dd>
                                @endif

                                @if (!empty($metadata['date_taken']))
                                    <dt class="cms-detail-list__term">Date Taken</dt>
                                    <dd class="cms-detail-list__value">
                                        <input type="text"
                                               name="date_taken"
                                               value="{{ $metadata['date_taken'] }}"
                                               class="cms-form-group__input cms-form-group__input--inline"
                                               placeholder="Date taken">
                                    </dd>
                                @endif

                                @if (isset($metadata['gps_latitude']) && isset($metadata['gps_longitude']))
                                    <dt class="cms-detail-list__term">GPS</dt>
                                    <dd class="cms-detail-list__value">
                                        <span>{{ $metadata['gps_latitude'] }}, {{ $metadata['gps_longitude'] }}</span>
                                        <button type="button" class="cms-btn cms-btn--xs cms-btn--outline" data-cms-remove-gps>Remove GPS</button>
                                        <input type="hidden" name="remove_gps" value="0" data-cms-remove-gps-input>
                                    </dd>
                                @endif

                                @if (!empty($metadata['x_resolution']))
                                    <dt class="cms-detail-list__term">Resolution</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['x_resolution'] }}&times;{{ $metadata['y_resolution'] ?? $metadata['x_resolution'] }} DPI</dd>
                                @endif

                                @if (!empty($metadata['software']))
                                    <dt class="cms-detail-list__term">Software</dt>
                                    <dd class="cms-detail-list__value">{{ $metadata['software'] }}</dd>
                                @endif
                            </dl>
                            <button type="submit" class="cms-btn cms-btn--primary cms-btn--sm">Save Metadata</button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Legacy EXIF data (raw key/value) --}}
            @if (!empty($exifData) && empty($metadata))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">
                        <button type="button" class="cms-sidebar-panel__toggle" data-cms-collapsible-toggle aria-expanded="false">
                            EXIF Data
                        </button>
                    </h3>
                    <div class="cms-sidebar-panel__body" data-cms-collapsible-body hidden>
                        <dl class="cms-detail-list cms-detail-list--compact">
                            @foreach ($exifData as $exifKey => $exifValue)
                                <dt class="cms-detail-list__term">{{ $exifKey }}</dt>
                                <dd class="cms-detail-list__value">{{ $exifValue }}</dd>
                            @endforeach
                        </dl>
                    </div>
                </div>
            @endif

            {{-- Licensing --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Licensing</h3>
                <div class="cms-sidebar-panel__body">
                    <form method="POST" action="/admin/cms/media/{{ $asset['id'] }}/license" data-cms-license-form>
                        @csrf
                        @method('PUT')
                        <div class="cms-form-group">
                            <label for="license" class="cms-form-group__label">License</label>
                            <select id="license" name="license" class="cms-form-group__select">
                                <option value="">None</option>
                                <option value="CC-BY" @if (($asset['license'] ?? '') === 'CC-BY') selected @endif>CC Attribution</option>
                                <option value="CC-BY-SA" @if (($asset['license'] ?? '') === 'CC-BY-SA') selected @endif>CC Attribution-ShareAlike</option>
                                <option value="CC-BY-NC" @if (($asset['license'] ?? '') === 'CC-BY-NC') selected @endif>CC Attribution-NonCommercial</option>
                                <option value="CC-BY-NC-SA" @if (($asset['license'] ?? '') === 'CC-BY-NC-SA') selected @endif>CC Attribution-NC-ShareAlike</option>
                                <option value="CC-BY-ND" @if (($asset['license'] ?? '') === 'CC-BY-ND') selected @endif>CC Attribution-NoDerivatives</option>
                                <option value="CC-BY-NC-ND" @if (($asset['license'] ?? '') === 'CC-BY-NC-ND') selected @endif>CC Attribution-NC-NoDerivatives</option>
                                <option value="CC0" @if (($asset['license'] ?? '') === 'CC0') selected @endif>CC0 Public Domain</option>
                                <option value="All Rights Reserved" @if (($asset['license'] ?? '') === 'All Rights Reserved') selected @endif>All Rights Reserved</option>
                                <option value="Custom" @if (($asset['license'] ?? '') === 'Custom') selected @endif>Custom</option>
                            </select>
                        </div>
                        <div class="cms-form-group">
                            <label for="copyright-holder" class="cms-form-group__label">Copyright Holder</label>
                            <input type="text"
                                   id="copyright-holder"
                                   name="copyright_holder"
                                   value="{{ $asset['copyright_holder'] ?? '' }}"
                                   class="cms-form-group__input"
                                   maxlength="255"
                                   placeholder="Name or organization">
                        </div>
                        <button type="submit" class="cms-btn cms-btn--primary cms-btn--sm">Save License</button>
                    </form>
                </div>
            </div>

            {{-- SEO Metadata --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">SEO</h3>
                <div class="cms-sidebar-panel__body">
                    <form method="POST" action="/admin/cms/media/{{ $asset['id'] }}/seo" data-cms-seo-form>
                        @csrf
                        @method('PUT')
                        <div class="cms-form-group">
                            <label for="seo-title" class="cms-form-group__label">Title</label>
                            <input type="text"
                                   id="seo-title"
                                   name="title"
                                   value="{{ $asset['title'] ?? '' }}"
                                   class="cms-form-group__input"
                                   maxlength="255">
                        </div>
                        <div class="cms-form-group">
                            <label for="seo-description" class="cms-form-group__label">Description</label>
                            <textarea id="seo-description"
                                      name="description"
                                      class="cms-form-group__textarea"
                                      rows="3"
                                      maxlength="1000">{{ $asset['description'] ?? '' }}</textarea>
                        </div>
                        <div class="cms-form-group">
                            <label for="seo-caption" class="cms-form-group__label">Caption</label>
                            <input type="text"
                                   id="seo-caption"
                                   name="caption"
                                   value="{{ $asset['caption'] ?? '' }}"
                                   class="cms-form-group__input"
                                   maxlength="500">
                        </div>
                        <button type="submit" class="cms-btn cms-btn--primary cms-btn--sm">Save SEO</button>
                    </form>
                </div>
            </div>

            {{-- Derivatives --}}
            @if (!empty($derivatives))
                <div class="cms-sidebar-panel">
                    <h3 class="cms-sidebar-panel__title">Derivatives</h3>
                    <div class="cms-sidebar-panel__body">
                        <table class="cms-table cms-table--compact">
                            <thead class="cms-table__head">
                                <tr>
                                    <th class="cms-table__th" scope="col">Variant</th>
                                    <th class="cms-table__th" scope="col">Format</th>
                                    <th class="cms-table__th" scope="col">Size</th>
                                    <th class="cms-table__th" scope="col">Dimensions</th>
                                </tr>
                            </thead>
                            <tbody class="cms-table__body">
                                @foreach ($derivatives as $derivative)
                                    <tr class="cms-table__row">
                                        <td class="cms-table__td"><code>{{ $derivative['variant'] ?? '' }}</code></td>
                                        <td class="cms-table__td">{{ $derivative['format'] ?? '' }}</td>
                                        <td class="cms-table__td">{{ $derivative['human_size'] ?? '' }}</td>
                                        <td class="cms-table__td">
                                            @if (isset($derivative['width']) && isset($derivative['height']))
                                                {{ $derivative['width'] }}&times;{{ $derivative['height'] }}
                                            @else
                                                &mdash;
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- Public URL --}}
            <div class="cms-sidebar-panel">
                <h3 class="cms-sidebar-panel__title">Public URL</h3>
                <div class="cms-sidebar-panel__body">
                    <div class="cms-media-show__url-group">
                        <input type="text"
                               class="cms-form-group__input"
                               value="{{ $asset['public_url'] ?? '' }}"
                               readonly
                               aria-label="Public URL"
                               data-cms-url-field>
                        <button type="button" class="cms-btn cms-btn--outline" data-cms-copy-url="{{ $asset['public_url'] ?? '' }}">Copy</button>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

@include('cms::admin._partials.confirm-modal')
@endsection
