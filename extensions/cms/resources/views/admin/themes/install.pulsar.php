@extends('admin.layout')

@section('title', 'Install Theme')

@section('content')
<div class="cms-theme-install">
    <header class="cms-theme-install__header">
        <h1 class="cms-theme-install__title">Install Theme</h1>
        <a href="/admin/cms/themes" class="cms-btn cms-btn--outline">Back to Themes</a>
    </header>

    @if (isset($installed) && $installed)
        {{-- Post-upload: show manifest details, provenance result, confirm/cancel --}}
        <section class="cms-theme-install__result" aria-labelledby="install-result-heading">
            <h2 class="cms-theme-install__section-title" id="install-result-heading">Theme Installed Successfully</h2>

            <div class="cms-theme-install__details">
                <dl class="cms-detail-list">
                    <dt class="cms-detail-list__term">Name</dt>
                    <dd class="cms-detail-list__value">{{ $theme['display_name'] ?? '' }}</dd>

                    <dt class="cms-detail-list__term">Slug</dt>
                    <dd class="cms-detail-list__value"><code>{{ $theme['slug'] ?? '' }}</code></dd>

                    <dt class="cms-detail-list__term">Version</dt>
                    <dd class="cms-detail-list__value">{{ $theme['version'] ?? '' }}</dd>

                    @if (isset($theme['description']))
                        <dt class="cms-detail-list__term">Description</dt>
                        <dd class="cms-detail-list__value">{{ $theme['description'] }}</dd>
                    @endif

                    @if (isset($theme['author_name']))
                        <dt class="cms-detail-list__term">Author</dt>
                        <dd class="cms-detail-list__value">{{ $theme['author_name'] }}</dd>
                    @endif

                    @if (isset($theme['license']))
                        <dt class="cms-detail-list__term">License</dt>
                        <dd class="cms-detail-list__value">{{ $theme['license'] }}</dd>
                    @endif
                </dl>
            </div>

            <div class="cms-theme-install__provenance">
                <h3 class="cms-theme-install__provenance-title">Provenance Verification</h3>
                <div class="cms-theme-install__provenance-badges">
                    @if ($theme['provenance_verified'] ?? false)
                        <span class="cms-badge cms-badge--approved">Hash Verified</span>
                    @else
                        <span class="cms-badge cms-badge--archived">Hash Not Verified</span>
                    @endif

                    @if ($theme['signature_verified'] ?? false)
                        <span class="cms-badge cms-badge--approved">Signature Verified</span>
                    @else
                        <span class="cms-badge cms-badge--in-review">Unsigned</span>
                    @endif
                </div>
            </div>

            <div class="cms-theme-install__confirm-actions">
                <form method="POST" action="/admin/cms/themes/{{ $theme['id'] }}/activate" class="cms-inline-form">
                    @csrf
                    <button type="submit" class="cms-btn cms-btn--primary">Activate Now</button>
                </form>
                <a href="/admin/cms/themes" class="cms-btn cms-btn--outline">Activate Later</a>
            </div>
        </section>
    @else
        {{-- Upload form --}}
        <section class="cms-theme-install__upload" aria-labelledby="upload-heading">
            <h2 class="cms-theme-install__section-title" id="upload-heading">Upload Theme Package</h2>

            <form method="POST"
                  action="/admin/cms/themes/install"
                  enctype="multipart/form-data"
                  class="cms-form">
                @csrf

                <div class="cms-form-group">
                    <label for="theme-file" class="cms-form-group__label">Theme ZIP Archive</label>
                    <input type="file"
                           id="theme-file"
                           name="file"
                           class="cms-form-group__input"
                           accept=".zip,application/zip"
                           required>
                    <p class="cms-form-group__hint">Upload a <code>.zip</code> archive containing the theme package with a valid <code>theme.json</code> manifest.</p>
                </div>

                @if (isset($error))
                    <div class="cms-alert cms-alert--danger" role="alert">
                        <p>{{ $error }}</p>
                    </div>
                @endif

                <button type="submit" class="cms-btn cms-btn--primary">Upload &amp; Install</button>
            </form>
        </section>
    @endif
</div>
@endsection
