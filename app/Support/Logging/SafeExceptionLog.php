<?php

namespace App\Support\Logging;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Stage 2 A1: the exception text that is safe to log.
 *
 * WHY THIS EXISTS
 *
 * Laravel's `QueryException::getMessage()` is the framework's own summary of a failed statement, and it
 * embeds BOTH the full SQL and every bound value:
 *
 *     SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'email' cannot be null
 *     (Connection: mysql, SQL: insert into `users` (`first_name`, `email`, `password`, `address`)
 *     values (Dana, dana@example.test, $2y$10$...., Damascus))
 *
 * A single `Log::error('Registration failed', ['error' => $e->getMessage()])` therefore writes the
 * bcrypt password hash, the name, the email and the address into the log file - which is exactly what
 * the owner's test log showed for signup (Path B) and for ride creation. The same is true for every
 * catch block in the codebase whose body can be a query, and there are many: an INSERT, an UPDATE, a
 * `DB::transaction` wrapper, an Eloquent `->save()`.
 *
 * WHAT IS LOGGED INSTEAD
 *
 * The SQLSTATE and the DRIVER's own message - the text PDO produced before Laravel attached the SQL and
 * the bindings to it - which is what the owner asked for:
 *
 *     SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'email' cannot be null
 *
 * Not one bound value survives, and a duplicate-key message can still name the offending key. Non-query
 * exceptions are returned unchanged, so this is a no-op for every log line that never could have leaked:
 * that is what makes the sweep mechanical instead of a per-site judgement call about which catch blocks
 * "can" see a QueryException.
 *
 * NOT A SANITIZER. This is a formatter for log text. It never rewrites the exception, and nothing here
 * changes a response, a status code or a query.
 */
final class SafeExceptionLog
{
    /**
     * The message to log in place of `$e->getMessage()`.
     *
     * A `QueryException` becomes `SQLSTATE[xxxxx]: <driver message>`; everything else is unchanged, so a
     * caller that used to log a ValidationException's text still logs exactly that text.
     */
    public static function message(Throwable $e): string
    {
        if (! $e instanceof QueryException) {
            return $e->getMessage();
        }

        $driver = self::driverMessage($e);

        return $driver === ''
            ? sprintf('SQLSTATE[%s]: database error', self::sqlState($e))
            : sprintf('SQLSTATE[%s]: %s', self::sqlState($e), $driver);
    }

    /**
     * The SQLSTATE, preferring the driver's own errorInfo (the shape PDO documents) and falling back to
     * the previous exception's code.
     */
    public static function sqlState(Throwable $e): string
    {
        if ($e instanceof QueryException && is_array($e->errorInfo) && isset($e->errorInfo[0]) && $e->errorInfo[0] !== null) {
            return (string) $e->errorInfo[0];
        }

        $previous = $e->getPrevious();
        if ($previous instanceof PDOException && $previous->getCode() !== '') {
            return (string) $previous->getCode();
        }

        return 'HY000';
    }

    /**
     * The driver's message without Laravel's `SQLSTATE[...]:` prefix and - crucially - without the SQL
     * and the bindings Laravel appends. `errorInfo[2]` is the driver's text verbatim; the previous
     * PDOException carries the same text when errorInfo is not populated.
     */
    public static function driverMessage(Throwable $e): string
    {
        if ($e instanceof QueryException && is_array($e->errorInfo) && isset($e->errorInfo[2]) && $e->errorInfo[2] !== null && $e->errorInfo[2] !== '') {
            return (string) $e->errorInfo[2];
        }

        $previous = $e->getPrevious();
        if ($previous instanceof PDOException) {
            return (string) preg_replace('/^SQLSTATE\[[^\]]*\]:\s*/', '', $previous->getMessage());
        }

        return '';
    }

    /**
     * The context keys a query failure can carry without leaking: the driver's SQLSTATE, error code and
     * message. Used where a call site wants structured fields rather than one formatted string.
     *
     * @return array{sqlstate: string, driver_code: int|string|null, driver_message: string}
     */
    public static function context(Throwable $e): array
    {
        $code = null;
        if ($e instanceof QueryException && is_array($e->errorInfo) && array_key_exists(1, $e->errorInfo)) {
            $code = $e->errorInfo[1];
        }

        return [
            'sqlstate' => self::sqlState($e),
            'driver_code' => $code,
            'driver_message' => self::driverMessage($e),
        ];
    }
}
