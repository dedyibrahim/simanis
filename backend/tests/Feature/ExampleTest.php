<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_frontend()
    {
        $response = $this->get('/');

        $response->assertRedirect('http://192.168.0.11/');
    }
}
