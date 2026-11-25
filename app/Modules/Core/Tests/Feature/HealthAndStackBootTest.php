<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('boots the application health endpoint, PostgreSQL citext, UUIDv7, and Redis', function (): void {
    $this->get('/up')->assertOk();

    $citextExists = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'citext') AS enabled");
    expect($citextExists->enabled)->toBeTrue();

    $uuid = (string) Str::uuid7();
    expect(Str::isUuid($uuid))->toBeTrue();

    $pong = Redis::connection()->ping();
    expect((string) $pong)->toContain('PONG');
});
