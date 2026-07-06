<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleased;
use Illuminate\Queue\Events\JobExpired;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use WebReinvent\VaahSignoz\Meter\MeterFactory;
use WebReinvent\VaahSignoz\Helpers\InstrumentationHelper;

class QueueInstrumentation
{
    /**
     * Active job spans keyed by job id
     */
    protected static $activeSpans = [];

    public function boot()
    {
        if (!config('vaahsignoz.instrumentations.queue', false)) {
            return;
        }

        Event::listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        Event::listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        Event::listen(JobFailed::class, [$this, 'handleJobFailed']);
        Event::listen(JobQueued::class, [$this, 'handleJobQueued']);
        Event::listen(JobReleased::class, [$this, 'handleJobReleased']);
        Event::listen(JobExpired::class, [$this, 'handleJobExpired']);
    }

    public function handleJobProcessing(JobProcessing $event)
    {
        $job = $event->job;
        $jobId = $job->getJobId() ?? 'unknown';
        $queue = $job->getQueue() ?? 'default';
        $jobName = method_exists($job, 'getName')
            ? ($job->getName() ?? 'unknown')
            : class_basename(get_class($job));

        $span = TracerFactory::createSpan('queue.job', [
            'queue.name' => $queue,
            'queue.job.name' => $jobName,
            'queue.job.id' => (string) $jobId,
            'queue.worker' => $event->connectionName ?? 'unknown',
        ]);

        self::$activeSpans[$jobId] = [
            'span' => $span,
            'queue' => $queue,
            'job' => $jobName,
        ];
    }

    public function handleJobProcessed(JobProcessed $event)
    {
        $jobId = $event->job->getJobId() ?? 'unknown';

        if (isset(self::$activeSpans[$jobId])) {
            $data = self::$activeSpans[$jobId];
            InstrumentationHelper::setSpanStatus($data['span'], 'ok');
            $data['span']->setAttribute('queue.job.status', 'processed');
            $data['span']->end();
            unset(self::$activeSpans[$jobId]);

            // Metric
            MeterFactory::counter('queue.jobs.processed')->add(1, [
                'queue' => $data['queue'],
                'job' => $data['job'],
            ]);
        }
    }

    public function handleJobFailed(JobFailed $event)
    {
        $jobId = $event->job->getJobId() ?? 'unknown';
        $exception = $event->exception;

        if (isset(self::$activeSpans[$jobId])) {
            $data = self::$activeSpans[$jobId];
            InstrumentationHelper::setSpanStatus($data['span'], 'error', $exception->getMessage());
            $data['span']->setAttribute('queue.job.status', 'failed');
            $data['span']->setAttribute('exception.type', get_class($exception));
            $data['span']->setAttribute('exception.message', $exception->getMessage());
            $data['span']->end();
            unset(self::$activeSpans[$jobId]);
        }

        // Metric
        MeterFactory::counter('queue.jobs.failed')->add(1, [
            'queue' => $event->job->getQueue() ?? 'default',
            'exception' => get_class($exception),
        ]);
    }

    public function handleJobQueued(JobQueued $event)
    {
        // $event->job is the actual job instance (not a queue job wrapper),
        // so we get the class name instead of calling getName().
        $jobName = method_exists($event->job, 'getName')
            ? ($event->job->getName() ?? 'unknown')
            : class_basename(get_class($event->job));

        MeterFactory::counter('queue.jobs.enqueued')->add(1, [
            'queue' => $event->connectionName ?? 'default',
            'job' => $jobName,
        ]);
    }

    public function handleJobReleased(JobReleased $event)
    {
        MeterFactory::counter('queue.jobs.released')->add(1, [
            'queue' => $event->job->getQueue() ?? 'default',
        ]);
    }

    public function handleJobExpired(JobExpired $event)
    {
        MeterFactory::counter('queue.jobs.expired')->add(1, [
            'queue' => $event->job->getQueue() ?? 'default',
        ]);
    }
}
