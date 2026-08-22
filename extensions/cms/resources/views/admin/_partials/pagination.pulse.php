{{-- Pagination component. Expects: $page (int), $perPage (int), $total (int), $baseUrl (string) --}}
<?php
/** @var int $page */
/** @var int $perPage */
/** @var int $total */
/** @var string $baseUrl */
$__totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
$__prevPage = max(1, $page - 1);
$__nextPage = min($__totalPages, $page + 1);
$__separator = str_contains($baseUrl, '?') ? '&' : '?';
?>
@if ($__totalPages > 1)
    <nav class="cms-pagination" aria-label="Pagination">
        <ul class="cms-pagination__list">
            <li class="cms-pagination__item">
                <a href="{{ $baseUrl }}{{ $__separator }}page={{ $__prevPage }}"
                   class="cms-pagination__link @if ($page <= 1) cms-pagination__link--disabled @endif"
                   @if ($page <= 1) aria-disabled="true" tabindex="-1" @endif
                   aria-label="Previous page">&laquo; Previous</a>
            </li>

            @for ($__i = 1; $__i <= $__totalPages; $__i++)
                <li class="cms-pagination__item">
                    <a href="{{ $baseUrl }}{{ $__separator }}page={{ $__i }}"
                       class="cms-pagination__link @if ($__i === $page) cms-pagination__link--active @endif"
                       @if ($__i === $page) aria-current="page" @endif>{{ $__i }}</a>
                </li>
            @endfor

            <li class="cms-pagination__item">
                <a href="{{ $baseUrl }}{{ $__separator }}page={{ $__nextPage }}"
                   class="cms-pagination__link @if ($page >= $__totalPages) cms-pagination__link--disabled @endif"
                   @if ($page >= $__totalPages) aria-disabled="true" tabindex="-1" @endif
                   aria-label="Next page">Next &raquo;</a>
            </li>
        </ul>
    </nav>
@endif
