<?php

namespace Database\Seeders;

use App\Models\AgentTemplate;
use App\Models\TeamPack;
use Illuminate\Database\Seeder;

class TeamPackSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->packs() as $index => $pack) {
            $templateSlugs = $pack['templates'];
            unset($pack['templates']);

            $teamPack = TeamPack::query()->updateOrCreate(
                ['slug' => $pack['slug']],
                array_merge($pack, ['sort_order' => $index]),
            );

            $templateIds = [];
            foreach ($templateSlugs as $sortOrder => $slug) {
                $template = AgentTemplate::query()->where('slug', $slug)->first();
                if ($template) {
                    $templateIds[$template->id] = ['sort_order' => $sortOrder];
                }
            }

            $teamPack->templates()->sync($templateIds);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function packs(): array
    {
        return [
            [
                'slug' => 'growth-team',
                'name' => 'Growth Team',
                'tagline' => 'Drive signups, close deals, and grow revenue',
                'description' => 'A complete growth squad — content marketing to attract leads, sales outreach to close them, and data analysis to optimize the funnel.',
                'emoji' => '🚀',
                'is_active' => true,
                'templates' => ['quill', 'hunter', 'lens'],
            ],
            [
                'slug' => 'customer-team',
                'name' => 'Customer Team',
                'tagline' => 'Delight customers from signup to renewal',
                'description' => 'A customer-facing team that handles support tickets, onboarding guidance, and proactive communication to keep churn low and satisfaction high.',
                'emoji' => '💛',
                'is_active' => true,
                'templates' => ['haven', 'quill', 'vigor'],
            ],
            [
                'slug' => 'back-office',
                'name' => 'Back Office',
                'tagline' => 'Keep the business running while you build',
                'description' => 'An operations crew handling project coordination, financial tracking, and the admin work that piles up when you are heads-down shipping product.',
                'emoji' => '⚙️',
                'is_active' => true,
                'templates' => ['atlas', 'ledger', 'vigor'],
            ],
            [
                'slug' => 'market-intel',
                'name' => 'Market Intelligence',
                'tagline' => 'Know your market before your competitors do',
                'description' => 'A research team that tracks competitor moves, analyzes market trends, and surfaces opportunities — so you make decisions with data, not gut feel.',
                'emoji' => '🔍',
                'is_active' => true,
                'templates' => ['lens', 'babel', 'echo'],
            ],
        ];
    }
}
