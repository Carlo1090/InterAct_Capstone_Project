<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_creating_a_user_produces_a_log_entry(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson('/api/admin/users', [
            'name' => 'New Student',
            'email' => 'new.student@example.test',
            'password' => 'a-strong-password',
            'role' => 'student',
        ])->assertCreated();

        $this->assertDatabaseHas('system_logs', ['action' => 'User Created']);
    }

    public function test_deactivating_a_user_produces_a_log_entry(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $target = User::factory()->create(['role' => 'student', 'name' => 'Target Student']);

        $this->patchJson("/api/admin/users/{$target->id}/deactivate")->assertOk();

        $this->assertDatabaseHas('system_logs', [
            'action' => 'User Deactivated',
            'description' => 'Deactivated account for Target Student',
        ]);
    }

    public function test_updating_a_company_produces_a_log_entry(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $company = Company::create(['name' => 'Old Name', 'address' => 'Old Address', 'is_active' => true]);

        $this->putJson("/api/admin/companies/{$company->id}", ['name' => 'New Name'])->assertOk();

        $this->assertDatabaseHas('system_logs', ['action' => 'Company Updated']);
    }

    public function test_student_password_change_produces_a_log_entry(): void
    {
        $student = User::factory()->create(['role' => 'student', 'name' => 'Password Student']);
        Sanctum::actingAs($student, ['*']);

        $this->putJson('/api/student/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertOk();

        $this->assertDatabaseHas('system_logs', [
            'action' => 'Password Changed',
            'description' => 'Password Student changed their password',
        ]);
    }

    public function test_index_filters_by_action_role_date_and_search(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);

        $company = Company::create(['name' => 'Acme Corp', 'address' => '123 Main St', 'is_active' => true]);
        $this->putJson("/api/admin/companies/{$company->id}", ['name' => 'Acme Corp Updated'])->assertOk();

        $student = User::factory()->create(['role' => 'student', 'name' => 'Filter Student']);
        Sanctum::actingAs($student, ['*']);
        $this->putJson('/api/student/password', [
            'current_password' => 'password',
            'password' => 'a-new-password-123',
            'password_confirmation' => 'a-new-password-123',
        ])->assertOk();

        Sanctum::actingAs($admin, ['*']);

        $byAction = $this->getJson('/api/admin/audit-logs?action=Company Updated');
        $byAction->assertOk();
        $this->assertCount(1, $byAction->json('data'));
        $this->assertSame('Company Updated', $byAction->json('data.0.action'));

        $byRole = $this->getJson('/api/admin/audit-logs?role=student');
        $byRole->assertOk();
        $actions = collect($byRole->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('Password Changed'));
        $this->assertFalse($actions->contains('Company Updated'));

        $bySearch = $this->getJson('/api/admin/audit-logs?search=Acme');
        $bySearch->assertOk();
        $this->assertCount(1, $bySearch->json('data'));

        $todayDate = now()->toDateString();
        $byDate = $this->getJson("/api/admin/audit-logs?date={$todayDate}");
        $byDate->assertOk();
        $this->assertGreaterThanOrEqual(2, count($byDate->json('data')));
    }

    public function test_actions_endpoint_returns_a_distinct_sorted_list(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson('/api/admin/companies', ['name' => 'Company One', 'address' => 'Address One'])->assertCreated();
        $companyTwo = Company::create(['name' => 'Company Two', 'address' => 'Address Two', 'is_active' => true]);
        $this->putJson("/api/admin/companies/{$companyTwo->id}", ['name' => 'Company Two Updated'])->assertOk();

        $response = $this->getJson('/api/admin/audit-logs/actions');

        $response->assertOk();
        $actions = $response->json();
        $this->assertSame(array_values($actions), array_unique($actions));
        $this->assertContains('Company Created', $actions);
        $this->assertContains('Company Updated', $actions);
    }

    public function test_export_returns_csv_with_matching_rows(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $company = Company::create(['name' => 'Exportable Co', 'address' => '456 Side St', 'is_active' => true]);
        $this->putJson("/api/admin/companies/{$company->id}", ['name' => 'Exportable Co Updated'])->assertOk();

        $response = $this->get('/api/admin/audit-logs/export?action=Company Updated');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Timestamp', $content);
        $this->assertStringContainsString('IP Address', $content);
        $this->assertStringContainsString('Company Updated', $content);
        $this->assertStringNotContainsString('Company Created', $content);
    }

    public function test_non_admin_cannot_access_audit_log_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'student']), ['*']);

        $this->getJson('/api/admin/audit-logs')->assertStatus(403);
        $this->getJson('/api/admin/audit-logs/actions')->assertStatus(403);
        $this->getJson('/api/admin/audit-logs/export')->assertStatus(403);
    }
}
