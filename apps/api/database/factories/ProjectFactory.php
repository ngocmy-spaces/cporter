<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $words = fake()->unique()->words(2, true);
        $slug = Str::slug($words);

        return [
            'name' => Str::title($words),
            'slug' => $slug,
            'base_path' => "/home/user/{$slug}.domain",
            'type' => ProjectType::StaticSite,
            'docroot_subpath' => null,
            'php_binary' => null,
            'keep_releases' => 5,
            'health_check_url' => "https://{$slug}.domain/",
            'shared_paths' => [],
            'hooks' => [],
            'status' => ProjectStatus::Active,
        ];
    }

    /** A Laravel-type project (needs shared paths + hooks via cron-worker). */
    public function laravel(): static
    {
        return $this->state(fn () => [
            'type' => ProjectType::Laravel,
            'docroot_subpath' => 'public',
            'php_binary' => '/opt/cpanel/ea-php83/root/usr/bin/php',
            'shared_paths' => ['.env', 'storage'],
            'hooks' => ['pre_activate' => ['artisan migrate --force', 'artisan config:cache']],
        ]);
    }
}
