<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Override;
use Pulsar\Api\Internal;

/**
 * Pagination directive: renders accessible page navigation.
 *
 * Usage: {@}pagination($paginator)
 *
 * Compiles to a PHP block that iterates the paginator's links() and
 * renders an accessible nav element using pui-pagination CSS classes.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class PaginationDirective implements DirectiveInterface
{
    #[Override]
    public function name(): string
    {
        return 'pagination';
    }

    #[Override]
    public function compile(string $expression): string
    {
        return <<<PHP
            <?php
            \$__paginator = {$expression};
            \$__links = \$__paginator->links();
            if (\$__paginator->lastPage > 1): ?>
            <nav aria-label="Pagination">
              <ul class="pui-pagination">
                <?php foreach (\$__links as \$__link): ?>
                  <?php if (\$__link->isEllipsis): ?>
                    <li class="pui-pagination__item">
                      <span class="pui-pagination__ellipsis">&hellip;</span>
                    </li>
                  <?php elseif (\$__link->isDisabled): ?>
                    <li class="pui-pagination__item">
                      <span class="pui-pagination__link pui-pagination__link--disabled"><?= \$__link->label ?></span>
                    </li>
                  <?php elseif (\$__link->isActive): ?>
                    <li class="pui-pagination__item">
                      <a class="pui-pagination__link" href="?page=<?= \$__link->page ?>" aria-current="page"><?= \$__link->label ?></a>
                    </li>
                  <?php else: ?>
                    <li class="pui-pagination__item">
                      <a class="pui-pagination__link" href="?page=<?= \$__link->page ?>"><?= \$__link->label ?></a>
                    </li>
                  <?php endif; ?>
                <?php endforeach; ?>
              </ul>
            </nav>
            <?php endif; ?>
            <?php unset(\$__paginator, \$__links, \$__link); ?>
            PHP;
    }
}
