<?php

namespace Tests\Feature;

use App\Jobs\ResponderChat;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChatColaTest extends TestCase
{
    public function test_las_respuestas_del_chat_van_a_su_propia_cola(): void
    {
        Queue::fake();

        ResponderChat::dispatch(1, 2);

        Queue::assertPushedOn(ResponderChat::COLA, ResponderChat::class);
        $this->assertSame('chat', ResponderChat::COLA);
    }
}
