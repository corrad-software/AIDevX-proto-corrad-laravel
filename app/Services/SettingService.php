<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettingService
{
    /**
     * Default setting keys and their default values.
     */
    protected array $defaults = [
        'siteTitle' => '',
        'tagline' => '',
        'webfrontTitle' => '',
        'webfrontTagline' => '',
        'titleFormat' => '%page% | %site%',
        'metaDescription' => '',
        'siteIconUrl' => '',
        'webfrontLogoUrl' => '',
        'sidebarLogoUrl' => '',
        'faviconUrl' => '',
        'language' => 'en',
        'timezone' => 'UTC',
        'footerText' => '',
        'frontPageId' => null,
        'storefrontMenu' => null,
        'adminMenuPrefs' => null,
        'lookupSystem' => ['KERISI', 'iAGC', 'eGPA'],
        /** Reference labels for hierarchy (DB `users.user_level`: super_admin … user, secondary_user). */
        'lookupUserLevel' => [
            ['code' => '0', 'desc' => 'developer'],
            ['code' => '1', 'desc' => 'admin internal'],
            ['code' => '2', 'desc' => 'admin external'],
            ['code' => '3', 'desc' => 'agent'],
            ['code' => '4', 'desc' => 'user'],
            ['code' => '5', 'desc' => 'secondary user'],
        ],
        /** Reference for user category (e.g. tempatan / luar negara); optional use on forms. */
        'lookupUserCategory' => [
            ['code' => 'tempatan', 'desc' => 'user tempatan'],
            ['code' => 'luar_negara', 'desc' => 'luar negara'],
        ],
        /** User segment (code + description), e.g. government vs private; optional use on forms / reporting. */
        'lookupUserSegment' => [
            ['code' => '1', 'desc' => 'Government'],
            ['code' => '2', 'desc' => 'Private'],
        ],
        /** Jenis pengguna (kod + keterangan); contoh tempatan / luar negara. */
        'lookupUserJenisPengguna' => [
            ['code' => '1', 'desc' => 'Tempatan'],
            ['code' => '2', 'desc' => 'Luar negara'],
        ],
    ];

    /**
     * Legacy aliases that may exist in old databases.
     *
     * @var array<string, array<int, string>>
     */
    protected array $aliases = [
        'siteTitle' => ['siteTitle', 'site_title'],
        'tagline' => ['tagline'],
        'webfrontTitle' => ['webfrontTitle', 'webfront_title'],
        'webfrontTagline' => ['webfrontTagline', 'webfront_tagline'],
        'titleFormat' => ['titleFormat', 'title_format'],
        'metaDescription' => ['metaDescription', 'meta_description'],
        'siteIconUrl' => ['siteIconUrl', 'site_icon_url'],
        'webfrontLogoUrl' => ['webfrontLogoUrl', 'webfront_logo_url'],
        'sidebarLogoUrl' => ['sidebarLogoUrl', 'sidebar_logo_url'],
        'faviconUrl' => ['faviconUrl', 'favicon_url'],
        'language' => ['language'],
        'timezone' => ['timezone'],
        'footerText' => ['footerText', 'footer_text'],
        'frontPageId' => ['frontPageId', 'frontpageId', 'frontpage_id'],
        'storefrontMenu' => ['storefrontMenu', 'storefront_menu'],
        'adminMenuPrefs' => ['adminMenuPrefs', 'admin_menu_prefs'],
        'lookupSystem' => ['lookupSystem', 'lookup_system'],
        'lookupUserLevel' => ['lookupUserLevel', 'lookup_user_level'],
        'lookupUserCategory' => ['lookupUserCategory', 'lookup_user_category'],
        'lookupUserSegment' => ['lookupUserSegment', 'lookup_user_segment'],
        'lookupUserJenisPengguna' => ['lookupUserJenisPengguna', 'lookup_user_jenis_pengguna'],
    ];

    /**
     * Retrieve all settings as a key-value array, applying defaults for missing keys.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        $rows = DB::table('settings')->pluck('value', 'key')->toArray();

        $result = [];
        foreach ($this->defaults as $key => $default) {
            $result[$key] = $this->resolveValueByAlias($key, $rows, $default);
        }

        return $result;
    }

    /**
     * Update multiple settings at once, upserting each key within a transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(array $data): void
    {
        DB::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                $stringValue = $this->serializeValue($value);

                DB::table('settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $stringValue]
                );

                // Remove legacy alias keys to prevent shadowing
                $aliasList = $this->aliases[$key] ?? [];
                foreach ($aliasList as $alias) {
                    if ($alias !== $key) {
                        DB::table('settings')->where('key', $alias)->delete();
                    }
                }
            }
        });
    }

    /**
     * Retrieve a single setting value.
     *
     * @param  mixed|null  $default
     */
    public function get(string $key, $default = null): ?string
    {
        $keys = $this->aliases[$key] ?? [$key];
        foreach ($keys as $candidate) {
            $row = DB::table('settings')->where('key', $candidate)->first();
            if ($row) {
                return $row->value;
            }
        }

        return $default;
    }

    /**
     * Set a single setting value.
     */
    public function set(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Serialize a value for storage in the settings table.
     *
     * @param  mixed  $value
     */
    protected function serializeValue($value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    /**
     * Resolve a canonical setting key from DB rows and legacy aliases.
     *
     * @param  array<string, mixed>  $rows
     * @param  mixed  $default
     * @return mixed
     */
    protected function resolveValueByAlias(string $key, array $rows, $default)
    {
        $candidates = $this->aliases[$key] ?? [$key];

        foreach ($candidates as $candidate) {
            if (! array_key_exists($candidate, $rows)) {
                continue;
            }

            $value = $rows[$candidate];

            if ($key === 'frontPageId') {
                if ($value === null || $value === '' || $value === 'null') {
                    return null;
                }

                return (int) $value;
            }

            if ($value === 'null') {
                return null;
            }

            if ($key === 'lookupSystem' && is_string($value)) {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : $default;
            }

            if ($key === 'lookupUserLevel' && is_string($value)) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded)) {
                    return $default;
                }

                return $this->normalizeLookupUserLevelRows($decoded, $default);
            }

            if ($key === 'lookupUserCategory' && is_string($value)) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded)) {
                    return $default;
                }

                return $this->normalizeLookupUserLevelRows($decoded, $default);
            }

            if ($key === 'lookupUserSegment' && is_string($value)) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded)) {
                    return $default;
                }

                return $this->normalizeLookupUserLevelRows($decoded, $default);
            }

            if ($key === 'lookupUserJenisPengguna' && is_string($value)) {
                $decoded = json_decode($value, true);

                if (! is_array($decoded)) {
                    return $default;
                }

                return $this->normalizeLookupUserLevelRows($decoded, $default);
            }

            return $value;
        }

        return $default;
    }

    /**
     * Default user-level lookup rows (code + desc).
     *
     * @return array<int, array{code: string, desc: string}>
     */
    public function defaultLookupUserLevel(): array
    {
        return $this->defaults['lookupUserLevel'];
    }

    /**
     * @return array<int, array{code: string, desc: string}>
     */
    public function defaultLookupUserCategory(): array
    {
        return $this->defaults['lookupUserCategory'];
    }

    /**
     * @return array<int, array{code: string, desc: string}>
     */
    public function defaultLookupUserSegment(): array
    {
        return $this->defaults['lookupUserSegment'];
    }

    /**
     * @return array<int, array{code: string, desc: string}>
     */
    public function defaultLookupUserJenisPengguna(): array
    {
        return $this->defaults['lookupUserJenisPengguna'];
    }

    /**
     * Normalize user-level lookup: legacy "0 - developer" strings, or rows with desc / label.
     *
     * @param  array<int, mixed>  $items
     * @param  array<int, array{code: string, desc: string}>  $default
     * @return array<int, array{code: string, desc: string}>
     */
    protected function normalizeLookupUserLevelRows(array $items, array $default): array
    {
        $out = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                if (preg_match('/^\s*(\S+)\s*-\s*(.+)$/u', $item, $m)) {
                    $out[] = ['code' => trim($m[1]), 'desc' => trim($m[2])];
                }

                continue;
            }
            if (is_array($item)) {
                $code = isset($item['code']) ? trim((string) $item['code']) : '';
                $desc = isset($item['desc']) ? trim((string) $item['desc']) : '';
                if ($desc === '' && isset($item['label'])) {
                    $desc = trim((string) $item['label']);
                }
                if ($desc === '' && isset($item['description'])) {
                    $desc = trim((string) $item['description']);
                }
                if ($code !== '' && $desc !== '') {
                    $out[] = ['code' => $code, 'desc' => $desc];
                }
            }
        }

        return $out !== [] ? $out : $default;
    }

    /**
     * Get lookup options for a given key (e.g. 'system' → string list, 'userLevel' → code/desc rows).
     *
     * @return array<int, string>|array<int, array{code: string, desc: string}>
     */
    public function getLookup(string $key): array
    {
        $settingKey = 'lookup'.Str::studly($key);
        if (! isset($this->defaults[$settingKey])) {
            return [];
        }

        $value = $this->getAll()[$settingKey] ?? $this->defaults[$settingKey];

        return is_array($value) ? $value : [];
    }

    /**
     * Update a lookup (string list for system; code/desc rows for userLevel).
     *
     * @param  array<int, string>|array<int, array{code: string, desc: string}>  $options
     */
    public function setLookup(string $key, array $options): void
    {
        $settingKey = 'lookup'.Str::studly($key);
        $this->set($settingKey, json_encode($options));
    }
}
