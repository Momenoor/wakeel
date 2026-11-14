<?php

namespace App\Support;

use App\Models\ErrorReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every error the system reports (bootstrap/app.php) kept as an ErrorReport, with a code: the user is
 * shown the code — on the error page, or in a notice when it happened behind the page — and the
 * administrator finds the whole of it under Settings → Error tracking by that code.
 *
 * What Laravel does not report is not kept either: a page not found, a refused permission, a form
 * that did not validate, a session that ran out. Recording never throws, and never stops the error
 * being logged as before.
 */
final class ErrorTracker
{
    /** The same error (same fingerprint) kept this many times a minute at the most: a loop is not a thousand rows. */
    private const A_MINUTE = 5;

    /** Characters kept of the input and of the trace. */
    private const INPUT_LIMIT = 20000;

    private const TRACE_LIMIT = 60000;

    /** No 0/O or 1/I: read over the phone without mistakes. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Field names whose values are never kept. */
    private const SECRET = '/pass|secret|token|authorization|api[_-]?key|credential|cookie/i';

    /** The code of the error recorded in this request, for its answer. */
    private static ?string $code = null;

    private static bool $recording = false;

    public static function record(Throwable $e): void
    {
        // An error while recording an error is not recorded.
        if (self::$recording) {
            return;
        }

        self::$recording = true;

        try {
            $fingerprint = sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());

            if (RateLimiter::tooManyAttempts('error-report:'.$fingerprint, self::A_MINUTE)) {
                return;
            }
            RateLimiter::hit('error-report:'.$fingerprint, 60);

            $report = ErrorReport::create([
                'code' => self::newCode(),
                'fingerprint' => $fingerprint,
                'status' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500,
                'exception' => Str::limit($e::class, 250, ''),
                'message' => Str::limit($e->getMessage(), 5000),
                'file' => Str::limit(self::relative($e->getFile()), 490, ''),
                'line' => $e->getLine(),
                'trace' => Str::limit(self::relative($e->getTraceAsString()), self::TRACE_LIMIT),
                'previous' => self::previous($e),
                'app_version' => Str::limit(AppUpdate::currentVersion(), 30, ''),
                // A command, a worker, the scheduler — unless it is answering a request (as the tests do).
                ...(app()->runningInConsole() && request()->route() === null ? self::console() : self::http(request())),
            ]);

            self::$code = $report->code;
        } catch (Throwable) {
            // The database gone, the table not there yet: the log still has it.
        } finally {
            self::$recording = false;
        }
    }

    /** The code to show for this request's error — once: the next request's is its own. */
    public static function takeCode(): ?string
    {
        [$code, self::$code] = [self::$code, null];

        return $code;
    }

    /** The code without taking it (the error page reads it; the header takes it). */
    public static function code(): ?string
    {
        return self::$code;
    }

    private static function newCode(): string
    {
        $pick = fn (): string => 'E-'.collect(range(1, 6))->map(fn (): string => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        $code = $pick();

        return ErrorReport::query()->where('code', $code)->exists() ? $pick() : $code;
    }

    /**
     * @return array<string, mixed>
     */
    private static function http(Request $request): array
    {
        $livewire = $request->hasHeader('X-Livewire');

        return [
            'source' => $livewire ? 'livewire' : 'web',
            'method' => $request->method(),
            // For a Livewire call: the page it was made from.
            'url' => Str::limit((string) ($livewire ? $request->headers->get('referer', $request->fullUrl()) : $request->fullUrl()), 2000, ''),
            'route' => Str::limit((string) $request->route()?->getName(), 250, '') ?: null,
            'action' => $livewire ? self::livewireAction($request) : null,
            'user_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
            'input' => self::input($livewire ? self::livewireInput($request) : $request->except(['_token'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function console(): array
    {
        $command = implode(' ', array_slice((array) ($_SERVER['argv'] ?? []), 1));

        return [
            'source' => match (true) {
                str_contains($command, 'queue:') => 'queue',
                str_contains($command, 'schedule:') => 'scheduler',
                default => 'console',
            },
            'action' => Str::limit($command, 2000) ?: null,
        ];
    }

    /** "matters.list-matters@mountAction, callMountedAction + chat-widget@checkForNewMessages". */
    private static function livewireAction(Request $request): ?string
    {
        $parts = collect((array) $request->input('components'))->map(function ($component): string {
            $name = json_decode((string) ($component['snapshot'] ?? ''), true)['memo']['name'] ?? 'livewire';
            $calls = collect((array) ($component['calls'] ?? []))->pluck('method')->filter()->unique()->implode(', ');

            return $name.'@'.($calls ?: 'update');
        });

        return Str::limit($parts->implode(' + '), 2000) ?: null;
    }

    /**
     * What was called and changed — not the components' whole state, which is most of a Livewire request.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function livewireInput(Request $request): array
    {
        return collect((array) $request->input('components'))->map(fn ($component): array => [
            'calls' => collect((array) ($component['calls'] ?? []))->map(fn ($call): array => ['method' => $call['method'] ?? null, 'params' => $call['params'] ?? []])->all(),
            'updates' => (array) ($component['updates'] ?? []),
        ])->all();
    }

    /**
     * The input with secrets taken out, and not kept whole when it is very large.
     *
     * @param  array<mixed>  $input
     * @return array<mixed>|null
     */
    private static function input(array $input): ?array
    {
        if ($input === []) {
            return null;
        }

        $clean = self::redact($input);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json !== false && strlen($json) > self::INPUT_LIMIT
            ? ['truncated' => mb_strcut($json, 0, self::INPUT_LIMIT)]
            : $clean;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key) === 1) {
                $values[$key] = '***';
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            } elseif (is_object($value)) {
                // An uploaded file, say: its kind, not its content.
                $values[$key] = '['.$value::class.']';
            }
        }

        return $values;
    }

    /** The errors it wraps, each on a line: "Class: message (file:line)". */
    private static function previous(Throwable $e): ?string
    {
        $lines = [];

        for ($each = $e->getPrevious(); $each !== null && count($lines) < 10; $each = $each->getPrevious()) {
            $lines[] = $each::class.': '.Str::limit($each->getMessage(), 1000).' ('.self::relative($each->getFile()).':'.$each->getLine().')';
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** Paths from the project's folder: shorter, and the server's own layout left out. */
    private static function relative(string $text): string
    {
        return str_replace([base_path().DIRECTORY_SEPARATOR, base_path().'/'], '', $text);
    }
}
