<?php

namespace Omisteck\Peek;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Omisteck\Peek\Payloads\EnvironmentPayload;
use Omisteck\Peek\Payloads\ExceptionPayload;
use Omisteck\Peek\Payloads\ExecutedQueryPayload;
use Omisteck\Peek\Payloads\LoggedMailPayload;
use Omisteck\Peek\Payloads\MailablePayload;
use Omisteck\Peek\Payloads\MarkdownPayload;
use Omisteck\Peek\Payloads\ModelPayload;
use Omisteck\Peek\Payloads\ResponsePayload;
use Omisteck\Peek\Payloads\ViewPayload;
use Omisteck\Peek\Settings\Settings;
use Omisteck\Peek\Watchers\CacheWatcher;
use Omisteck\Peek\Watchers\DuplicateQueryWatcher;
use Omisteck\Peek\Watchers\EventWatcher;
use Omisteck\Peek\Watchers\ExceptionWatcher;
use Omisteck\Peek\Watchers\HttpClientWatcher;
use Omisteck\Peek\Watchers\JobWatcher;
use Omisteck\Peek\Watchers\QueryWatcher;
use Omisteck\Peek\Watchers\RequestWatcher;
use Omisteck\Peek\Watchers\SlowQueryWatcher;
use Omisteck\Peek\Watchers\ViewWatcher;
use Omisteck\Peek\Watchers\Watcher;
use ReflectionFunction;
use Throwable;

class Peek extends BasePeek
{
    public function __construct(Settings $settings, ?Client $client = null, ?string $uuid = null)
    {
        // persist the enabled setting across multiple instantiations
        $enabled = static::$enabled;

        parent::__construct($settings, $client, $uuid);

        static::$enabled = $enabled;
    }

    public function loggedMail(string $loggedMail): self
    {
        $payload = LoggedMailPayload::forLoggedMail($loggedMail);

        $this->sendRequest($payload);

        return $this;
    }

    public function mailable(Mailable ...$mailables): self
    {
        $shouldRestoreFake = false;

        if (get_class(app(MailManager::class)) === MailFake::class) {
            $shouldRestoreFake = true;

            Mail::swap(new MailManager(app()));
        }

        if ($shouldRestoreFake) {
            Mail::fake();
        }

        $payloads = array_map(function (Mailable $mailable) {
            return MailablePayload::forMailable($mailable);
        }, $mailables);

        $this->sendRequest($payloads);

        return $this;
    }

    /**
     * @param  array|string  ...$keys
     * @return $this
     */
    public function context(...$keys): self
    {
        if (! class_exists(Context::class)) {
            return $this;
        }

        if (isset($keys[0]) && is_array($keys[0])) {
            $keys = $keys[0];
        }

        $context = count($keys)
            ? Context::only($keys)
            : Context::all();

        $this
            ->send($context)
            ->label('Context');

        return $this;
    }

    /**
     * @param  array|string  ...$keys
     * @return $this
     */
    public function hiddenContext(...$keys): self
    {
        if (! class_exists(Context::class)) {
            return $this;
        }

        if (isset($keys[0]) && is_array($keys[0])) {
            $keys = $keys[0];
        }

        $hiddenContext = count($keys)
            ? Context::onlyHidden($keys)
            : Context::allHidden();

        $this
            ->send($hiddenContext)
            ->label('Hidden Context');

        return $this;
    }

    /**
     * @param  Model|iterable  ...$model
     */
    public function model(...$model): self
    {
        $models = [];
        foreach ($model as $passedModel) {
            if (is_null($passedModel)) {
                $models[] = null;

                continue;
            }
            if ($passedModel instanceof Model) {
                $models[] = $passedModel;

                continue;
            }

            if (is_iterable($model)) {
                foreach ($passedModel as $item) {
                    $models[] = $item;

                    continue;
                }
            }
        }

        $payloads = array_map(function (?Model $model) {
            return new ModelPayload($model);
        }, $models);

        foreach ($payloads as $payload) {
            peek()->sendRequest($payload);
        }

        return $this;
    }

    /**
     * @param  Model|iterable  $models
     */
    public function models($models): self
    {
        return $this->model($models);
    }

    // public function markdown(string $markdown): self
    // {
    //     $payload = new MarkdownPayload($markdown);

    //     $this->sendRequest($payload);

    //     return $this;
    // }

    /**
     * @param  string[]|array|null  $onlyShowNames
     */
    public function env(?array $onlyShowNames = null, ?string $filename = null): self
    {
        $filename ??= app()->environmentFilePath();

        $payload = new EnvironmentPayload($onlyShowNames, $filename);

        $this->sendRequest($payload);

        return $this;
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\Peek
     */
    public function showEvents($callable = null)
    {
        $watcher = app(EventWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function events($callable = null)
    {
        return $this->showEvents($callable);
    }

    public function stopShowingEvents(): self
    {
        /** @var \Omisteck\Peek\Watchers\EventWatcher $eventWatcher */
        $eventWatcher = app(EventWatcher::class);

        $eventWatcher->disable();

        return $this;
    }

    public function showExceptions(): self
    {
        /** @var \Omisteck\Peek\Watchers\ExceptionWatcher $exceptionWatcher */
        $exceptionWatcher = app(ExceptionWatcher::class);

        $exceptionWatcher->enable();

        return $this;
    }

    public function stopShowingExceptions(): self
    {
        /** @var \Omisteck\Peek\Watchers\ExceptionWatcher $exceptionWatcher */
        $exceptionWatcher = app(ExceptionWatcher::class);

        $exceptionWatcher->disable();

        return $this;
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\Peek
     */
    public function showJobs($callable = null)
    {
        $watcher = app(JobWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\Peek
     */
    public function showCache($callable = null)
    {
        $watcher = app(CacheWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function stopShowingCache(): self
    {
        app(CacheWatcher::class)->disable();

        return $this;
    }

    public function jobs($callable = null)
    {
        return $this->showJobs($callable);
    }

    public function stopShowingJobs(): self
    {
        app(JobWatcher::class)->disable();

        return $this;
    }

    public function view(View $view): self
    {
        $payload = new ViewPayload($view);

        return $this->sendRequest($payload);
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\BasePeek
     */
    public function showViews($callable = null)
    {
        $watcher = app(ViewWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function views($callable = null)
    {
        return $this->showViews($callable);
    }

    public function stopShowingViews(): self
    {
        app(ViewWatcher::class)->disable();

        return $this;
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\BasePeek
     */
    public function showQueries($callable = null)
    {
        $watcher = app(QueryWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function countQueries(callable $callable)
    {
        /** @var QueryWatcher $watcher */
        $watcher = app(QueryWatcher::class);

        $watcher->keepExecutedQueries();

        if (! $watcher->enabled()) {
            $watcher->doNotSendIndividualQueries();
        }

        $output = $this->handleWatcherCallable($watcher, $callable);

        $executedQueryStatistics = collect($watcher->getExecutedQueries())

            ->pipe(function (Collection $queries) {
                return [
                    'Count' => $queries->count(),
                    'Total time' => $queries->sum(function (QueryExecuted $query) {
                        return $query->time;
                    }),
                ];
            });

        $executedQueryStatistics['Total time'] .= ' ms';

        $watcher
            ->stopKeepingAndClearExecutedQueries()
            ->sendIndividualQueries();

        $this->table($executedQueryStatistics, 'Queries');

        return $output;
    }

    public function queries($callable = null)
    {
        return $this->showQueries($callable);
    }

    public function stopShowingQueries(): self
    {
        app(QueryWatcher::class)->disable();

        return $this;
    }

    public function slowQueries($milliseconds = 500, $callable = null)
    {
        return $this->showSlowQueries($milliseconds, $callable);
    }

    public function showSlowQueries($milliseconds = 500, $callable = null)
    {
        $watcher = app(SlowQueryWatcher::class)
            ->setMinimumTimeInMilliseconds($milliseconds);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function stopShowingSlowQueries(): self
    {
        app(SlowQueryWatcher::class)->disable();

        return $this;
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\BasePeek
     */
    public function showDuplicateQueries($callable = null)
    {
        $watcher = app(DuplicateQueryWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function stopShowingDuplicateQueries(): self
    {
        app(DuplicateQueryWatcher::class)->disable();

        return $this;
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\BasePeek
     */
    public function showRequests($callable = null)
    {
        $watcher = app(RequestWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function requests($callable = null)
    {
        return $this->showRequests($callable);
    }

    public function stopShowingRequests(): self
    {
        $this->requestWatcher()->disable();

        return $this;
    }

    /**
     * @param  null  $callable
     * @return \Omisteck\Peek\BasePeek
     */
    public function showHttpClientRequests($callable = null)
    {
        if (! HttpClientWatcher::supportedByLaravelVersion()) {
            $this->send('Http logging is not available in your Laravel version')->error();

            return $this;
        }

        $watcher = app(HttpClientWatcher::class);

        return $this->handleWatcherCallable($watcher, $callable);
    }

    public function httpClientRequests($callable = null)
    {
        return $this->showHttpClientRequests($callable);
    }

    public function stopShowingHttpClientRequests(): self
    {
        app(HttpClientWatcher::class)->disable();

        return $this;
    }

    protected function handleWatcherCallable(Watcher $watcher, ?Closure $callable = null)
    {
        $peekProxy = new PeekProxy;

        $wasEnabled = $watcher->enabled();

        $watcher->enable();

        if ($peekProxy) {
            $watcher->setPeekProxy($peekProxy);
        }

        if ($callable) {
            $output = $callable();

            if (! $wasEnabled) {
                $watcher->disable();
            }

            if ((new ReflectionFunction($callable))->hasReturnType()) {
                return $output;
            }
        }

        return $peekProxy;
    }

    public function testResponse(TestResponse $testResponse)
    {
        $payload = ResponsePayload::fromTestResponse($testResponse);

        $this->sendRequest($payload);
    }

    protected function requestWatcher(): RequestWatcher
    {
        return app(RequestWatcher::class);
    }

    public function exception(Throwable $exception, array $meta = [])
    {
        $payloads[] = new ExceptionPayload($exception, $meta);

        if ($exception instanceof QueryException) {
            $executedQuery = new QueryExecuted($exception->getSql(), $exception->getBindings(), null, DB::connection(config('database.default')));

            $payloads[] = new ExecutedQueryPayload($executedQuery);
        }

        $this->sendRequest($payloads)->error();

        return $this;
    }

    /**
     * @param  \Omisteck\Peek\Payloads\Payload|\Omisteck\Peek\Payloads\Payload[]  $payloads
     *
     * @throws \Exception
     */
    public function sendRequest($payloads, array $meta = []): BasePeek
    {
        if (! $this->enabled()) {
            return $this;
        }

        $meta['laravel_version'] = app()->version();

        if (class_exists(InstalledVersions::class)) {
            try {
                $meta['laravel_peek_package_version'] = InstalledVersions::getVersion('omisteck/peek');
            } catch (\Exception $e) {
                $meta['laravel_peek_package_version'] = '0.0.0';
            }
        }

        return BasePeek::sendRequest($payloads, $meta);
    }
}
