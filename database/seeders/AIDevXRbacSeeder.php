<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class AIDevXRbacSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'super_admin',
                'description' => 'Highest access level for system and developer governance',
                'permissions' => [
                    'acc.project.view', 'acc.project.manage', 'acc.user.manage', 'acc.role.mapping.manage',
                    'kb.document.view', 'kb.document.upload', 'kb.document.edit', 'kb.document.approve', 'kb.document.archive', 'kb.context.retrieve',
                    'tpl.template.view', 'tpl.template.manage', 'tpl.checklist.run', 'tpl.threshold.manage',
                    'doc.draft.create', 'doc.section.edit', 'doc.section.regenerate', 'doc.version.view', 'doc.approve', 'doc.baseline.freeze',
                    'col.module.assign', 'col.section.lock', 'col.merge.execute', 'col.audit.view',
                    'cmp.krisa.run', 'cmp.ivv.run', 'cmp.result.view', 'cmp.override.approve',
                    'trc.matrix.view', 'trc.matrix.generate', 'trc.impact.analyze', 'trc.matrix.export',
                    'wrd.docx.generate', 'wrd.docx.export',
                    'pro.stack.select', 'pro.repo.new', 'pro.repo.existing', 'pro.prototype.generate',
                ],
            ],
            [
                'name' => 'admin',
                'description' => 'Full system access and governance override authority',
                'permissions' => [
                    'acc.project.view', 'acc.project.manage', 'acc.user.manage', 'acc.role.mapping.manage',
                    'kb.document.view', 'kb.document.upload', 'kb.document.edit', 'kb.document.approve', 'kb.document.archive', 'kb.context.retrieve',
                    'tpl.template.view', 'tpl.template.manage', 'tpl.checklist.run', 'tpl.threshold.manage',
                    'doc.draft.create', 'doc.section.edit', 'doc.section.regenerate', 'doc.version.view', 'doc.approve', 'doc.baseline.freeze',
                    'col.module.assign', 'col.section.lock', 'col.merge.execute', 'col.audit.view',
                    'cmp.krisa.run', 'cmp.ivv.run', 'cmp.result.view', 'cmp.override.approve',
                    'trc.matrix.view', 'trc.matrix.generate', 'trc.impact.analyze', 'trc.matrix.export',
                    'wrd.docx.generate', 'wrd.docx.export',
                    'pro.stack.select', 'pro.repo.new', 'pro.repo.existing', 'pro.prototype.generate',
                ],
            ],
            [
                'name' => 'project_manager',
                'description' => 'Program governance and approval visibility',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'tpl.template.view', 'doc.version.view',
                    'cmp.result.view', 'cmp.override.approve',
                    'trc.matrix.view', 'trc.matrix.generate', 'trc.matrix.export',
                    'wrd.docx.export',
                ],
            ],
            [
                'name' => 'project_leader_dev_lead',
                'description' => 'Technical leadership and prototype approval',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'tpl.template.view', 'doc.version.view',
                    'trc.matrix.view', 'trc.matrix.generate', 'trc.impact.analyze',
                    'pro.stack.select', 'pro.repo.new', 'pro.repo.existing', 'pro.prototype.generate',
                ],
            ],
            [
                'name' => 'team_lead_lead_ba',
                'description' => 'Primary documentation owner and baseline approver',
                'permissions' => [
                    'acc.project.view',
                    'kb.document.view', 'kb.document.upload', 'kb.document.edit',
                    'tpl.template.view', 'tpl.checklist.run',
                    'doc.draft.create', 'doc.section.edit', 'doc.section.regenerate', 'doc.version.view', 'doc.approve', 'doc.baseline.freeze',
                    'col.module.assign', 'col.section.lock', 'col.merge.execute', 'col.audit.view',
                    'cmp.krisa.run', 'cmp.ivv.run', 'cmp.result.view', 'cmp.override.approve',
                    'trc.matrix.view', 'trc.matrix.generate', 'trc.impact.analyze',
                    'wrd.docx.generate', 'wrd.docx.export',
                ],
            ],
            [
                'name' => 'lead_tester',
                'description' => 'QA lead with compliance and traceability focus',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'tpl.template.view', 'doc.version.view',
                    'cmp.krisa.run', 'cmp.ivv.run', 'cmp.result.view',
                    'trc.matrix.view', 'trc.matrix.generate', 'trc.impact.analyze',
                ],
            ],
            [
                'name' => 'lead_developer',
                'description' => 'Implementation lead for downstream technical outputs',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'tpl.template.view', 'doc.version.view',
                    'trc.matrix.view', 'trc.impact.analyze',
                    'pro.stack.select', 'pro.repo.new', 'pro.repo.existing', 'pro.prototype.generate',
                ],
            ],
            [
                'name' => 'ba',
                'description' => 'Main author for project documentation',
                'permissions' => [
                    'acc.project.view',
                    'kb.document.view', 'kb.document.upload', 'kb.document.edit', 'kb.context.retrieve',
                    'tpl.template.view', 'tpl.checklist.run',
                    'doc.draft.create', 'doc.section.edit', 'doc.section.regenerate', 'doc.version.view',
                    'col.section.lock', 'cmp.result.view',
                    'trc.matrix.view', 'trc.matrix.generate',
                    'wrd.docx.generate',
                ],
            ],
            [
                'name' => 'assistant_ba',
                'description' => 'Supports BA authoring and preparation tasks',
                'permissions' => [
                    'acc.project.view',
                    'kb.document.view', 'kb.document.upload', 'kb.document.edit', 'kb.context.retrieve',
                    'tpl.template.view', 'tpl.checklist.run',
                    'doc.draft.create', 'doc.section.edit', 'doc.version.view',
                    'col.section.lock', 'cmp.result.view', 'trc.matrix.view',
                ],
            ],
            [
                'name' => 'tester',
                'description' => 'Execution-level QA and traceability verification',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'tpl.template.view', 'doc.version.view',
                    'cmp.krisa.run', 'cmp.ivv.run', 'cmp.result.view',
                    'trc.matrix.view', 'trc.matrix.generate',
                ],
            ],
            [
                'name' => 'developer',
                'description' => 'Execution-level development and prototype run',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'tpl.template.view', 'doc.version.view',
                    'cmp.result.view', 'trc.matrix.view', 'trc.impact.analyze',
                    'pro.stack.select', 'pro.repo.new', 'pro.repo.existing', 'pro.prototype.generate',
                ],
            ],
            [
                'name' => 'implementor',
                'description' => 'Deployment/implementation support role',
                'permissions' => [
                    'acc.project.view', 'kb.document.view', 'doc.version.view',
                    'cmp.result.view', 'trc.matrix.view',
                    'wrd.docx.export',
                    'pro.repo.existing', 'pro.prototype.generate',
                ],
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                [
                    'description' => $role['description'],
                    'permissions' => $role['permissions'],
                ]
            );
        }
    }
}
