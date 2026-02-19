@extends('admin.layout')

@section('title', 'Install Plugin')

@section('content')
<div class="cms-plugin-install">
    <header class="cms-plugin-install__header">
        <h1 class="cms-plugin-install__title">Install Plugin</h1>
        <a href="/admin/cms/plugins" class="cms-btn cms-btn--outline">Back to Plugins</a>
    </header>

    @if (isset($installed) && $installed)
        {{-- Post-upload: show manifest details, capability review, provenance, confirm --}}
        <div class="cms-plugin-install__wizard">
            {{-- Step 1: Manifest details --}}
            <section class="cms-plugin-install__step" aria-labelledby="step-manifest-heading">
                <h2 class="cms-plugin-install__section-title" id="step-manifest-heading">
                    <span class="cms-plugin-install__step-number">1</span>
                    Plugin Details
                </h2>
                <div class="cms-plugin-install__details">
                    <dl class="cms-detail-list">
                        <dt class="cms-detail-list__term">Name</dt>
                        <dd class="cms-detail-list__value">{{ $plugin['display_name'] ?? '' }}</dd>

                        <dt class="cms-detail-list__term">Slug</dt>
                        <dd class="cms-detail-list__value"><code>{{ $plugin['slug'] ?? '' }}</code></dd>

                        <dt class="cms-detail-list__term">Version</dt>
                        <dd class="cms-detail-list__value">{{ $plugin['version'] ?? '' }}</dd>

                        @if (isset($plugin['author']))
                            <dt class="cms-detail-list__term">Author</dt>
                            <dd class="cms-detail-list__value">{{ $plugin['author'] }}</dd>
                        @endif

                        @if (isset($plugin['license']))
                            <dt class="cms-detail-list__term">License</dt>
                            <dd class="cms-detail-list__value">{{ $plugin['license'] }}</dd>
                        @endif

                        @if (isset($plugin['description']))
                            <dt class="cms-detail-list__term">Description</dt>
                            <dd class="cms-detail-list__value">{{ $plugin['description'] }}</dd>
                        @endif
                    </dl>
                </div>
            </section>

            {{-- Step 2: Capability review --}}
            <section class="cms-plugin-install__step" aria-labelledby="step-capabilities-heading">
                <h2 class="cms-plugin-install__section-title" id="step-capabilities-heading">
                    <span class="cms-plugin-install__step-number">2</span>
                    Declared Capabilities
                </h2>
                @if (!empty($plugin['capabilities'] ?? []))
                    <table class="cms-table cms-table--compact">
                        <thead class="cms-table__head">
                            <tr>
                                <th class="cms-table__th">Capability</th>
                                <th class="cms-table__th">Description</th>
                            </tr>
                        </thead>
                        <tbody class="cms-table__body">
                            @foreach ($plugin['capabilities'] as $cap)
                                <tr class="cms-table__row">
                                    <td class="cms-table__td"><span class="cms-badge cms-badge--in-review">{{ $cap['name'] ?? $cap }}</span></td>
                                    <td class="cms-table__td">{{ $cap['description'] ?? 'No description provided.' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="cms-plugin-install__no-caps">This plugin declares no special capabilities.</p>
                @endif
            </section>

            {{-- Step 3: Provenance verification --}}
            <section class="cms-plugin-install__step" aria-labelledby="step-provenance-heading">
                <h2 class="cms-plugin-install__section-title" id="step-provenance-heading">
                    <span class="cms-plugin-install__step-number">3</span>
                    Provenance Verification
                </h2>
                <div class="cms-plugin-install__provenance-badges">
                    @if ($plugin['hash_verified'] ?? false)
                        <span class="cms-badge cms-badge--approved">Hash Verified</span>
                    @else
                        <span class="cms-badge cms-badge--archived">Hash Not Verified</span>
                    @endif

                    @if ($plugin['signature_verified'] ?? false)
                        <span class="cms-badge cms-badge--approved">Signature Verified</span>
                    @else
                        <span class="cms-badge cms-badge--in-review">Unsigned</span>
                    @endif
                </div>

                @if (!($plugin['signature_verified'] ?? false))
                    <div class="cms-alert cms-alert--warning" role="alert">
                        <p>This plugin is not cryptographically signed. Only install plugins from trusted sources.</p>
                    </div>
                @endif
            </section>

            {{-- Step 4: Confirm installation --}}
            <section class="cms-plugin-install__step" aria-labelledby="step-confirm-heading">
                <h2 class="cms-plugin-install__section-title" id="step-confirm-heading">
                    <span class="cms-plugin-install__step-number">4</span>
                    Confirm Installation
                </h2>
                <div class="cms-plugin-install__confirm-actions">
                    <form method="POST" action="/admin/cms/plugins/{{ $plugin['id'] }}/enable" class="cms-inline-form">
                        @csrf
                        <button type="submit" class="cms-btn cms-btn--primary">Enable Now</button>
                    </form>
                    <a href="/admin/cms/plugins" class="cms-btn cms-btn--outline">Enable Later</a>
                </div>
            </section>
        </div>
    @else
        {{-- Upload form --}}
        <section class="cms-plugin-install__upload" aria-labelledby="upload-heading">
            <h2 class="cms-plugin-install__section-title" id="upload-heading">Upload Plugin Package</h2>

            <form method="POST"
                  action="/admin/cms/plugins/install"
                  enctype="multipart/form-data"
                  class="cms-form"
                  data-cms-plugin-upload>
                @csrf

                <div class="cms-form-group">
                    <label for="plugin-file" class="cms-form-group__label">Plugin ZIP Archive</label>
                    <div class="cms-plugin-install__dropzone" data-cms-dropzone>
                        <input type="file"
                               id="plugin-file"
                               name="file"
                               class="cms-plugin-install__file-input"
                               accept=".zip,application/zip"
                               required>
                        <div class="cms-plugin-install__dropzone-content">
                            <p class="cms-plugin-install__dropzone-text">Drag and drop a plugin archive here, or click to browse</p>
                            <p class="cms-form-group__hint">Upload a <code>.zip</code> archive containing the plugin package with a valid <code>pulsar.json</code> manifest.</p>
                        </div>
                    </div>
                </div>

                @if (isset($error))
                    <div class="cms-alert cms-alert--danger" role="alert">
                        <p>{{ $error }}</p>
                    </div>
                @endif

                <button type="submit" class="cms-btn cms-btn--primary">Upload &amp; Install</button>
            </form>
        </section>

        <script>
        (function () {
            var dropzone = document.querySelector('[data-cms-dropzone]');
            var input = dropzone ? dropzone.querySelector('input[type="file"]') : null;
            if (!dropzone || !input) return;

            dropzone.addEventListener('click', function () { input.click(); });
            dropzone.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropzone.classList.add('cms-plugin-install__dropzone--active');
            });
            dropzone.addEventListener('dragleave', function () {
                dropzone.classList.remove('cms-plugin-install__dropzone--active');
            });
            dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                dropzone.classList.remove('cms-plugin-install__dropzone--active');
                if (e.dataTransfer.files.length > 0) {
                    input.files = e.dataTransfer.files;
                    var nameEl = dropzone.querySelector('.cms-plugin-install__dropzone-text');
                    if (nameEl) nameEl.textContent = e.dataTransfer.files[0].name;
                }
            });
            input.addEventListener('change', function () {
                if (input.files.length > 0) {
                    var nameEl = dropzone.querySelector('.cms-plugin-install__dropzone-text');
                    if (nameEl) nameEl.textContent = input.files[0].name;
                }
            });
        })();
        </script>
    @endif
</div>
@endsection
