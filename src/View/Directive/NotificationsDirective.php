<?php

declare(strict_types=1);

namespace Pulsar\View\Directive;

use Override;
use Pulsar\Api\Internal;

/**
 * Notifications directive: renders a bell icon with unread count.
 *
 * Usage: {@}notifications($userId)
 *
 * Compiles to a PHP block that queries the DatabaseNotificationRepository
 * for unread notifications and renders a dropdown bell component.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Directive implementation detail')]
final readonly class NotificationsDirective implements DirectiveInterface
{
    #[Override]
    public function name(): string
    {
        return 'notifications';
    }

    #[Override]
    public function compile(string $expression): string
    {
        return <<<'PHP'
            <?php
            $__notifUserId = $expression;
            $__notifRepo = $__notifRepo ?? null;
            $__notifCount = 0;
            $__notifItems = [];
            if ($__notifRepo instanceof \Pulsar\Notification\DatabaseNotificationRepository) {
                $__notifCount = $__notifRepo->unreadCount($__notifUserId);
                $__notifItems = $__notifRepo->unreadForNotifiable($__notifUserId, 10);
            }
            ?>
            <div class="pui-notifications" data-notifications>
              <button type="button" class="pui-notifications__bell" aria-label="Notifications (<?= $__notifCount ?> unread)" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <?php if ($__notifCount > 0): ?>
                  <span class="pui-notifications__badge" aria-hidden="true"><?= $__notifCount > 99 ? '99+' : $__notifCount ?></span>
                <?php endif; ?>
              </button>
              <div class="pui-notifications__dropdown" role="menu" hidden>
                <?php if ($__notifItems === []): ?>
                  <p class="pui-notifications__empty">No new notifications</p>
                <?php else: ?>
                  <ul class="pui-notifications__list">
                    <?php foreach ($__notifItems as $__notif): ?>
                      <li class="pui-notifications__item<?= $__notif->isUnread() ? ' pui-notifications__item--unread' : '' ?>">
                        <span class="pui-notifications__type"><?= htmlspecialchars(basename(str_replace('\\', '/', $__notif->type)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <time class="pui-notifications__time" datetime="<?= date('c', $__notif->createdAt) ?>"><?= date('M j, H:i', $__notif->createdAt) ?></time>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>
            <?php unset($__notifUserId, $__notifRepo, $__notifCount, $__notifItems, $__notif); ?>
            PHP;
    }
}
