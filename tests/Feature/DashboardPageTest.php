<?php

namespace Tests\Feature;

use Database\Seeders\DemoSeeder;
use Tests\TestCase;

class DashboardPageTest extends TestCase
{
    public function test_the_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_the_dashboard_page_renders_the_wireframe_panels(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSeeText('Current Cycle Usage')
            ->assertSeeText('Projected Overage Revenue')
            ->assertSeeText('Top 5 Customers by Usage')
            ->assertSeeText('Daily Usage Trend')
            ->assertSeeText('Churn Risk')
            ->assertSeeText('System status');
    }

    public function test_the_demo_key_shortcut_is_offered_only_in_local_environments(): void
    {
        $this->app['env'] = 'production';
        $this->get('/dashboard')->assertDontSee(DemoSeeder::DEMO_API_KEY);

        $this->app['env'] = 'local';
        $this->get('/dashboard')->assertSee(DemoSeeder::DEMO_API_KEY);
    }
}
