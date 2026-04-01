<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'siteTitle', 'value' => 'KEHSA'],
            ['key' => 'tagline', 'value' => 'Admin for SELAR & AINA'],
            ['key' => 'webfrontTitle', 'value' => 'CORRAD Laravel'],
            ['key' => 'webfrontTagline', 'value' => 'Design system and admin standards.'],
            ['key' => 'titleFormat', 'value' => '%page% | %site%'],
            ['key' => 'metaDescription', 'value' => 'Internal UI standard and admin toolkit.'],
            ['key' => 'siteIconUrl', 'value' => ''],
            ['key' => 'webfrontLogoUrl', 'value' => ''],
            ['key' => 'sidebarLogoUrl', 'value' => ''],
            ['key' => 'faviconUrl', 'value' => ''],
            ['key' => 'language', 'value' => 'en'],
            ['key' => 'timezone', 'value' => 'UTC'],
            ['key' => 'footerText', 'value' => ''],
            ['key' => 'frontPageId', 'value' => 'null'],
        ];

        foreach ($settings as $setting) {
            // Do not overwrite user-customized settings when seeding.
            Setting::firstOrCreate(['key' => $setting['key']], ['value' => $setting['value']]);
        }
    }
}
