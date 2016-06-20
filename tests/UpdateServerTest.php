<?php

use Suth\UpdateServer\UpdateServer;

/**
 * @coversDefaultClass Suth\UpdateServer\UpdateServer
 */
class UpdateServerTest extends TestCase
{
    /**
     * @test
     * @covers ::__construct
     */
    public function it_can_be_constructed()
    {
        $update = new UpdateServer('http://localhost');

        $this->assertInstanceOf(UpdateServer::class, $update);
    }
}
