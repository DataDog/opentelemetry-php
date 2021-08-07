<?php

declare(strict_types=1);

namespace OpenTelemetry\Sdk\Trace\Sampler;

use Attribute;
use InvalidArgumentException;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Sdk\Trace\Attributes;
use OpenTelemetry\Sdk\Trace\Sampler;
use OpenTelemetry\Sdk\Trace\SamplingResult;
use OpenTelemetry\Sdk\Trace\Span;
use OpenTelemetry\Sdk\Trace\SpanContext;
use OpenTelemetry\Sdk\Trace\TraceState as TraceTraceState;
use OpenTelemetry\Trace as API;
use OpenTelemetry\Trace\TraceState;

/**
 * This implementation of the SamplerInterface records with given probability.
 * Example:
 * ```
 * use OpenTelemetry\Trace\TraceIdRatioBasedSamplerWithRatioAttribute;
 * $sampler = new TraceIdRatioBasedSamplerWithRatioAttribute(0.01);
 * ```
 */
class TraceIdRatioBasedSamplerWithRatioAttribute implements Sampler
{
    /**
     * @var float
     */
    private $probability;

    const TRACESTATE_KEY = "dd";
    const SAMPLE_RATE_KEY = "_sample_rate";

    /**
     * TraceIdRatioBasedSampler constructor.
     * @param float $probability Probability float value between 0.0 and 1.0.
     */
    public function __construct(float $probability)
    {
        if ($probability < 0.0 || $probability > 1.0) {
            throw new InvalidArgumentException('probability should be be between 0.0 and 1.0.');
        }
        $this->probability = $probability;
    }

    /**
     * Returns `SamplingResult` based on probability. Respects the parent `SampleFlag`
     * {@inheritdoc}
     */
    public function shouldSample(
        Context $parentContext,
        string $traceId,
        string $spanName,
        int $spanKind,
        ?API\Attributes $attributes = null,
        ?API\Links $links = null
    ): SamplingResult {
        $attributes = $attributes ?: new Attributes();

        // TODO: Add config to adjust which spans get sampled (only default from specification is implemented)
        $parentSpan = Span::extract($parentContext);
        $parentSpanContext = $parentSpan !== null ? $parentSpan->getContext() : SpanContext::getInvalid();
        $traceState = $parentSpanContext->getTraceState() ?: new TraceTraceState();
        $sampleRateFromState = $this->getSampleRateFromState($traceState);

        if ($sampleRateFromState !== null) {
            $attributes->setAttribute(self::SAMPLE_RATE_KEY, $sampleRateFromState);
        } else {
            $attributes->setAttribute(self::SAMPLE_RATE_KEY, $this->probability);
            $traceState = $traceState->with(self::TRACESTATE_KEY, (string)$this->probability);
        }

        $samplingDecision = $this->makeDecision($traceId);
        return new SamplingResult($samplingDecision, $attributes, $traceState);
    }

    public function getDescription(): string
    {
        return sprintf('%s{%.6F}', 'TraceIdRatioBasedSamplerWithRatioAttribute', $this->probability);
    }

    /**
     * Makes a sampling decision based on the trace id.
     *
     * @param string $traceId
     * @return int
     */
    private function makeDecision(string $traceId): int
    {
        /**
         * Since php can only store up to 63 bit positive integers
         */
        $traceIdLimit = (1 << 60) - 1;
        $lowerOrderBytes = hexdec(substr($traceId, strlen($traceId) - 15, 15));
        $traceIdCondition = $lowerOrderBytes < round($this->probability * $traceIdLimit);
        return $traceIdCondition ? SamplingResult::RECORD_AND_SAMPLE : SamplingResult::DROP;
    }

    private function getSampleRateFromState(TraceState $traceState): ?float
    {
        $ddState = $traceState->get(self::TRACESTATE_KEY);
        if ($ddState === null || $ddState === "" || strpos($ddState, '|') !== false) {
            return null;
        }

        if (!\is_numeric($ddState)) {
            return null;
        }

        return (float)$ddState;
    }
}
