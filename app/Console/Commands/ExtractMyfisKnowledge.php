<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\OpenAIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExtractMyfisKnowledge extends Command
{
    protected $signature = 'kerisi:extract-knowledge
                            {--section=all : Section to extract (all|menu|menu_access|lookup|bl|pages|schema|workflow|rbac). menu_access = navigation + group menu permissions; lookup = fims_usr reference tables}
                            {--upload : Upload to OpenAI Vector Store after extraction}
                            {--tunnel : Ensure SSH tunnel is active before running}';

    protected $description = 'Extract MYFIS/KERISI system knowledge from database and upload to AI';

    private \PDO $db;

    private OpenAIService $openAI;

    public function handle(): int
    {
        $this->info('🔍 KERISI Knowledge Extractor');
        $this->info('================================');

        // Ensure tunnel active
        if ($this->option('tunnel')) {
            $this->ensureTunnel();
        }

        try {
            $this->db = new \PDO(
                'mysql:host='.env('MYFIS_DB_HOST', '127.0.0.1').';port='.env('MYFIS_DB_PORT', '3307').';dbname=fims;charset=utf8mb4',
                env('MYFIS_DB_USERNAME', 'admin'),
                env('MYFIS_DB_PASSWORD', ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            $this->info('✅ Connected to MYFIS database');
        } catch (\Exception $e) {
            $this->error('❌ Cannot connect: '.$e->getMessage());
            $this->error('Make sure SSH tunnel is active. Run with --tunnel flag or manually: ');
            $this->error('ssh -i ~/.ssh/kerisi-bastion.pem -f -N -L 3307:'.env('MYFIS_DB_INTERNAL_HOST').':3306 ec2-user@'.env('MYFIS_BASTION_HOST'));

            return 1;
        }

        $this->openAI = app(OpenAIService::class);
        $section = $this->option('section');
        $upload = $this->option('upload');

        Storage::disk('local')->makeDirectory('kerisi-knowledge');

        $generated = [];

        if (in_array($section, ['all', 'menu'])) {
            $generated[] = $this->extractMenuStructure();
        }

        if (in_array($section, ['all', 'menu_access'])) {
            $generated[] = $this->extractMenuAccessForAi();
        }

        if (in_array($section, ['all', 'lookup'])) {
            $generated = array_merge($generated, $this->extractLookupTables());
        }

        if (in_array($section, ['all', 'pages'])) {
            $generated = array_merge($generated, $this->extractPagesByModule());
        }

        if (in_array($section, ['all', 'bl'])) {
            $generated = array_merge($generated, $this->extractBusinessLogic());
        }

        if (in_array($section, ['all', 'schema'])) {
            $generated = array_merge($generated, $this->extractDatabaseSchema());
        }

        if (in_array($section, ['all', 'workflow'])) {
            $generated = array_merge($generated, $this->extractWorkflow());
        }

        if (in_array($section, ['all', 'rbac'])) {
            $generated = array_merge($generated, $this->extractRbac());
        }

        // Include ticket resolution guide (critical for Support Chat) when uploading
        $ticketGuidePath = 'kerisi-knowledge/kerisi-ticket-resolution-guide.md';
        if ($upload && Storage::disk('local')->exists($ticketGuidePath)) {
            $generated[] = $ticketGuidePath;
            $this->line('  ✅ kerisi-ticket-resolution-guide.md (included for upload)');
        }

        $this->newLine();
        $this->info('📄 Generated '.count($generated).' knowledge documents');

        if ($upload) {
            $this->uploadToVectorStore($generated);
        } else {
            $this->info('💡 Run with --upload to push to OpenAI Vector Store');
        }

        return 0;
    }

    // ─── 1. Menu Structure ────────────────────────────────────────────────────

    private function extractMenuStructure(): string
    {
        $this->info('📋 Extracting menu structure...');

        $menus = $this->db->query('
            SELECT MENUID, MENUNAME, MENUPARENT, MENULEVEL, MENULINK, MENUSTATUS
            FROM FLC_MENU
            WHERE MENUSTATUS = 1
            ORDER BY MENULEVEL, MENUPARENT, MENUID
        ')->fetchAll(\PDO::FETCH_ASSOC);

        $content = "# KERISI System - Complete Menu Structure\n\n";
        $content .= "This document lists all menus and navigation structure in the KERISI (MYFIS) system.\n\n";

        // Build tree
        $tree = [];
        $indexed = [];
        foreach ($menus as $menu) {
            $indexed[$menu['MENUID']] = $menu;
            $indexed[$menu['MENUID']]['children'] = [];
        }
        foreach ($indexed as $id => $menu) {
            if ($menu['MENUPARENT'] == 0 || ! isset($indexed[$menu['MENUPARENT']])) {
                $tree[$id] = &$indexed[$id];
            } else {
                $indexed[$menu['MENUPARENT']]['children'][$id] = &$indexed[$id];
            }
        }

        $content .= $this->renderMenuTree($tree, 0);
        $content .= $this->formatMenuPathIndex($menus);

        return $this->saveDocument('kerisi-menu-structure', 'KERISI Menu Structure', $content, 'System Navigation');
    }

    /**
     * Full menu trail (parent / child / …) for KB search — matches UI breadcrumbs e.g. "Budget / Increment".
     *
     * @param  array<int, array<string, mixed>>  $byId  MENUID => row
     */
    private function menuBreadcrumbTrail(array $byId, int $menuId): string
    {
        $parts = [];
        $current = $menuId;
        $guard = 0;
        while ($current > 0 && isset($byId[$current]) && $guard++ < 64) {
            $name = trim((string) ($byId[$current]['MENUNAME'] ?? ''));
            if ($name !== '') {
                $parts[] = $name;
            }
            $parent = (int) ($byId[$current]['MENUPARENT'] ?? 0);
            if ($parent === 0) {
                break;
            }
            $current = $parent;
        }

        return implode(' / ', array_reverse($parts));
    }

    /**
     * Markdown table: every visible menu with breadcrumb path (for vector search & Ctrl+F in exported docs).
     *
     * @param  array<int, array<string, mixed>>  $menus
     */
    private function formatMenuPathIndex(array $menus): string
    {
        $byId = [];
        foreach ($menus as $m) {
            $byId[(int) $m['MENUID']] = $m;
        }

        $rows = [];
        foreach ($menus as $m) {
            $id = (int) $m['MENUID'];
            $trail = $this->menuBreadcrumbTrail($byId, $id);
            $link = trim((string) ($m['MENULINK'] ?? ''));
            $rows[] = ['trail' => $trail, 'id' => $id, 'link' => $link];
        }

        usort($rows, fn ($a, $b) => strcmp($a['trail'], $b['trail']));

        $out = "\n## Menu path index (full trail for search)\n\n";
        $out .= "Use this table to find screens by the same wording as the UI breadcrumb (e.g. **Budget / Increment**).\n\n";
        $out .= "| Menu path | MENUID | MENULINK |\n";
        $out .= "|-----------|--------|----------|\n";
        foreach ($rows as $r) {
            $linkCell = $r['link'] !== '' ? '`'.$this->escapeMarkdownCell($r['link']).'`' : '—';
            $out .= '| '.$this->escapeMarkdownCell($r['trail']).' | '.$r['id'].' | '.$linkCell." |\n";
        }
        $out .= "\n";

        return $out;
    }

    private function renderMenuTree(array $nodes, int $depth): string
    {
        $text = '';
        $prefix = str_repeat('  ', $depth);
        foreach ($nodes as $node) {
            $link = isset($node['MENULINK']) && $node['MENULINK'] !== '' && $node['MENULINK'] !== null
                ? ' — `'.$node['MENULINK'].'`'
                : ' — _(no MENULINK)_';
            $text .= "{$prefix}- **{$node['MENUNAME']}** (ID: {$node['MENUID']}){$link}\n";
            if (! empty($node['children'])) {
                $text .= $this->renderMenuTree($node['children'], $depth + 1);
            }
        }

        return $text;
    }

    private function extractMenuAccessForAi(): string
    {
        $this->info('📋 Extracting menu navigation and access (groups, permissions, user mapping)...');

        $baseUrl = rtrim((string) env('KERISI_SYSTEM_URL', ''), '/');
        $content = "# Menu access and system navigation\n\n";
        $content .= "This mapping follows: **FLC_MENU** (all menu access), **PRUSER** (user profile), **FLC_USER_GROUP** (user groups), **FLC_USER_GRP_MAPPING** (user-group link), and **FLC_PERMISSION** (menu permission by group).\n\n";
        if ($baseUrl !== '') {
            $content .= "**Base URL** (`KERISI_SYSTEM_URL`): `{$baseUrl}`\n\n";
        } else {
            $content .= "Set **`KERISI_SYSTEM_URL`** in `.env` to build full URLs when needed.\n\n";
        }

        $menus = $this->db->query('
            SELECT MENUID, MENUNAME, MENUPARENT, MENULEVEL, MENULINK, MENUSTATUS
            FROM FLC_MENU
            WHERE MENUSTATUS = 1
            ORDER BY MENULEVEL, MENUPARENT, MENUID
        ')->fetchAll(\PDO::FETCH_ASSOC);

        $tree = [];
        $indexed = [];
        foreach ($menus as $menu) {
            $indexed[$menu['MENUID']] = $menu;
            $indexed[$menu['MENUID']]['children'] = [];
        }
        foreach ($indexed as $id => $menu) {
            if ($menu['MENUPARENT'] == 0 || ! isset($indexed[$menu['MENUPARENT']])) {
                $tree[$id] = &$indexed[$id];
            } else {
                $indexed[$menu['MENUPARENT']]['children'][$id] = &$indexed[$id];
            }
        }

        $content .= "## Visible menus (with MENULINK)\n\n";
        $content .= $this->renderMenuTree($tree, 0);
        $content .= $this->formatMenuPathIndex($menus);
        $content .= "\n";

        $content .= "## User groups (FLC_USER_GROUP)\n\n";
        $groups = [];
        try {
            $groups = $this->db->query('
                SELECT GROUP_ID, GROUP_CODE, DESCRIPTION, GROUP_PARENT
                FROM FLC_USER_GROUP
                ORDER BY GROUP_CODE
            ')->fetchAll(\PDO::FETCH_ASSOC);
            if ($groups === []) {
                $content .= "_No rows._\n\n";
            } else {
                $content .= "| GROUP_ID | GROUP_CODE | DESCRIPTION | GROUP_PARENT |\n";
                $content .= "|----------|------------|-------------|---------------|\n";
                foreach ($groups as $g) {
                    $desc = $this->escapeMarkdownCell((string) ($g['DESCRIPTION'] ?? ''));
                    $content .= '| '.(int) $g['GROUP_ID'].' | `'.$this->escapeMarkdownCell((string) $g['GROUP_CODE']).'` | '.$desc.' | '.($g['GROUP_PARENT'] ?? '—')." |\n";
                }
                $content .= "\n";
            }
        } catch (\Throwable $e) {
            $content .= '_Could not read FLC_USER_GROUP:_ `'.$this->escapeMarkdownCell($e->getMessage())."`\n\n";
        }

        $content .= "## Menu items granted per group (FLC_PERMISSION, PERM_TYPE = menu)\n\n";
        foreach ($groups as $g) {
            $gid = (int) $g['GROUP_ID'];
            $code = $this->escapeMarkdownCell((string) ($g['GROUP_CODE'] ?? ''));
            $content .= "### Group: `{$code}` (GROUP_ID {$gid})\n\n";
            try {
                $items = $this->db->query("
                    SELECT m.MENUID, m.MENUNAME, m.MENULINK
                    FROM FLC_PERMISSION p
                    INNER JOIN FLC_MENU m ON m.MENUID = p.PERM_ITEM
                    WHERE p.GROUP_ID = {$gid} AND p.PERM_TYPE = 'menu'
                    ORDER BY m.MENUNAME
                ")->fetchAll(\PDO::FETCH_ASSOC);
                if ($items === []) {
                    $content .= "_No menu permissions for this group._\n\n";

                    continue;
                }
                $content .= "| MENUID | MENUNAME | MENULINK |\n";
                $content .= "|--------|----------|----------|\n";
                foreach ($items as $it) {
                    $content .= '| '.(int) ($it['MENUID'] ?? 0).' | '
                        .$this->escapeMarkdownCell((string) ($it['MENUNAME'] ?? '')).' | '
                        .$this->escapeMarkdownCell((string) ($it['MENULINK'] ?? ''))." |\n";
                }
                $content .= "\n";
            } catch (\Throwable $e) {
                $content .= '_Query error:_ `'.$this->escapeMarkdownCell($e->getMessage())."`\n\n";
            }
        }

        [$mapTable, $mapUserCol, $mapGroupCol] = $this->resolveUserGroupMappingSource();
        $content .= '## User–group mapping ('.($mapTable ?? 'unknown table').")\n\n";
        if ($mapTable && $mapUserCol && $mapGroupCol) {
            $mappingSqlLeft = "
                SELECT g.GROUP_CODE, m.`{$mapGroupCol}` AS group_id, m.`{$mapUserCol}` AS map_user_id,
                       u.USERID AS pruser_userid, u.USERNAME, u.NAME, u.EMAIL
                FROM {$mapTable} m
                INNER JOIN FLC_USER_GROUP g ON g.GROUP_ID = m.`{$mapGroupCol}`
                LEFT JOIN PRUSER u ON u.USERID = m.`{$mapUserCol}`
                ORDER BY g.GROUP_CODE, m.`{$mapUserCol}`
                LIMIT 5000
            ";
            $mappingSqlPlain = "
                SELECT g.GROUP_CODE, m.`{$mapGroupCol}` AS group_id, m.`{$mapUserCol}` AS map_user_id
                FROM {$mapTable} m
                INNER JOIN FLC_USER_GROUP g ON g.GROUP_ID = m.`{$mapGroupCol}`
                ORDER BY g.GROUP_CODE, m.`{$mapUserCol}`
                LIMIT 5000
            ";
            $rows = null;
            $withUserColumns = false;
            try {
                $rows = $this->db->query($mappingSqlLeft)->fetchAll(\PDO::FETCH_ASSOC);
                $withUserColumns = true;
            } catch (\Throwable $e) {
                $content .= '_LEFT JOIN PRUSER failed; retrying without user details:_ `'.$this->escapeMarkdownCell($e->getMessage())."`\n\n";
                try {
                    $rows = $this->db->query($mappingSqlPlain)->fetchAll(\PDO::FETCH_ASSOC);
                    $withUserColumns = false;
                } catch (\Throwable $e2) {
                    $content .= '_Mapping query failed:_ `'.$this->escapeMarkdownCell($e2->getMessage())."`\n\n";
                    $rows = null;
                }
            }
            if ($rows !== null) {
                if ($rows === []) {
                    $content .= "_No mapping rows._\n\n";
                } elseif ($withUserColumns) {
                    $content .= "_Up to 5000 rows. `LEFT JOIN PRUSER` on `PRUSER.USERID = mapping.{$mapUserCol}`._\n\n";
                    $content .= "| GROUP_CODE | map_user_id | USERNAME | NAME | EMAIL |\n";
                    $content .= "|------------|-------------|----------|------|-------|\n";
                    foreach ($rows as $r) {
                        $content .= '| `'.$this->escapeMarkdownCell((string) ($r['GROUP_CODE'] ?? '')).'` | '
                            .$this->escapeMarkdownCell((string) ($r['map_user_id'] ?? '')).' | '
                            .$this->escapeMarkdownCell((string) ($r['USERNAME'] ?? '')).' | '
                            .$this->escapeMarkdownCell((string) ($r['NAME'] ?? '')).' | '
                            .$this->escapeMarkdownCell((string) ($r['EMAIL'] ?? ''))." |\n";
                    }
                    $content .= "\n";
                } else {
                    $content .= "_Up to 5000 rows (without PRUSER join)._\n\n";
                    $content .= "| GROUP_CODE | group_id | map_user_id |\n";
                    $content .= "|------------|----------|-------------|\n";
                    foreach ($rows as $r) {
                        $content .= '| `'.$this->escapeMarkdownCell((string) ($r['GROUP_CODE'] ?? '')).'` | '
                            .$this->escapeMarkdownCell((string) ($r['group_id'] ?? '')).' | '
                            .$this->escapeMarkdownCell((string) ($r['map_user_id'] ?? ''))." |\n";
                    }
                    $content .= "\n";
                }
            }
        } else {
            $content .= "_Could not resolve mapping table/columns for FLC_USER_GRP_MAPPING or FLC_USER_GROUP_MAPPING (USER_ID/USERID and GROUP_ID/GROUPID)._\n\n";
        }

        return $this->saveDocument(
            'kerisi-menu-access-complete',
            'KERISI Menu access (navigation + groups)',
            $content,
            'System Navigation'
        );
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [table, userCol, groupCol]
     */
    private function resolveUserGroupMappingSource(): array
    {
        $candidates = ['FLC_USER_GRP_MAPPING', 'FLC_USER_GROUP_MAPPING'];
        foreach ($candidates as $table) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM {$table}");
                $cols = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                continue;
            }
            $names = array_map(static fn ($r) => $r['Field'] ?? '', $cols);
            $userCol = in_array('USER_ID', $names, true)
                ? 'USER_ID'
                : (in_array('USERID', $names, true) ? 'USERID' : null);
            $groupCol = in_array('GROUP_ID', $names, true)
                ? 'GROUP_ID'
                : (in_array('GROUPID', $names, true) ? 'GROUPID' : null);
            if ($userCol && $groupCol) {
                return [$table, $userCol, $groupCol];
            }
        }

        return [null, null, null];
    }

    private function resolvePruserPrimaryColumn(): ?string
    {
        try {
            $dbName = $this->db->query('SELECT DATABASE()')->fetchColumn();
            if ($dbName) {
                $stmt = $this->db->prepare('
                    SELECT COLUMN_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = ?
                    ORDER BY ORDINAL_POSITION
                    LIMIT 1
                ');
                $stmt->execute([(string) $dbName, 'PRUSER', 'PRI']);
                $pk = $stmt->fetchColumn();
                if (is_string($pk) && $pk !== '') {
                    return $pk;
                }
            }
        } catch (\Throwable $e) {
        }

        foreach (['USERID', 'USER_ID'] as $candidate) {
            try {
                $this->db->query("SELECT `{$candidate}` FROM PRUSER LIMIT 1");

                return $candidate;
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private function escapeMarkdownCell(string $s): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);

        return str_replace('|', '\\|', $s);
    }

    private function extractLookupTables(): array
    {
        $this->info('📚 Extracting fims_usr lookup / reference tables (sample data)...');

        $usrConn = new \PDO(
            'mysql:host='.env('MYFIS_DB_HOST', '127.0.0.1').';port='.env('MYFIS_DB_PORT', '3307').';dbname=fims_usr;charset=utf8mb4',
            env('MYFIS_DB_USERNAME', 'admin'),
            env('MYFIS_DB_PASSWORD', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $sql = "
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = 'fims_usr'
              AND TABLE_TYPE = 'BASE TABLE'
              AND (
                LOWER(TABLE_NAME) LIKE 'lookup%'
                OR LOWER(TABLE_NAME) LIKE 'sysref%'
                OR LOWER(TABLE_NAME) LIKE 'ref\\_%' ESCAPE '\\\\'
                OR LOWER(TABLE_NAME) LIKE '%lookup%'
              )
            ORDER BY TABLE_NAME
            LIMIT 200
        ";
        $tables = $usrConn->query($sql)->fetchAll(\PDO::FETCH_COLUMN);
        $tables = array_values(array_filter(array_map('strval', $tables ?? []), function (string $t) {
            return (bool) preg_match('/^[A-Za-z0-9_]+$/', $t);
        }));
        $tables = array_slice($tables, 0, 80);

        $maxTotalChars = 1200000;
        $body = "# Lookup and reference data (fims_usr)\n\n";
        $body .= "Tables from **fims_usr** matching `lookup%`, `sysref%`, `ref_%`, or `%lookup%` (from information_schema, max **200** candidates, **80** tables processed). Each section lists **columns**, **row count**, and up to **40 sample rows** (total document cap **~1.2M** characters).\n\n";

        $totalLen = strlen($body);
        $tablesIncluded = 0;

        foreach ($tables as $tableName) {
            if ($totalLen >= $maxTotalChars) {
                $body .= "\n\n_Document truncated at character budget (~{$maxTotalChars} chars)._\n";
                break;
            }
            $safeTable = '`'.str_replace('`', '``', $tableName).'`';
            $section = "\n## Table: {$safeTable}\n\n";

            try {
                $colRows = $usrConn->query("
                    SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_COMMENT
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = 'fims_usr' AND TABLE_NAME = ".$usrConn->quote($tableName).'
                    ORDER BY ORDINAL_POSITION
                ')->fetchAll(\PDO::FETCH_ASSOC);
                $section .= "| Column | Type | Nullable | Key | Comment |\n";
                $section .= "|--------|------|----------|-----|----------|\n";
                foreach ($colRows as $c) {
                    $section .= '| `'.$c['COLUMN_NAME'].'` | '.$c['COLUMN_TYPE'].' | '.$c['IS_NULLABLE'].' | '.($c['COLUMN_KEY'] ?: '—').' | '.$this->escapeMarkdownCell((string) ($c['COLUMN_COMMENT'] ?? ''))." |\n";
                }
                $section .= "\n";

                $cnt = (int) $usrConn->query("SELECT COUNT(*) FROM {$safeTable}")->fetchColumn();
                $section .= "**Row count:** {$cnt}\n\n";

                if ($cnt > 0) {
                    $samples = $usrConn->query("SELECT * FROM {$safeTable} LIMIT 40")->fetchAll(\PDO::FETCH_ASSOC);
                    if ($samples !== []) {
                        $headerKeys = array_keys($samples[0]);
                        $section .= '| '.implode(' | ', array_map(fn ($h) => '`'.$h.'`', $headerKeys))." |\n";
                        $section .= '|'.str_repeat('---|', count($headerKeys))."\n";
                        foreach ($samples as $row) {
                            $cells = [];
                            foreach ($headerKeys as $k) {
                                $v = $row[$k] ?? null;
                                $cells[] = $this->formatLookupTableCell($v);
                            }
                            $section .= '| '.implode(' | ', $cells).' |'."\n";
                        }
                        $section .= "\n";
                    }
                }
            } catch (\Throwable $e) {
                $section .= '_Error:_ `'.$this->escapeMarkdownCell($e->getMessage())."`\n\n";
            }

            $projected = $totalLen + strlen($section);
            if ($projected > $maxTotalChars) {
                $body .= "\n\n_Document truncated: adding the next table would exceed ~{$maxTotalChars} characters._\n";
                break;
            }
            $body .= $section;
            $totalLen = strlen($body);
            $tablesIncluded++;
        }

        $body .= "\n\n_Table sections included:_ **{$tablesIncluded}**\n";

        $path = $this->saveDocument(
            'kerisi-lookup-reference-complete',
            'KERISI Lookup / Reference (complete sample)',
            $body,
            'Lookup / Reference'
        );

        return [$path];
    }

    private function formatLookupTableCell(mixed $v): string
    {
        if ($v === null) {
            return '_NULL_';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_scalar($v)) {
            return $this->escapeMarkdownCell((string) $v);
        }

        $enc = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $this->escapeMarkdownCell(is_string($enc) ? $enc : '');
    }

    private function extractPagesByModule(): array
    {
        $this->info('📄 Extracting pages, components and items by module...');

        // Get top-level menus as modules
        $topMenus = $this->db->query('
            SELECT MENUID, MENUNAME FROM FLC_MENU
            WHERE MENULEVEL = 1 AND MENUSTATUS = 1
            ORDER BY MENUNAME
        ')->fetchAll(\PDO::FETCH_ASSOC);

        $files = [];
        $bar = $this->output->createProgressBar(count($topMenus));
        $bar->start();

        foreach ($topMenus as $module) {
            $content = $this->buildModuleDoc($module);
            if ($content) {
                $files[] = $this->saveDocument(
                    'kerisi-module-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $module['MENUNAME'])),
                    'KERISI Module: '.$module['MENUNAME'],
                    $content,
                    $module['MENUNAME']
                );
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $files;
    }

    private function buildModuleDoc(array $module): string
    {
        $subMenuIds = $this->getAllSubMenuIds($module['MENUID']);
        if (empty($subMenuIds)) {
            return '';
        }

        $placeholders = implode(',', array_map('intval', $subMenuIds));

        // 1 query: all pages for this module
        $pages = $this->db->query("
            SELECT p.PAGEID, p.PAGENAME, p.PAGEBREADCRUMBS, p.MENUID, m.MENUNAME
            FROM FLC_PAGE p
            JOIN FLC_MENU m ON m.MENUID = p.MENUID
            WHERE p.MENUID IN ({$placeholders})
            ORDER BY p.PAGENAME
        ")->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($pages)) {
            return '';
        }

        $pageIds = array_column($pages, 'PAGEID');
        $pageIdsStr = implode(',', array_map('intval', $pageIds));

        // 1 query: all components for all pages
        $allComponents = $this->db->query("
            SELECT COMPONENTID, PAGEID, COMPONENTTITLE, COMPONENTTYPE, COMPONENTBINDINGSOURCE
            FROM FLC_PAGE_COMPONENT
            WHERE PAGEID IN ({$pageIdsStr})
            ORDER BY PAGEID, COMPONENTORDER
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $compIds = array_column($allComponents, 'COMPONENTID');

        // Index components by page
        $compsByPage = [];
        foreach ($allComponents as $c) {
            $compsByPage[$c['PAGEID']][] = $c;
        }

        $itemsByComp = [];
        $trigsByPage = [];
        $trigsByComp = [];

        if (! empty($compIds)) {
            $compIdsStr = implode(',', array_map('intval', $compIds));

            // 1 query: all items for all components
            $allItems = $this->db->query("
                SELECT COMPONENTID, ITEMNAME, ITEMTYPE, MAPPINGID, ITEMREQUIRED, ITEMREADONLY
                FROM FLC_PAGE_COMPONENT_ITEMS
                WHERE COMPONENTID IN ({$compIdsStr})
                ORDER BY COMPONENTID, ITEMORDER
            ")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($allItems as $it) {
                $itemsByComp[$it['COMPONENTID']][] = $it;
            }

            // 1 query: component-level triggers
            $compTriggers = $this->db->query("
                SELECT t.TRIGGER_ITEM_ID as COMPONENTID, t.TRIGGER_TYPE, t.TRIGGER_EVENT, t.TRIGGER_BL
                FROM FLC_TRIGGER t
                WHERE t.TRIGGER_ITEM_TYPE = 'component'
                AND t.TRIGGER_ITEM_ID IN ({$compIdsStr})
                AND t.TRIGGER_STATUS = 1
            ")->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($compTriggers as $trig) {
                $trigsByComp[$trig['COMPONENTID']][] = $trig;
            }
        }

        // 1 query: all controls for all pages
        $allControls = $this->db->query("
            SELECT CONTROLID, PAGEID, COMPONENTID, CONTROLNAME, CONTROLTITLE, CONTROLTYPE, CONTROLREDIRECTURL
            FROM FLC_PAGE_CONTROL
            WHERE PAGEID IN ({$pageIdsStr})
            ORDER BY PAGEID, CONTROLORDER, CONTROLID
        ")->fetchAll(\PDO::FETCH_ASSOC);
        $controlsByPage = [];
        foreach ($allControls as $ctrl) {
            $controlsByPage[$ctrl['PAGEID']][] = $ctrl;
        }

        // 1 query: page-level triggers
        $pageTriggers = $this->db->query("
            SELECT t.TRIGGER_ITEM_ID as PAGEID, t.TRIGGER_TYPE, t.TRIGGER_EVENT, t.TRIGGER_BL
            FROM FLC_TRIGGER t
            WHERE t.TRIGGER_ITEM_TYPE = 'page'
            AND t.TRIGGER_ITEM_ID IN ({$pageIdsStr})
            AND t.TRIGGER_STATUS = 1
        ")->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($pageTriggers as $trig) {
            $trigsByPage[$trig['PAGEID']][] = $trig;
        }

        // Build document from in-memory data
        $content = "# KERISI Module: {$module['MENUNAME']}\n\n";
        $content .= "This document describes page-building structure for the **{$module['MENUNAME']}** module in KERISI, so technical support can quickly inspect what exists inside each page.\n\n";
        $content .= "## Core relationships and usage\n\n";
        $content .= "- `FLC_MENU` stores navigation entries and links.\n";
        $content .= "- `FLC_PAGE` defines each screen and links to menu via `FLC_PAGE.MENUID -> FLC_MENU.MENUID`.\n";
        $content .= "- `FLC_PAGE_COMPONENT` defines blocks/sections inside a page (`FLC_PAGE.PAGEID -> FLC_PAGE_COMPONENT.PAGEID`).\n";
        $content .= "- `FLC_PAGE_COMPONENT_ITEMS` defines fields/items inside a component (`FLC_PAGE_COMPONENT.COMPONENTID -> FLC_PAGE_COMPONENT_ITEMS.COMPONENTID`).\n";
        $content .= "- `FLC_PAGE_CONTROL` defines actions/buttons and where each action is attached (page/component).\n\n";

        foreach ($pages as $page) {
            $pid = $page['PAGEID'];
            $content .= "## Page: {$page['PAGENAME']}\n";
            if ($page['PAGEBREADCRUMBS']) {
                $content .= "**Path:** {$page['PAGEBREADCRUMBS']}\n";
            }
            $content .= "**Menu:** {$page['MENUNAME']} (`MENUID: {$page['MENUID']}`)\n";
            $componentCount = count($compsByPage[$pid] ?? []);
            $controlCount = count($controlsByPage[$pid] ?? []);
            $content .= "**Structure:** {$componentCount} component(s), {$controlCount} control/action(s)\n\n";

            foreach ($compsByPage[$pid] ?? [] as $comp) {
                $cid = $comp['COMPONENTID'];
                $content .= '### Component: '.strip_tags($comp['COMPONENTTITLE'] ?? '')."\n";
                $content .= "- Type: `{$comp['COMPONENTTYPE']}`\n";
                if ($comp['COMPONENTBINDINGSOURCE']) {
                    $content .= "- Data Source: `{$comp['COMPONENTBINDINGSOURCE']}`\n";
                }

                if (! empty($itemsByComp[$cid])) {
                    $content .= "- **Fields:**\n";
                    foreach ($itemsByComp[$cid] as $item) {
                        $attrs = [];
                        if ($item['ITEMREQUIRED']) {
                            $attrs[] = 'required';
                        }
                        if ($item['ITEMREADONLY']) {
                            $attrs[] = 'readonly';
                        }
                        $attrStr = $attrs ? ' ('.implode(', ', $attrs).')' : '';
                        $content .= "  - `{$item['ITEMNAME']}` [{$item['ITEMTYPE']}]{$attrStr}";
                        if ($item['MAPPINGID']) {
                            $content .= " → `{$item['MAPPINGID']}`";
                        }
                        $content .= "\n";
                    }
                }

                if (! empty($trigsByComp[$cid])) {
                    $content .= "- **Triggers:**\n";
                    foreach ($trigsByComp[$cid] as $trig) {
                        $content .= "  - [{$trig['TRIGGER_TYPE']}] on `{$trig['TRIGGER_EVENT']}` → BL: `{$trig['TRIGGER_BL']}`\n";
                    }
                }

                $content .= "\n";
            }

            if (! empty($trigsByPage[$pid])) {
                $content .= "**Page Triggers:**\n";
                foreach ($trigsByPage[$pid] as $trig) {
                    $content .= "- [{$trig['TRIGGER_TYPE']}] on `{$trig['TRIGGER_EVENT']}` → BL: `{$trig['TRIGGER_BL']}`\n";
                }
                $content .= "\n";
            }

            if (! empty($controlsByPage[$pid])) {
                $content .= "**Controls / Actions (`FLC_PAGE_CONTROL`):**\n";
                foreach ($controlsByPage[$pid] as $ctrl) {
                    $controlTitle = trim((string) ($ctrl['CONTROLTITLE'] ?? '')) ?: (string) ($ctrl['CONTROLNAME'] ?? 'Unnamed control');
                    $controlName = (string) ($ctrl['CONTROLNAME'] ?? '-');
                    $controlType = (int) ($ctrl['CONTROLTYPE'] ?? 0);
                    $typeLabel = self::CONTROL_TYPE_LABELS[$controlType] ?? "Type {$controlType}";
                    $componentRef = $ctrl['COMPONENTID'] ? "component `{$ctrl['COMPONENTID']}`" : 'page-level';
                    $content .= "- **{$controlTitle}** (`{$controlName}`) [{$typeLabel}] on {$componentRef}";
                    if (! empty($ctrl['CONTROLREDIRECTURL'])) {
                        $content .= " → Redirect: `{$ctrl['CONTROLREDIRECTURL']}`";
                    }
                    $content .= "\n";
                }
                $content .= "\n";
            }

            $content .= "---\n\n";
        }

        return $content;
    }

    private function getAllSubMenuIds(int $parentId): array
    {
        $ids = [$parentId];
        $children = $this->db->query("
            SELECT MENUID FROM FLC_MENU WHERE MENUPARENT = {$parentId}
        ")->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($children as $childId) {
            $ids = array_merge($ids, $this->getAllSubMenuIds($childId));
        }

        return $ids;
    }

    // ─── 3. Business Logic (Full Detail for Technical Support) ─────────────────

    private const BL_DETAIL_MAX_CHARS = 2500;

    private function extractBusinessLogic(): array
    {
        $this->info('⚙️  Extracting Business Logic (with code detail for technical support)...');
        @ini_set('memory_limit', '512M');

        // Fetch triggers once (small dataset)
        $triggersByBL = [];
        $trigRows = $this->db->query("
            SELECT TRIGGER_BL, TRIGGER_TYPE, TRIGGER_EVENT, TRIGGER_ITEM_TYPE, TRIGGER_ITEM_ID
            FROM FLC_TRIGGER
            WHERE TRIGGER_STATUS = 1 AND TRIGGER_BL IS NOT NULL AND TRIGGER_BL != ''
        ")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($trigRows as $t) {
            $blName = trim($t['TRIGGER_BL']);
            if (! isset($triggersByBL[$blName])) {
                $triggersByBL[$blName] = [];
            }
            $triggersByBL[$blName][] = $t;
        }

        // Get module -> SQL LIKE patterns
        $modulePatterns = $this->getBLModulePatterns();

        $files = [];
        $bar = $this->output->createProgressBar(count($modulePatterns));
        $bar->start();

        foreach ($modulePatterns as $moduleName => $likePatterns) {
            $conditions = implode(' OR ', array_map(fn ($p) => 'BLNAME LIKE '.$this->db->quote($p), $likePatterns));

            $moduleBLs = $this->db->query('
                SELECT BLID, BLNAME, BLTITLE, BLTYPE, BLDESCRIPTION,
                       LEFT(BLDETAIL, '.self::BL_DETAIL_MAX_CHARS.") as BLDETAIL_PREVIEW,
                       LENGTH(BLDETAIL) as BLDETAIL_FULL_LEN
                FROM FLC_BL
                WHERE (BLSTATUS IS NULL OR BLSTATUS != 'DELETED')
                AND BLDETAIL IS NOT NULL AND BLDETAIL != ''
                AND ({$conditions})
                ORDER BY BLNAME
            ")->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($moduleBLs)) {
                $bar->advance();

                continue;
            }
            $content = "# KERISI Business Logic - {$moduleName}\n\n";
            $content .= "**Core system logic for technical support.** When users report issues, match the BL name from error messages, logs, or page triggers to find the actual code and logic.\n\n";
            $content .= "Each BL below includes:\n";
            $content .= "- **BL Name** — identifier used in triggers and API calls\n";
            $content .= "- **Code preview** — first part of the logic (PHP backend or JS frontend)\n";
            $content .= "- **Triggers** — when/where this BL is called (page, component, event)\n\n";
            $content .= "---\n\n";

            $phpBLs = array_filter($moduleBLs, fn ($b) => $b['BLTYPE'] === 'PHP');
            $jsBLs = array_filter($moduleBLs, fn ($b) => $b['BLTYPE'] === 'JS');

            if (! empty($phpBLs)) {
                $content .= "## PHP Business Logic (Backend API)\n\n";
                foreach ($phpBLs as $bl) {
                    $content .= $this->formatBLBlock($bl, $triggersByBL);
                }
            }

            if (! empty($jsBLs)) {
                $content .= "## JS Business Logic (Frontend)\n\n";
                foreach ($jsBLs as $bl) {
                    $content .= $this->formatBLBlock($bl, $triggersByBL);
                }
            }

            $slug = 'kerisi-bl-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $moduleName));
            $path = $this->saveDocument($slug, 'KERISI BL: '.$moduleName, $content, $moduleName);
            $files[] = ['path' => $path, 'bl_count' => count($moduleBLs)];
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $files;
    }

    private function formatBLBlock(array $bl, array $triggersByBL): string
    {
        $blName = $bl['BLNAME'];
        $triggers = $triggersByBL[$blName] ?? [];

        $out = "### {$bl['BLTITLE']}\n\n";
        $out .= "- **BL Name:** `{$blName}`\n";
        $out .= '- **Type:** '.($bl['BLTYPE'] === 'PHP' ? 'PHP (Backend)' : 'JS (Frontend)')."\n";
        if (! empty($bl['BLDESCRIPTION'])) {
            $out .= "- **Description:** {$bl['BLDESCRIPTION']}\n";
        }

        if (! empty($triggers)) {
            $out .= "- **Called by:**\n";
            foreach (array_slice($triggers, 0, 10) as $t) {
                $out .= "  - [{$t['TRIGGER_TYPE']}] `{$t['TRIGGER_EVENT']}` on {$t['TRIGGER_ITEM_TYPE']} ID {$t['TRIGGER_ITEM_ID']}\n";
            }
            if (count($triggers) > 10) {
                $out .= '  - ... and '.(count($triggers) - 10)." more\n";
            }
        }

        $detail = $bl['BLDETAIL_PREVIEW'] ?? '';
        if ($detail) {
            $truncated = ($bl['BLDETAIL_FULL_LEN'] ?? 0) > self::BL_DETAIL_MAX_CHARS;
            $out .= "\n**Code:**\n```\n".$this->escapeCodeBlock($detail).($truncated ? "\n... (truncated)" : '')."\n```\n\n";
        }

        $out .= "---\n\n";

        return $out;
    }

    private function escapeCodeBlock(string $s): string
    {
        return str_replace('```', '` ` `', $s);
    }

    /** @return array<string, string[]> Module name => LIKE patterns for SQL */
    private function getBLModulePatterns(): array
    {
        $map = [
            'Cashbook' => ['%CASHBOOK%'],
            'Payroll' => ['%PAYROLL%', '%GAJI%'],
            'Budget' => ['%BUDGET%', '%BAJET%', '%VIREMENT%', '%INCREMENT%', '%DECREMENT%'],
            'Asset' => ['%ASSET%', '%ASET%'],
            'Purchasing' => ['%PURCHASING%', '%REQUISIT%', '%STORE%'],
            'Vendor Portal' => ['%VENDOR%'],
            'Debtor Portal' => ['%DEBTOR%'],
            'Credit Control' => ['%CREDIT%', '%CCONTROL%'],
            'Account Payable' => ['%VOUCHER%', '%BAUCER%', '%PAYMENT%', '%BILL%', '%AP_%'],
            'Account Receivable' => ['%RECEIPT%', '%INVOICE%', '%AR_%', '%CUST%'],
            'General Ledger' => ['%GL%', '%JOURNAL%', '%POSTING%', '%LEDGER%'],
            'Loan' => ['%LOAN%', '%PINJAMAN%'],
            'Investment' => ['%INVEST%'],
            'Staff Portal' => ['%STAFF%'],
            'Student Finance' => ['%STUDENT%'],
            'Travel Claim' => ['%TRAVEL%'],
            'Advance' => ['%ADVANCE%'],
            'Reports' => ['%REPORT%'],
            'Setup & Maintenance' => ['%SETUP%'],
            'Authentication' => ['%AUTH%', '%LOGIN%'],
            'User Management' => ['%USER%'],
        ];

        $out = [];
        foreach ($map as $module => $patterns) {
            $out[$module] = $patterns;
        }

        return $out;
    }

    private function detectBLModule(string $blName): string
    {
        $upperName = strtoupper($blName);
        foreach ($this->getBLModulePatterns() as $module => $patterns) {
            if ($module === 'General') {
                continue;
            }
            foreach ($patterns as $p) {
                $pattern = str_replace('%', '', $p);
                if (str_contains($upperName, $pattern)) {
                    return $module;
                }
            }
        }

        return 'General';
    }

    // ─── 4. Database Schema (fims_usr) ────────────────────────────────────────

    private function extractDatabaseSchema(): array
    {
        $this->info('🗄️  Extracting fims_usr database schema + relationships...');

        // Connect to fims_usr instead of fims
        $usrConn = new \PDO(
            'mysql:host='.env('MYFIS_DB_HOST', '127.0.0.1').';port='.env('MYFIS_DB_PORT', '3307').';dbname=fims_usr;charset=utf8mb4',
            env('MYFIS_DB_USERNAME', 'admin'),
            env('MYFIS_DB_PASSWORD', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // All columns
        $rows = $usrConn->query("
            SELECT
                c.TABLE_NAME,
                c.COLUMN_NAME,
                c.COLUMN_TYPE,
                c.IS_NULLABLE,
                c.COLUMN_KEY,
                c.COLUMN_COMMENT
            FROM information_schema.COLUMNS c
            WHERE c.TABLE_SCHEMA = 'fims_usr'
            ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $tableColumns = [];
        foreach ($rows as $row) {
            $tableColumns[$row['TABLE_NAME']][] = $row;
        }

        // All FK relationships (child -> parent)
        $fkRows = $usrConn->query("
            SELECT
                kcu.TABLE_NAME AS CHILD_TABLE,
                kcu.COLUMN_NAME AS CHILD_COLUMN,
                kcu.REFERENCED_TABLE_NAME AS PARENT_TABLE,
                kcu.REFERENCED_COLUMN_NAME AS PARENT_COLUMN,
                kcu.CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE kcu
            WHERE kcu.TABLE_SCHEMA = 'fims_usr'
              AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $relationshipsByChild = [];
        $relationshipsByParent = [];
        foreach ($fkRows as $fk) {
            $child = (string) $fk['CHILD_TABLE'];
            $parent = (string) $fk['PARENT_TABLE'];
            $relationshipsByChild[$child][] = $fk;
            $relationshipsByParent[$parent][] = $fk;
        }

        // Group tables by module prefix
        $moduleMap = [
            'budget' => 'Budget',
            'staff_' => 'Staff & Payroll',
            'payroll' => 'Staff & Payroll',
            'asset_' => 'Asset',
            'invest' => 'Investment',
            'ccontrl' => 'Credit Control',
            'ccontr' => 'Credit Control',
            'int_st' => 'Account Receivable (AR)',
            'lookup' => 'Lookup / Reference',
            'posting' => 'General Ledger',
            'gl_' => 'General Ledger',
            'bills_' => 'Account Payable (AP)',
            'voucher' => 'Account Payable (AP)',
            'store_' => 'Purchasing / Store',
            'requisit' => 'Purchasing / Store',
            'travel' => 'Travel Claim',
            'activity' => 'Activity',
            'capital' => 'Capital',
            'monthly' => 'Monthly Report',
            'cust_i' => 'Customer Info',
            'rep_ag' => 'Report Aging',
            'temp_' => 'Temp Tables',
            'lv_seq' => 'Sequence / Reference',
        ];

        $grouped = [];
        foreach (array_keys($tableColumns) as $tableName) {
            $matched = false;
            foreach ($moduleMap as $prefix => $moduleName) {
                if (str_starts_with(strtolower($tableName), strtolower($prefix))) {
                    $grouped[$moduleName][] = $tableName;
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $grouped['Other'][] = $tableName;
            }
        }

        ksort($grouped);

        $files = [];

        // Master full schema doc for AI grounding
        $master = "# KERISI Database Schema (Complete) + Relationships\n\n";
        $master .= "Database: `fims_usr`\n\n";
        $master .= "This document contains all tables, columns, and FK relationships.\n\n";
        $master .= "## Foreign Key Relationships\n\n";
        if (empty($fkRows)) {
            $master .= "- No FK rows found in information_schema.\n\n";
        } else {
            $master .= "| Child Table | Child Column | Parent Table | Parent Column | Constraint |\n";
            $master .= "|------------|--------------|--------------|---------------|------------|\n";
            foreach ($fkRows as $fk) {
                $master .= "| `{$fk['CHILD_TABLE']}` | `{$fk['CHILD_COLUMN']}` | `{$fk['PARENT_TABLE']}` | `{$fk['PARENT_COLUMN']}` | `{$fk['CONSTRAINT_NAME']}` |\n";
            }
            $master .= "\n";
        }
        $master .= "## All Tables\n\n";
        foreach ($tableColumns as $tableName => $cols) {
            $master .= "### Table: `{$tableName}`\n\n";
            if (! empty($relationshipsByChild[$tableName])) {
                $master .= "**References (child -> parent):**\n";
                foreach ($relationshipsByChild[$tableName] as $fk) {
                    $master .= "- `{$fk['CHILD_COLUMN']}` -> `{$fk['PARENT_TABLE']}.{$fk['PARENT_COLUMN']}` (`{$fk['CONSTRAINT_NAME']}`)\n";
                }
                $master .= "\n";
            }
            if (! empty($relationshipsByParent[$tableName])) {
                $master .= "**Referenced by (parent <- child):**\n";
                foreach ($relationshipsByParent[$tableName] as $fk) {
                    $master .= "- `{$fk['CHILD_TABLE']}.{$fk['CHILD_COLUMN']}` -> `{$fk['PARENT_COLUMN']}` (`{$fk['CONSTRAINT_NAME']}`)\n";
                }
                $master .= "\n";
            }
            $master .= "| Column | Type | Nullable | Key | Comment |\n";
            $master .= "|--------|------|----------|-----|---------|\n";
            foreach ($cols as $col) {
                $nullable = $col['IS_NULLABLE'] === 'YES' ? 'YES' : 'NO';
                $key = $col['COLUMN_KEY'] ?: '-';
                $comment = $col['COLUMN_COMMENT'] ?: '-';
                $master .= "| `{$col['COLUMN_NAME']}` | {$col['COLUMN_TYPE']} | {$nullable} | {$key} | {$comment} |\n";
            }
            $master .= "\n";
        }
        $files[] = $this->saveDocument(
            'kerisi-schema-complete-relationships',
            'KERISI DB Schema: Complete + Relationships',
            $master,
            'Database Schema'
        );

        $bar = $this->output->createProgressBar(count($grouped));
        $bar->start();
        foreach ($grouped as $moduleName => $tables) {
            $content = "# KERISI Database Schema: {$moduleName}\n\n";
            $content .= "Tables, columns, and relationships for **{$moduleName}** in KERISI (`fims_usr`).\n\n";

            foreach ($tables as $tableName) {
                $cols = $tableColumns[$tableName] ?? [];
                $content .= "## Table: `{$tableName}`\n\n";
                if (! empty($relationshipsByChild[$tableName])) {
                    $content .= "**References (child -> parent):**\n";
                    foreach ($relationshipsByChild[$tableName] as $fk) {
                        $content .= "- `{$fk['CHILD_COLUMN']}` -> `{$fk['PARENT_TABLE']}.{$fk['PARENT_COLUMN']}` (`{$fk['CONSTRAINT_NAME']}`)\n";
                    }
                    $content .= "\n";
                }
                if (! empty($relationshipsByParent[$tableName])) {
                    $content .= "**Referenced by (parent <- child):**\n";
                    foreach ($relationshipsByParent[$tableName] as $fk) {
                        $content .= "- `{$fk['CHILD_TABLE']}.{$fk['CHILD_COLUMN']}` -> `{$fk['PARENT_COLUMN']}` (`{$fk['CONSTRAINT_NAME']}`)\n";
                    }
                    $content .= "\n";
                }
                $content .= "| Column | Type | Nullable | Key | Comment |\n";
                $content .= "|--------|------|----------|-----|---------|\n";
                foreach ($cols as $col) {
                    $nullable = $col['IS_NULLABLE'] === 'YES' ? 'YES' : 'NO';
                    $key = $col['COLUMN_KEY'] ?: '-';
                    $comment = $col['COLUMN_COMMENT'] ?: '-';
                    $content .= "| `{$col['COLUMN_NAME']}` | {$col['COLUMN_TYPE']} | {$nullable} | {$key} | {$comment} |\n";
                }
                $content .= "\n";
            }

            $slug = 'kerisi-schema-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $moduleName));
            $files[] = $this->saveDocument($slug, "KERISI DB Schema: {$moduleName}", $content, $moduleName);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $files;
    }

    // ─── 5. Workflow (Page Flow: Controls → Triggers → BL) ──────────────────────

    /** Control type labels for documentation */
    private const CONTROL_TYPE_LABELS = [
        1 => 'Submit/Process',
        6 => 'Button',
        8 => 'Action',
        21 => 'Print/Report',
        25 => 'Process',
    ];

    private function extractWorkflow(): array
    {
        $this->info('🔄 Extracting workflow (page flow: controls → triggers → BL)...');

        $topMenus = $this->db->query('
            SELECT MENUID, MENUNAME FROM FLC_MENU
            WHERE MENULEVEL = 1 AND MENUSTATUS = 1
            ORDER BY MENUNAME
        ')->fetchAll(\PDO::FETCH_ASSOC);

        // All control-level triggers
        $triggersByControl = [];
        $trigRows = $this->db->query("
            SELECT TRIGGER_ITEM_ID as CONTROLID, TRIGGER_TYPE, TRIGGER_EVENT, TRIGGER_BL
            FROM FLC_TRIGGER
            WHERE TRIGGER_ITEM_TYPE = 'control' AND TRIGGER_STATUS = 1
        ")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($trigRows as $t) {
            $triggersByControl[$t['CONTROLID']][] = $t;
        }

        $files = [];
        $bar = $this->output->createProgressBar(count($topMenus));
        $bar->start();

        foreach ($topMenus as $module) {
            $subMenuIds = $this->getAllSubMenuIds($module['MENUID']);
            if (empty($subMenuIds)) {
                $bar->advance();

                continue;
            }

            $placeholders = implode(',', array_map('intval', $subMenuIds));

            $pages = $this->db->query("
                SELECT p.PAGEID, p.PAGENAME, p.PAGEBREADCRUMBS, m.MENUNAME
                FROM FLC_PAGE p
                JOIN FLC_MENU m ON m.MENUID = p.MENUID
                WHERE p.MENUID IN ({$placeholders})
                ORDER BY p.PAGENAME
            ")->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($pages)) {
                $bar->advance();

                continue;
            }

            $pageIds = array_column($pages, 'PAGEID');
            $pageIdsStr = implode(',', array_map('intval', $pageIds));

            $controls = $this->db->query("
                SELECT CONTROLID, CONTROLNAME, CONTROLTITLE, CONTROLTYPE, CONTROLORDER,
                       CONTROLREDIRECTURL, PAGEID, COMPONENTID
                FROM FLC_PAGE_CONTROL
                WHERE PAGEID IN ({$pageIdsStr}) AND CONTROLSTATUS = 1
                ORDER BY PAGEID, CONTROLORDER, CONTROLID
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $controlsByPage = [];
            foreach ($controls as $c) {
                $controlsByPage[$c['PAGEID']][] = $c;
            }

            $pageTriggers = $this->db->query("
                SELECT t.TRIGGER_ITEM_ID as PAGEID, t.TRIGGER_TYPE, t.TRIGGER_EVENT, t.TRIGGER_BL
                FROM FLC_TRIGGER t
                WHERE t.TRIGGER_ITEM_TYPE = 'page'
                AND t.TRIGGER_ITEM_ID IN ({$pageIdsStr})
                AND t.TRIGGER_STATUS = 1
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $trigsByPage = [];
            foreach ($pageTriggers as $trig) {
                $trigsByPage[$trig['PAGEID']][] = $trig;
            }

            $content = "# KERISI Workflow - {$module['MENUNAME']}\n\n";
            $content .= "This document describes the **flow of actions** within each page in the {$module['MENUNAME']} module.\n";
            $content .= "Use this to understand: what buttons exist, what happens when clicked, and the sequence of Business Logic (BL) execution.\n\n";
            $content .= "---\n\n";

            $pageCount = 0;
            foreach ($pages as $page) {
                $pid = $page['PAGEID'];
                $pageControls = $controlsByPage[$pid] ?? [];
                $pageTrigs = $trigsByPage[$pid] ?? [];

                if (empty($pageControls) && empty($pageTrigs)) {
                    continue;
                }

                $pageCount++;
                $content .= "## Page: {$page['PAGENAME']}\n";
                if ($page['PAGEBREADCRUMBS']) {
                    $content .= "**Path:** {$page['PAGEBREADCRUMBS']}\n";
                }
                $content .= "**Menu:** {$page['MENUNAME']}\n\n";

                if (! empty($pageTrigs)) {
                    $content .= "**On Load (Page Triggers):**\n";
                    foreach ($pageTrigs as $trig) {
                        $content .= "- [{$trig['TRIGGER_TYPE']}] `{$trig['TRIGGER_EVENT']}` → BL: `{$trig['TRIGGER_BL']}`\n";
                    }
                    $content .= "\n";
                }

                if (! empty($pageControls)) {
                    $content .= "**Controls (Buttons / Actions):**\n";
                    foreach ($pageControls as $ctrl) {
                        $typeLabel = self::CONTROL_TYPE_LABELS[$ctrl['CONTROLTYPE']] ?? "Type {$ctrl['CONTROLTYPE']}";
                        $content .= "- **{$ctrl['CONTROLTITLE']}** (`{$ctrl['CONTROLNAME']}`) [{$typeLabel}]";
                        if ($ctrl['CONTROLREDIRECTURL']) {
                            $content .= " → Redirect: `{$ctrl['CONTROLREDIRECTURL']}`";
                        }
                        $content .= "\n";

                        $trigs = $triggersByControl[$ctrl['CONTROLID']] ?? [];
                        foreach ($trigs as $trig) {
                            $content .= "  - on `{$trig['TRIGGER_EVENT']}` → BL: `{$trig['TRIGGER_BL']}`\n";
                        }
                    }
                    $content .= "\n";
                }

                $content .= "---\n\n";
            }

            if ($pageCount > 0) {
                $slug = 'kerisi-workflow-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $module['MENUNAME']));
                $files[] = [
                    'path' => $this->saveDocument($slug, 'KERISI Workflow: '.$module['MENUNAME'], $content, $module['MENUNAME']),
                    'workflow_count' => $pageCount,
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $files;
    }

    // ─── 6. RBAC (Role-Based Access Control) ────────────────────────────────────

    private function extractRbac(): array
    {
        $this->info('🔐 Extracting RBAC (role → menu/module access)...');

        $groups = $this->db->query('
            SELECT GROUP_ID, GROUP_CODE, DESCRIPTION
            FROM FLC_USER_GROUP
            ORDER BY GROUP_CODE
        ')->fetchAll(\PDO::FETCH_ASSOC);

        $allMenus = $this->db->query('SELECT MENUID, MENUNAME, MENUPARENT FROM FLC_MENU')->fetchAll(\PDO::FETCH_ASSOC);
        $menuById = [];
        foreach ($allMenus as $m) {
            $menuById[(int) $m['MENUID']] = $m;
        }

        $files = [];
        $bar = $this->output->createProgressBar(count($groups));
        $bar->start();

        foreach ($groups as $group) {
            $gid = (int) $group['GROUP_ID'];
            $code = $group['GROUP_CODE'];
            $desc = $group['DESCRIPTION'] ?? $code;

            $perms = $this->db->query("
                SELECT p.PERM_ITEM as MENUID, m.MENUNAME
                FROM FLC_PERMISSION p
                LEFT JOIN FLC_MENU m ON m.MENUID = p.PERM_ITEM
                WHERE p.GROUP_ID = {$gid} AND p.PERM_TYPE = 'menu'
                ORDER BY m.MENUNAME
            ")->fetchAll(\PDO::FETCH_ASSOC);

            $moduleNames = [];
            foreach ($perms as $p) {
                $mid = (int) $p['MENUID'];
                if ($mid) {
                    $mod = $this->resolveTopModule($mid, $menuById);
                    if ($mod && ! in_array($mod, $moduleNames)) {
                        $moduleNames[] = $mod;
                    }
                }
            }
            sort($moduleNames);

            $content = "# KERISI RBAC - Peranan: {$code}\n\n";
            $content .= "**Keterangan:** {$desc}\n\n";
            $content .= "Use this when users ask: \"sapa boleh akses modul X?\", \"user dalam group {$code} boleh guna apa?\", \"kenapa saya tak nampak menu Z?\".\n\n";
            $content .= "---\n\n";

            if (! empty($moduleNames)) {
                $content .= "**Modul yang boleh diakses:**\n";
                foreach ($moduleNames as $mod) {
                    $content .= "- {$mod}\n";
                }
                $content .= "\n";
            }

            $content .= '**Jumlah menu:** '.count($perms)."\n";
            if (count($perms) <= 80) {
                foreach ($perms as $p) {
                    $name = $p['MENUNAME'] ?? "ID {$p['MENUID']}";
                    $content .= "- {$name} (MENUID: {$p['MENUID']})\n";
                }
            } else {
                $content .= "- (Rujuk FLC_PERMISSION WHERE GROUP_ID = {$gid} AND PERM_TYPE = 'menu' untuk senarai penuh)\n";
            }

            $slug = 'kerisi-rbac-'.strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $code));
            $files[] = $this->saveDocument($slug, "KERISI RBAC: {$code}", $content, 'RBAC');
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $files;
    }

    private function resolveTopModule(int $menuId, array $menuById, array &$visited = []): ?string
    {
        if (isset($visited[$menuId])) {
            return null;
        }
        $visited[$menuId] = true;
        $m = $menuById[$menuId] ?? null;
        if (! $m) {
            return null;
        }
        $pid = (int) ($m['MENUPARENT'] ?? 0);
        if ($pid === 0 || $pid === $menuId) {
            return $m['MENUNAME'];
        }

        return $this->resolveTopModule($pid, $menuById, $visited) ?: $m['MENUNAME'];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function saveDocument(string $slug, string $name, string $content, string $module): string
    {
        $filename = $slug.'.md';
        $path = 'kerisi-knowledge/'.$filename;
        Storage::disk('local')->put($path, $content);

        $this->line("  ✅ {$name} (".number_format(strlen($content)).' chars)');

        return $path;
    }

    private function uploadToVectorStore(array $filePaths): void
    {
        $this->newLine();
        $this->info('⬆️  Uploading to OpenAI Vector Store (parallel, 8 concurrent)...');

        $items = [];
        foreach ($filePaths as $item) {
            $path = is_array($item) ? ($item['path'] ?? '') : $item;
            $blCount = is_array($item) ? ($item['bl_count'] ?? null) : null;
            $workflowCount = is_array($item) ? ($item['workflow_count'] ?? null) : null;
            $fullPath = Storage::disk('local')->path($path);
            $filename = basename($path);
            $items[] = [
                'path' => $fullPath,
                'filename' => $filename,
                'storage_path' => $path,
                'bl_count' => $blCount,
                'workflow_count' => $workflowCount,
            ];
        }

        $bar = $this->output->createProgressBar(count($items));
        $bar->start();

        $success = 0;
        $failed = 0;
        $concurrency = 8;

        foreach (array_chunk($items, $concurrency) as $chunk) {
            $results = $this->openAI->uploadFilesToVectorStoreBatch($chunk, $concurrency);

            foreach ($results as $result) {
                $filename = $result['filename'];
                $path = $result['storage_path'] ?? null;
                $blCount = $result['bl_count'] ?? null;
                $workflowCount = $result['workflow_count'] ?? null;

                if (isset($result['file_id'])) {
                    $fullPath = $result['path'];
                    $name = pathinfo($filename, PATHINFO_FILENAME);
                    $module = $this->filenameToModule($filename);
                    $notes = ($blCount !== null || $workflowCount !== null)
                        ? json_encode(array_filter(['bl_count' => $blCount, 'workflow_count' => $workflowCount]))
                        : null;

                    KnowledgeDocument::updateOrCreate(
                        ['original_filename' => $filename],
                        [
                            'name' => ucwords(str_replace(['-', '_'], ' ', $name)),
                            'file_path' => $path,
                            'file_type' => 'md',
                            'file_size' => filesize($fullPath),
                            'module' => $module,
                            'openai_file_id' => $result['file_id'],
                            'status' => 'uploaded',
                            'notes' => $notes,
                        ]
                    );
                    $success++;
                } else {
                    $failed++;
                    $this->newLine();
                    $this->warn("  ⚠️  Failed: {$filename} — ".($result['error'] ?? 'Unknown error'));
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ Uploaded: {$success} | ❌ Failed: {$failed}");
    }

    private function filenameToModule(string $filename): string
    {
        $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));

        if (str_contains($name, 'lookup-reference') || str_contains($name, 'kerisi-lookup')) {
            return 'Lookup / Reference';
        }
        if (str_contains($name, 'menu-access')) {
            return 'System Navigation';
        }

        if (str_contains($name, 'menu')) {
            return 'System Navigation';
        }
        if (str_contains($name, 'schema')) {
            return 'Database Schema';
        }
        // BL modules — check SPECIFIC names BEFORE generic 'bl-' (kerisi-bl-X)
        if (str_contains($name, 'account-payable') || str_contains($name, 'account_payable')) {
            return 'Account Payable';
        }
        if (str_contains($name, 'account-receivable')) {
            return 'Account Receivable';
        }
        if (str_contains($name, 'cashbook')) {
            return 'Cashbook';
        }
        if (str_contains($name, 'budget')) {
            return 'Budget';
        }
        if (str_contains($name, 'payroll')) {
            return 'Payroll';
        }
        if (str_contains($name, 'asset')) {
            return 'Asset';
        }
        if (str_contains($name, 'general-ledger') || str_contains($name, 'general_ledger')) {
            return 'General Ledger';
        }
        if (str_contains($name, 'purchasing')) {
            return 'Purchasing';
        }
        if (str_contains($name, 'loan')) {
            return 'Loan';
        }
        if (str_contains($name, 'invest')) {
            return 'Investment';
        }
        if (str_contains($name, 'credit-control')) {
            return 'Credit Control';
        }
        if (str_contains($name, 'staff-portal')) {
            return 'Staff Portal';
        }
        if (str_contains($name, 'vendor-portal')) {
            return 'Vendor Portal';
        }
        if (str_contains($name, 'debtor-portal')) {
            return 'Debtor Portal';
        }
        if (str_contains($name, 'student-finance')) {
            return 'Student Finance';
        }
        if (str_contains($name, 'travel-claim')) {
            return 'Travel Claim';
        }
        if (str_contains($name, 'advance')) {
            return 'Advance';
        }
        if (str_contains($name, 'reports')) {
            return 'Reports';
        }
        if (str_contains($name, 'setup') || str_contains($name, 'maintenance')) {
            return 'Setup & Maintenance';
        }
        if (str_contains($name, 'authentication') || str_contains($name, 'auth')) {
            return 'Authentication';
        }
        if (str_contains($name, 'user-management')) {
            return 'User Management';
        }
        if (str_contains($name, 'bl-')) {
            return 'Business Logic';
        } // fallback
        if (str_contains($name, 'workflow-')) {
            return $this->workflowFilenameToModule($filename);
        }
        if (str_contains($name, 'rbac')) {
            return 'RBAC';
        }

        return 'System Knowledge';
    }

    private function workflowFilenameToModule(string $filename): string
    {
        $name = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if (! str_contains($name, 'workflow-')) {
            return 'Workflow';
        }
        $suffix = substr($name, strpos($name, 'workflow-') + 9);

        return ucwords(str_replace('-', ' ', $suffix));
    }

    private function ensureTunnel(): void
    {
        $this->info('🔗 Checking SSH tunnel...');
        $host = env('MYFIS_BASTION_HOST');
        $user = env('MYFIS_BASTION_USER', 'ec2-user');
        $key = env('MYFIS_BASTION_KEY');
        $dbHost = env('MYFIS_DB_INTERNAL_HOST');
        $port = env('MYFIS_DB_PORT', '3307');

        // Check if tunnel already active
        $check = shell_exec("lsof -i :{$port} 2>/dev/null | grep LISTEN");
        if ($check) {
            $this->info('✅ Tunnel already active');

            return;
        }

        $cmd = "ssh -i {$key} -o StrictHostKeyChecking=no -f -N -L {$port}:{$dbHost}:3306 {$user}@{$host} 2>&1";
        shell_exec($cmd);
        sleep(2);
        $this->info('✅ Tunnel established');
    }
}
