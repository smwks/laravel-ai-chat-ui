<?php

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Smwks\LaravelAiKit\Testbench\EchoAgent;

class EchoAgentTestUser extends Authenticatable
{
    protected $table = 'users';

    protected $fillable = ['name'];

    public $timestamps = false;
}

it('is a conversational laravel/ai agent that persists its exchange', function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });
    }

    $user = EchoAgentTestUser::create(['name' => 'Ada']);

    EchoAgent::fake(['Echo: hello there']);

    $response = (new EchoAgent)->forUser($user)->prompt('hello there');

    expect($response->text)->toBe('Echo: hello there');
    expect($response->conversationId)->not->toBeNull();

    EchoAgent::assertPrompted('hello there');
});
