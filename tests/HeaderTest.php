<?php

use Suth\UpdateServer\Headers;

/**
 * @coversDefaultClass Suth\UpdateServer\Headers
 */
class HeadersTest extends TestCase
{
    private function getSampleHeaders()
    {
        return [
            'CONTENT_TYPE' => 'some content type',
            'CONTENT_LENGTH' => 'some content length',
            'PHP_AUTH_USER' => 'some auth user',
            'PHP_AUTH_PW' => 'some auth pw',
            'PHP_AUTH_DIGEST' => 'some auth digest',
            'AUTH_TYPE' => 'some auth type',
            'HTTP_USER_AGENT' => 'some user agent',
            'HTTP_HOST' => 'some host',
            'X_LAST_THING' => 'some last thing'
        ];
    }
    /**
     * @test
     * @covers ::__construct
     */
    public function it_can_be_constructed()
    {
        $headers = new Headers([
            'HELLO_WORLD' => 'Hello World!'
        ]);

        $this->assertInstanceOf(Headers::class, $headers);
    }

    /**
     * @test
     * @covers ::parse
     */
    public function it_can_parse_the_server_variable()
    {
        $headers = Headers::parse($this->getSampleHeaders());

        $expected = [
            'CONTENT_TYPE' => 'some content type',
            'CONTENT_LENGTH' => 'some content length',
            'PHP_AUTH_USER' => 'some auth user',
            'PHP_AUTH_PW' => 'some auth pw',
            'PHP_AUTH_DIGEST' => 'some auth digest',
            'AUTH_TYPE' => 'some auth type',
            'USER_AGENT' => 'some user agent',
            'HOST' => 'some host',
            'X_LAST_THING' => 'some last thing'
        ];

        $this->assertEquals($expected, $headers);
    }

    /**
     * @test
     * @covers ::normalizeName
     */
    public function it_can_normalize_a_header_name()
    {
        $obj = new Headers();

        $this->assertEquals(
            'Title-Case-With-Dashes',
            $this->invokePrivateMethod(
                $obj,
                'normalizeName',
                array('TITLE_CASE_WITH_DASHES')
            )
        );
    }

    /**
     * @test
     * @covers ::isHeaderName
     */
    public function it_tests_if_header_name()
    {
        foreach ($this->getSampleHeaders() as $key => $value) {
            $this->assertTrue(
                $this->invokePrivateMethod(Headers::class, 'isHeaderName', [$key])
            );
        }

        $this->assertFalse(
            $this->invokePrivateMethod(Headers::class, 'isHeaderName', ['NOT_A_HEADER_NAME'])
        );
    }
}
