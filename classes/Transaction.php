<?php
declare(strict_types=1);



final class Transaction
{
    public static function run(PDO $conn, callable $work): mixed
    {
        $conn->beginTransaction();
        try {
            $result = $work();
            $conn->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $exception;
        }
    }

    
    public static function savepoint(PDO $conn, string $name, callable $work): void
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?: 'sp';
        $conn->exec("SAVEPOINT {$name}");
        try {
            $work();
            $conn->exec("RELEASE SAVEPOINT {$name}");
        } catch (Throwable $exception) {
            $conn->exec("ROLLBACK TO SAVEPOINT {$name}");
            $conn->exec("RELEASE SAVEPOINT {$name}");
            throw $exception;
        }
    }
}
?>
