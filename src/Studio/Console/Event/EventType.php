<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event;

use Pulsar\Api\Internal;

/**
 * All event types collected by Studio Console.
 */
#[Internal]
enum EventType: string
{
    case HttpRequest = 'http.request';
    case HttpResponse = 'http.response';
    case DatabaseQuery = 'db.query';
    case CacheOperation = 'cache.operation';
    case JobQueued = 'job.queued';
    case JobProcessing = 'job.processing';
    case JobCompleted = 'job.completed';
    case JobFailed = 'job.failed';
    case SchedulerRun = 'scheduler.run';
    case OutgoingHttp = 'outgoing_http.request';
    case Notification = 'notification.sent';
    case Exception = 'exception';
    case LogEntry = 'log.entry';
    case FeatureFlagEval = 'feature_flag.eval';
    case Heartbeat = 'heartbeat';
    case SupervisorRecycle = 'supervisor.recycle';
    case SupervisorHealing = 'supervisor.healing';
    case IntegrityCheck = 'integrity.check';
}
