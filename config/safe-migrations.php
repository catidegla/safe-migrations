<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where the migrations are
    |--------------------------------------------------------------------------
    */

    'path' => 'database/migrations',

    /*
    |--------------------------------------------------------------------------
    | Which server you actually run
    |--------------------------------------------------------------------------
    |
    | Half the rules here turn on this. Adding a column with a default rewrote
    | the table on PostgreSQL 10 and has been instant since 11; most ALTER
    | work copied the table on MySQL 5.6 and has been in place since 5.7.
    |
    | Left null, the driver comes from your default connection and the version
    | is asked of the server when one is reachable. Where it is not, the rules
    | assume the oldest behaviour, which is the direction that stays loud
    | rather than the one that stays quiet about a real problem. In CI there is
    | usually no database, so set it here.
    |
    */

    'database' => null,
    'version' => null,

    /*
    |--------------------------------------------------------------------------
    | What you set before the DDL runs
    |--------------------------------------------------------------------------
    |
    | An instant migration still has to acquire an exclusive lock, and that
    | request waits behind whatever is already reading the table while every
    | statement arriving after it waits behind the request. One long select
    | and the table stops serving, for the length of the select rather than
    | the length of the migration.
    |
    | Setting a timeout turns that into a failed migration you run again,
    | which is the trade worth making. Put what you set here and the lock-queue
    | rule stops asking: '3s' on Postgres, 3 on MySQL, 3000 on SQL Server.
    | Left null it assumes nothing is set, because that is the default on
    | every one of them.
    |
    */

    'lock_timeout' => null,

    /*
    |--------------------------------------------------------------------------
    | Rules this project has decided not to run
    |--------------------------------------------------------------------------
    |
    | Turning one off globally is a real decision and is sometimes right: a
    | team with no rolling deploys does not need drop-column. Prefer the
    | per-line comment where it is one migration rather than a policy.
    |
    |   // safe-migrations-ignore: drop-column
    |
    */

    'disabled' => [],

];
