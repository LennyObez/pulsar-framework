<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Protobuf;

use Pulsar\Api\Internal;

/**
 * Protobuf field numbers pinned to OTLP proto v1.3.1.
 *
 * @see https://github.com/open-telemetry/opentelemetry-proto/tree/v1.3.1
 */
#[Internal(reason: 'Wire format constants for OTLP protobuf encoding')]
final class OtlpFieldNumbers
{
    //: ExportTraceServiceRequest --
    public const int RESOURCE_SPANS = 1;

    //: ResourceSpans --
    public const int RS_RESOURCE = 1;
    public const int RS_SCOPE_SPANS = 2;

    //: ScopeSpans --
    public const int SS_SCOPE = 1;
    public const int SS_SPANS = 2;

    //: Span --
    public const int SPAN_TRACE_ID = 1;
    public const int SPAN_SPAN_ID = 2;
    public const int SPAN_PARENT_SPAN_ID = 4;
    public const int SPAN_NAME = 5;
    public const int SPAN_KIND = 6;
    public const int SPAN_START_TIME = 7;
    public const int SPAN_END_TIME = 8;
    public const int SPAN_ATTRIBUTES = 9;
    public const int SPAN_STATUS = 15;

    //: Status --
    public const int STATUS_MESSAGE = 2;
    public const int STATUS_CODE = 3;

    //: KeyValue --
    public const int KV_KEY = 1;
    public const int KV_VALUE = 2;

    //: AnyValue --
    public const int AV_STRING = 1;
    public const int AV_INT = 3;
    public const int AV_DOUBLE = 4;
    public const int AV_BOOL = 2;

    //: Resource --
    public const int RESOURCE_ATTRIBUTES = 1;

    //: InstrumentationScope --
    public const int SCOPE_NAME = 1;
    public const int SCOPE_VERSION = 2;

    //: ExportMetricsServiceRequest --
    public const int RESOURCE_METRICS = 1;

    //: ResourceMetrics --
    public const int RM_RESOURCE = 1;
    public const int RM_SCOPE_METRICS = 2;

    //: ScopeMetrics --
    public const int SM_SCOPE = 1;
    public const int SM_METRICS = 2;

    //: Metric --
    public const int METRIC_NAME = 1;
    public const int METRIC_DESCRIPTION = 2;
    public const int METRIC_UNIT = 3;
    public const int METRIC_GAUGE = 5;
    public const int METRIC_SUM = 7;
    public const int METRIC_HISTOGRAM = 9;

    //: Gauge --
    public const int GAUGE_DATA_POINTS = 1;

    //: Sum --
    public const int SUM_DATA_POINTS = 1;
    public const int SUM_AGGREGATION_TEMPORALITY = 2;
    public const int SUM_IS_MONOTONIC = 3;

    //: NumberDataPoint --
    public const int NDP_ATTRIBUTES = 7;
    public const int NDP_START_TIME_UNIX_NANO = 2;
    public const int NDP_TIME_UNIX_NANO = 3;
    public const int NDP_AS_DOUBLE = 4;
    public const int NDP_AS_INT = 6;

    //: HistogramDataPoint --
    public const int HDP_TIME_UNIX_NANO = 3;
    public const int HDP_COUNT = 4;
    public const int HDP_SUM = 5;
    public const int HDP_BUCKET_COUNTS = 6;
    public const int HDP_EXPLICIT_BOUNDS = 7;
    public const int HDP_ATTRIBUTES = 9;

    //: ExportLogsServiceRequest --
    public const int RESOURCE_LOGS = 1;

    //: ResourceLogs --
    public const int RL_RESOURCE = 1;
    public const int RL_SCOPE_LOGS = 2;

    //: ScopeLogs --
    public const int SL_SCOPE = 1;
    public const int SL_LOG_RECORDS = 2;

    //; LogRecord --
    public const int LR_TIME_UNIX_NANO = 1;
    public const int LR_SEVERITY_NUMBER = 2;
    public const int LR_BODY = 5;
    public const int LR_ATTRIBUTES = 6;
    public const int LR_TRACE_ID = 9;
    public const int LR_SPAN_ID = 10;
    public const int LR_SEVERITY_TEXT = 3;

    //: Aggregation Temporality --
    public const int AGGREGATION_TEMPORALITY_CUMULATIVE = 2;

    //: Histogram --
    public const int HISTOGRAM_DATA_POINTS = 1;

    //: SpanKind --
    public const int SPAN_KIND_INTERNAL = 1;
    public const int SPAN_KIND_CLIENT = 3;
    public const int SPAN_KIND_PRODUCER = 4;
    public const int SPAN_KIND_CONSUMER = 5;

    //: StatusCode --
    public const int STATUS_CODE_UNSET = 0;
    public const int STATUS_CODE_OK = 1;
    public const int STATUS_CODE_ERROR = 2;
}
