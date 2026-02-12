<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Command;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Console\Command;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;

use function sprintf;

/**
 * Processes scheduled content publishing and unpublishing transitions.
 *
 * Intended to be run periodically (e.g., via cron every minute) to:
 *  - Publish content whose scheduled_publish_at time has arrived
 *  - Archive content whose scheduled_unpublish_at time has arrived
 */
#[Internal(reason: 'CMS scheduled publishing command')]
final class CmsPublishScheduledCommand extends Command
{
    public function __construct(
        private readonly ContentRepositoryInterface $contentRepository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->name = 'cms:publish-scheduled';
        $this->description = 'Publish scheduled content and archive expired content';
    }

    #[Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable();
        $publishedCount = 0;
        $unpublishedCount = 0;

        $toPublish = $this->contentRepository->findScheduledForPublishing($now);

        foreach ($toPublish as $content) {
            $updated = $content->publish();
            $this->contentRepository->save($updated);
            $publishedCount++;
        }

        $toUnpublish = $this->contentRepository->findScheduledForUnpublishing($now);

        foreach ($toUnpublish as $content) {
            $updated = $content->archive();
            $this->contentRepository->save($updated);
            $unpublishedCount++;
        }

        $output->info(sprintf('Published %d, archived %d content item(s).', $publishedCount, $unpublishedCount));

        return ExitCode::Success->value;
    }
}
