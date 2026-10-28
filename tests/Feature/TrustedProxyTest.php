<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_redirects_keep_https_when_tls_ends_at_the_proxy(): void
    {
        $this->get('http://sprint.example.com/', ['X-Forwarded-Proto' => 'https'])
            ->assertRedirect('https://sprint.example.com/login');
    }
}
