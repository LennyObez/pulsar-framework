@extends('cms::admin.layout')

@section('title', 'Digital Assets')

@section('cms-content')
<div class="cms-content-list">
    <header class="cms-content-list__header">
        <h1 class="cms-content-list__title">Digital Assets | {{ $productName ?? 'Product' }}</h1>
        <div class="cms-content-list__actions">
            <a href="/admin/cms/products/{{ $productId }}/edit" class="cms-btn cms-btn--outline">Back to Product</a>
        </div>
    </header>

    {{-- Upload Form --}}
    @can('cms.commerce.products.edit')
        <section class="cms-card">
            <h2 class="cms-card__title">Upload Asset</h2>
            <form method="POST"
                  action="/admin/cms/products/{{ $productId }}/digital-assets"
                  enctype="multipart/form-data"
                  class="cms-upload-form">
                @csrf

                <div class="cms-form-group">
                    <label for="asset-file" class="cms-form-group__label">File <span class="cms-required" aria-label="required">*</span></label>
                    <input type="file"
                           id="asset-file"
                           name="file"
                           class="cms-form-group__input"
                           required
                           aria-required="true">
                </div>

                <div class="cms-form-group">
                    <label for="asset-max-downloads" class="cms-form-group__label">Max Downloads per Customer</label>
                    <input type="number"
                           id="asset-max-downloads"
                           name="max_downloads"
                           value="5"
                           class="cms-form-group__input"
                           min="1"
                           max="100">
                </div>

                <button type="submit" class="cms-btn cms-btn--primary">Upload</button>
            </form>
        </section>
    @endcan

    {{-- Assets Table --}}
    <table class="cms-table">
        <thead class="cms-table__head">
            <tr>
                <th class="cms-table__th" scope="col">Filename</th>
                <th class="cms-table__th" scope="col">Size</th>
                <th class="cms-table__th" scope="col">Hash</th>
                <th class="cms-table__th" scope="col">Max Downloads</th>
                <th class="cms-table__th" scope="col">Actions</th>
            </tr>
        </thead>
        <tbody class="cms-table__body">
            @if (empty($assets))
                <tr>
                    <td colspan="5" class="cms-table__empty">No digital assets uploaded for this product.</td>
                </tr>
            @endif

            @foreach ($assets as $asset)
                <?php /** @var array{file_name: string, file_size: int, file_hash: string, max_downloads: int} $asset */ ?>
                <tr class="cms-table__row">
                    <td class="cms-table__td">{{ $asset['file_name'] ?? '' }}</td>
                    <td class="cms-table__td">
                        <?php
                        /** @var int $__size */
                        $__size = $asset['file_size'] ?? 0;
                if ($__size >= 1048576) {
                    echo htmlspecialchars(number_format($__size / 1048576, 2), ENT_QUOTES, 'UTF-8') . ' MB';
                } elseif ($__size >= 1024) {
                    echo htmlspecialchars(number_format($__size / 1024, 1), ENT_QUOTES, 'UTF-8') . ' KB';
                } else {
                    echo htmlspecialchars((string) $__size, ENT_QUOTES, 'UTF-8') . ' B';
                }
                ?>
                    </td>
                    <td class="cms-table__td" title="{{ $asset['file_hash'] ?? '' }}">
                        <code>{{ substr($asset['file_hash'] ?? '', 0, 12) }}...</code>
                    </td>
                    <td class="cms-table__td">{{ $asset['max_downloads'] ?? 5 }}</td>
                    <td class="cms-table__td cms-table__td--actions">
                        <div class="cms-action-group" role="group" aria-label="Asset actions">
                            @can('cms.commerce.products.edit')
                                <form method="POST" action="/admin/cms/products/{{ $productId }}/digital-assets/{{ $asset['id'] }}" class="cms-inline-form" data-cms-confirm="Are you sure you want to delete this asset?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cms-btn cms-btn--sm cms-btn--danger" title="Delete">Delete</button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
