<?php declare(strict_types=1);
/**
 * Copyright 2017 OpenCensus Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenCensus\Trace;

use OpenCensus\Trace\EventHandler\SpanEventHandlerInterface;
use OpenCensus\Trace\EventHandler\NullEventHandler;
use OpenCensus\Utils\IdGenerator;

/**
 * This plain PHP class represents a single timed event within a Trace. Spans can
 * be nested and form a trace tree. Often, a trace contains a root span that
 * describes the end-to-end latency of an operation and, optionally, one or more subspans
 * for its suboperations. Spans do not need to be contiguous. There may be
 * gaps between spans in a trace.
 */
class Span
{
    use AttributeTrait;
    use DateFormatTrait;

    // See https://github.com/census-instrumentation/opencensus-specs/blob/master/trace/HTTP.md#attributes
    public const ATTRIBUTE_HOST = 'http.host';
    public const ATTRIBUTE_PORT = 'http.port';
    public const ATTRIBUTE_METHOD = 'http.method';
    public const ATTRIBUTE_PATH = 'http.path';
    public const ATTRIBUTE_ROUTE = 'http.route';
    public const ATTRIBUTE_USER_AGENT = 'http.user_agent';
    public const ATTRIBUTE_STATUS_CODE = 'http.status_code';

    public const KIND_UNSPECIFIED = 'SPAN_KIND_UNSPECIFIED';
    public const KIND_SERVER = 'SERVER';
    public const KIND_CLIENT = 'CLIENT';

    /**
     * Unique identifier for a trace. All spans from the same Trace share the
     * same `traceId`. 16-byte value encoded as a hex string.
     *
     * @var string
     */
    private $traceId;

    /**
     * Unique identifier for a span within a trace, assigned when the span is
     * created. 8-byte value encoded as a hex string.
     *
     * @var string
     */
    private $spanId;

    /**
     * The `spanId` of this span's parent span. If this is a root span, then
     * this field must be empty. 8-byte value encoded as a hex string.
     *
     * @var string
     */
    private $parentSpanId;

    /**
     * A description of the span's operation.
     *
     * For example, the name can be a qualified method name or a file name
     * and a line number where the operation is called. A best practice is to
     * use the same display name within an application and at the same call
     * point. This makes it easier to correlate spans in different traces.
     *
     * @var string
     */
    private $name;

    /**
     * The start time of the span. On the client side, this is the time kept by
     * the local machine where the span execution starts. On the server side,
     * this is the time when the server's application handler starts running.
     *
     * @var \DateTimeInterface
     */
    private $startTime;

    /**
     * The end time of the span. On the client side, this is the time kept by
     * the local machine where the span execution ends. On the server side, this
     * is the time when the server application handler stops running.
     *
     * @var \DateTimeInterface
     */
    private $endTime;

    /**
     * Stack trace captured at the start of the span. This is in the format of
     * `debug_backtrace`.
     *
     * @var array
     */
    private $stackTrace = [];

    /**
     * A collection of `TimeEvent`s. A `TimeEvent` is a time-stamped annotation
     * on the span, consisting of either user-supplied key:value pairs, or
     * details of a message sent/received between Spans
     *
     * @var TimeEvent[]
     */
    private $timeEvents = [];

    /**
     * A collection of links, which are references from this span to a span
     * in the same or different trace.
     *
     * @var Link[]
     */
    private $links = [];

    /**
     * An optional final status for this span.
     *
     * @var Status
     */
    private $status;

    /**
     * A highly recommended but not required flag that identifies when a trace
     * crosses a process boundary. True when the parentSpanId belongs to the
     * same process as the current span.
     *
     * @var bool
     */
    private $sameProcessAsParentSpan;

    /**
     * Distinguishes between spans generated in a particular context. For
     * example, two spans with the same name may be distinguished using `CLIENT`
     * and `SERVER` to identify queueing latency associated with the span.
     *
     * @var string
     */
    private $kind;

    /**
     * Event handler for span updates.
     *
     * @var SpanEventHandlerInterface
     */
    private $eventHandler;

    /**
     * Whether or not this span has been attached.
     *
     * @var bool
     */
    private $attached = false;

    /**
     * Instantiate a new Span instance.
     *
     * @param array $options [optional] Configuration options.
     *
     *      @type string $spanId The ID of the span. If not provided,
     *            one will be generated automatically for you.
     *      @type string $name The name of the span.
     *      @type \DateTimeInterface|int|float $startTime Start time of the span in nanoseconds.
     *            If provided as an int or float, it is treated as a Unix timestamp.
     *      @type \DateTimeInterface|int|float $endTime End time of the span in nanoseconds.
     *            If provided as an int or float, it is treated as a Unix timestamp.
     *      @type string $parentSpanId ID of the parent span if any.
     *      @type array $attributes Associative array of $attribute => $value
     *            to attach to this span.
     *      @type Status $status The final status for this span.
     *      @type bool $sameProcessAsParentSpan True when the parentSpanId
     *            belongs to the same process as the current span.
     *      @type string $kind The span's type.
     */
    public function __construct($options = [])
    {
        $options += [
            'traceId' => null,
            'attributes' => [],
            'timeEvents' => [],
            'links' => [],
            'parentSpanId' => null,
            'status' => null,
            'sameProcessAsParentSpan' => true,
            'kind' => self::KIND_UNSPECIFIED,
            'eventHandler' => null
        ];

        $this->traceId = $options['traceId'] ?? IdGenerator::hex(8);
        $this->eventHandler = $options['eventHandler'] ?: new NullEventHandler();

        if (array_key_exists('startTime', $options)) {
            $this->setStartTime($options['startTime']);
        }

        if (array_key_exists('endTime', $options)) {
            $this->setEndTime($options['endTime']);
        }

        $this->addAttributes($options['attributes']);
        $this->addTimeEvents($options['timeEvents']);
        $this->addLinks($options['links']);

        if (array_key_exists('spanId', $options)) {
            $this->spanId = $options['spanId'];
        } else {
            $this->spanId = IdGenerator::hex(8);
        }

        if (array_key_exists('stackTrace', $options)) {
            $this->stackTrace = $this->filterStackTrace($options['stackTrace']);
        } else {
            $this->stackTrace = $this->filterStackTrace(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
        }

        if (array_key_exists('name', $options)) {
            $this->name = $options['name'];
        } else {
            $this->name = $this->generateSpanName();
        }

        $this->parentSpanId = $options['parentSpanId'];
        $this->status = $options['status'];
        $this->sameProcessAsParentSpan = $options['sameProcessAsParentSpan'];
        $this->kind = $options['kind'];
    }

    /**
     * Set the start time for this span.
     *
     * @param  \DateTimeInterface|int|float $when [optional] The start time of
     *         this span. **Defaults to** now. If provided as an int or float,
     *         it is expected to be a Unix timestamp.
     */
    public function setStartTime($when = null)
    {
        $this->startTime = $this->formatDate($when);
    }

    /**
     * Set the end time for this span.
     *
     * @param  \DateTimeInterface|int|float $when [optional] The end time of
     *         this span. **Defaults to** now. If provided as an int or float,
     *         it is expected to be a Unix timestamp.
     */
    public function setEndTime($when = null)
    {
        $this->endTime = $this->formatDate($when);
    }

    /**
     * Retrieve the ID of this span.
     *
     * @return string
     */
    public function spanId(): string
    {
        return $this->spanId;
    }

    /**
     * Return a read-only version of this span.
     *
     * @return SpanData
     */
    public function spanData(): SpanData
    {
        return new SpanData(
            $this->name,
            $this->traceId,
            $this->spanId,
            $this->startTime,
            $this->endTime,
            [
                'parentSpanId' => $this->parentSpanId,
                'attributes' => $this->attributes,
                'timeEvents' => $this->timeEvents,
                'links' => $this->links,
                'status' => $this->status,
                'sameProcessAsParentSpan' => $this->sameProcessAsParentSpan,
                'stackTrace' => $this->stackTrace,
                'kind' => $this->kind
            ]
        );
    }

    /**
     * Mark this span as attached.
     */
    public function attach()
    {
        $this->attached = true;
    }

    /**
     * Returns whether or not this span has been attached.
     *
     * @return bool
     */
    public function attached(): bool
    {
        return $this->attached;
    }

    /**
     * Add an attribute to this span.
     *
     * @param string $name The name of the attribute to add
     * @param string $value The attribute value
     */
    public function addAttribute(string $name, string $value)
    {
        $this->attributes[$name] = $value;
        $this->eventHandler->attributeAdded($this, $name, $value);
    }

    /**
     * Add time events to this span.
     *
     * @param TimeEvent[] $timeEvents
     */
    public function addTimeEvents(array $timeEvents)
    {
        foreach ($timeEvents as $timeEvent) {
            $this->addTimeEvent($timeEvent);
        }
    }

    /**
     * Add a time event to this span.
     *
     * @param TimeEvent $timeEvent
     */
    public function addTimeEvent(TimeEvent $timeEvent)
    {
        $this->timeEvents[] = $timeEvent;
        $this->eventHandler->timeEventAdded($this, $timeEvent);
    }

    /**
     * Add links to this span.
     *
     * @param Link[] $links
     */
    public function addLinks(array $links)
    {
        foreach ($links as $link) {
            $this->addLink($link);
        }
    }

    /**
     * Add a link to this span.
     *
     * @param Link $link
     */
    public function addLink(Link $link)
    {
        $this->links[] = $link;
        $this->eventHandler->linkAdded($this, $link);
    }

    /**
     * Set the status for this span.
     *
     * @param int $code The status code
     * @param string $message A developer-facing error message
     */
    public function setStatus($code, $message)
    {
        $this->status = new Status($code, $message);
    }

    /**
     * Return a filtered stackTrace where we strip out all functions from the OpenCensus\Trace namespace
     *
     * @param array $stackTrace
     * @return array
     */
    private function filterStackTrace(array $stackTrace): array
    {
        return array_values(
            array_filter($stackTrace, static function ($st) {
                return !array_key_exists('class', $st) || strpos($st['class'], 'OpenCensus\Trace') !== 0;
            })
        );
    }

    /**
     * Generate a name for this span. Attempts to generate a name
     * based on the caller's code.
     *
     * @return string
     */
    private function generateSpanName(): string
    {
        // Try to find the first stacktrace class entry that doesn't start with OpenCensus\Trace
        foreach ($this->stackTrace as $st) {
            $st += ['line' => null];
            if (!array_key_exists('class', $st)) {
                return implode('/', array_filter(['app', basename($st['file']), $st['function'], $st['line']]));
            }

            if (strpos($st['class'], 'OpenCensus\Trace') !== 0) {
                return implode('/', array_filter(['app', $st['class'], $st['function'], $st['line']]));
            }
        }

        // We couldn't find a suitable stackTrace entry - generate a random one
        return uniqid('span', true);
    }
}
