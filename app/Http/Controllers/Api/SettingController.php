<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SettingService $settingService,
    ) {}

    /**
     * Return all settings.
     */
    public function index(): JsonResponse
    {
        $settings = $this->settingService->getAll();

        return $this->sendOk($settings);
    }

    /**
     * Update settings.
     */
    public function update(Request $request): JsonResponse
    {
        // The CamelCaseMiddleware converts incoming keys to snake_case,
        // but settings keys must be stored as camelCase. Convert them back.
        $raw = $request->all();
        $data = [];
        foreach ($raw as $key => $value) {
            $data[Str::camel($key)] = $value;
        }

        $this->settingService->update($data);

        return $this->sendOk($data);
    }

    /**
     * Get admin menu preferences.
     */
    public function adminMenuPrefs(): JsonResponse
    {
        $value = $this->settingService->get('adminMenuPrefs');

        if ($value) {
            return $this->sendOk(json_decode($value, true));
        }

        return $this->sendOk(null);
    }

    /**
     * Update admin menu preferences.
     */
    public function updateAdminMenuPrefs(Request $request): JsonResponse
    {
        $prefs = $request->all();

        $this->settingService->set('adminMenuPrefs', json_encode($prefs));

        return $this->sendOk($prefs);
    }

    /**
     * Get all lookups (e.g. system: [KERISI, iAGC, eGPA]).
     */
    public function lookups(): JsonResponse
    {
        return $this->sendOk([
            'system' => $this->settingService->getLookup('system'),
            'user_level' => $this->settingService->getLookup('userLevel'),
            'user_category' => $this->settingService->getLookup('userCategory'),
            'user_segment' => $this->settingService->getLookup('userSegment'),
            'user_jenis_pengguna' => $this->settingService->getLookup('user_jenis_pengguna'),
        ]);
    }

    /**
     * Update lookups.
     */
    public function updateLookups(Request $request): JsonResponse
    {
        $data = $request->all();
        if (isset($data['system']) && is_array($data['system'])) {
            $this->settingService->setLookup('system', array_values(array_filter(array_map('trim', $data['system']))));
        }
        if (isset($data['user_level']) && is_array($data['user_level'])) {
            $normalized = $this->normalizeCodeDescLookupRequestRows($data['user_level']);
            $this->settingService->setLookup(
                'userLevel',
                $normalized !== [] ? $normalized : $this->settingService->defaultLookupUserLevel()
            );
        }
        if (isset($data['user_category']) && is_array($data['user_category'])) {
            $normalized = $this->normalizeCodeDescLookupRequestRows($data['user_category']);
            $this->settingService->setLookup(
                'userCategory',
                $normalized !== [] ? $normalized : $this->settingService->defaultLookupUserCategory()
            );
        }
        if (isset($data['user_segment']) && is_array($data['user_segment'])) {
            $normalized = $this->normalizeCodeDescLookupRequestRows($data['user_segment']);
            $this->settingService->setLookup(
                'userSegment',
                $normalized !== [] ? $normalized : $this->settingService->defaultLookupUserSegment()
            );
        }
        if (isset($data['user_jenis_pengguna']) && is_array($data['user_jenis_pengguna'])) {
            $normalized = $this->normalizeCodeDescLookupRequestRows($data['user_jenis_pengguna']);
            $this->settingService->setLookup(
                'user_jenis_pengguna',
                $normalized !== [] ? $normalized : $this->settingService->defaultLookupUserJenisPengguna()
            );
        }

        return $this->sendOk([
            'system' => $this->settingService->getLookup('system'),
            'user_level' => $this->settingService->getLookup('userLevel'),
            'user_category' => $this->settingService->getLookup('userCategory'),
            'user_segment' => $this->settingService->getLookup('userSegment'),
            'user_jenis_pengguna' => $this->settingService->getLookup('user_jenis_pengguna'),
        ]);
    }

    /**
     * @param  array<int, mixed>  $input
     * @return array<int, array{code: string, desc: string}>
     */
    private function normalizeCodeDescLookupRequestRows(array $input): array
    {
        $normalized = [];
        $seen = [];
        foreach ($input as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = isset($row['code']) ? trim((string) $row['code']) : '';
            $desc = isset($row['desc']) ? trim((string) $row['desc']) : '';
            if ($desc === '' && isset($row['label'])) {
                $desc = trim((string) $row['label']);
            }
            if ($desc === '' && isset($row['description'])) {
                $desc = trim((string) $row['description']);
            }
            if ($code === '' || $desc === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $normalized[] = ['code' => $code, 'desc' => $desc];
        }

        return $normalized;
    }

    /**
     * Get storefront menu.
     */
    public function storefrontMenu(): JsonResponse
    {
        $value = $this->settingService->get('storefrontMenu');

        if (! $value) {
            return $this->sendOk([]);
        }

        try {
            $items = json_decode($value, true);
            $normalized = $this->normalizeStorefrontMenuItems($items ?? []);

            return $this->sendOk($normalized);
        } catch (\Throwable) {
            return $this->sendOk([]);
        }
    }

    /**
     * Update storefront menu.
     */
    public function updateStorefrontMenu(Request $request): JsonResponse
    {
        $items = $this->normalizeStorefrontMenuItems($request->all());

        $this->settingService->set('storefrontMenu', json_encode($items));

        return $this->sendOk($items);
    }

    /**
     * Normalize storefront menu items with ID assignment logic.
     */
    protected function normalizeStorefrontMenuItems(array $input): array
    {
        // Assign IDs to items that don't have one
        $withIds = [];
        foreach ($input as $index => $item) {
            $withIds[] = [
                'id' => ! empty(trim($item['id'] ?? '')) ? trim($item['id']) : 'menu_'.($index + 1),
                'label' => $item['label'] ?? '',
                'href' => $item['href'] ?? '',
                'parentId' => $item['parentId'] ?? null,
                'openInNewTab' => $item['openInNewTab'] ?? false,
            ];
        }

        // Build set of valid IDs
        $idSet = array_map(fn ($item) => $item['id'], $withIds);

        // Validate parentId references
        return array_map(function ($item) use ($idSet) {
            $parentId = $item['parentId'];
            if ($parentId && in_array($parentId, $idSet) && $parentId !== $item['id']) {
                $item['parentId'] = $parentId;
            } else {
                $item['parentId'] = null;
            }

            return $item;
        }, $withIds);
    }
}
