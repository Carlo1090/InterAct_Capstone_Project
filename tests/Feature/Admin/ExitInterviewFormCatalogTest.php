<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\User;
use App\Support\ExitInterview\ExitInterviewForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin → Exit Interview: the read-only catalogue of hardcoded forms, and the
 * assignment of one to each department on the Departments page.
 */
class ExitInterviewFormCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_the_catalogue_lists_every_hardcoded_form_with_its_questions_and_departments(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        Department::create(['code' => 'CAST', 'name' => 'CAST', 'is_active' => true, 'exit_interview_form' => 'cast']);
        Department::create(['code' => 'CABM-B', 'name' => 'CABM-B', 'is_active' => true, 'exit_interview_form' => 'cabm']);
        Department::create(['code' => 'CABM-H', 'name' => 'CABM-H', 'is_active' => true, 'exit_interview_form' => 'cabm']);

        $response = $this->getJson('/api/admin/exit-interview-forms')->assertOk();

        $response->assertJsonPath('default', 'cabm');
        $response->assertJsonCount(count(ExitInterviewForms::keys()), 'forms');

        $forms = collect($response->json('forms'))->keyBy('key');

        $this->assertSame('Internship Program Student Exit Interview Form (CABM)', $forms['cabm']['label']);
        $this->assertSame(14, $forms['cabm']['question_count']);
        $this->assertSame(2, $forms['cabm']['pages']);
        $this->assertSame(['CABM-B', 'CABM-H'], array_column($forms['cabm']['departments'], 'code'));

        $this->assertSame('Exit Interview Questionnaire for On-the-Job (OJT) Students (CAST)', $forms['cast']['label']);
        $this->assertSame(31, $forms['cast']['question_count']);
        $this->assertSame(['CAST'], array_column($forms['cast']['departments'], 'code'));
        $this->assertSame('A. OJT Experience', $forms['cast']['sections'][0]['heading']);
        $this->assertSame('scale', $forms['cast']['sections'][4]['questions'][5]['type']);
    }

    public function test_only_an_admin_can_read_the_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'coordinator']), ['*']);

        $this->getJson('/api/admin/exit-interview-forms')->assertForbidden();
    }

    public function test_creating_a_department_requires_choosing_a_form(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $this->postJson('/api/admin/departments', [
            'code' => 'CON',
            'name' => 'College of Nursing',
        ])->assertUnprocessable()->assertJsonValidationErrors(['exit_interview_form']);

        $this->postJson('/api/admin/departments', [
            'code' => 'CON',
            'name' => 'College of Nursing',
            'exit_interview_form' => 'not-a-form',
        ])->assertUnprocessable()->assertJsonValidationErrors(['exit_interview_form']);

        $this->postJson('/api/admin/departments', [
            'code' => 'CON',
            'name' => 'College of Nursing',
            'exit_interview_form' => 'cast',
        ])->assertCreated()->assertJsonPath('exit_interview_form', 'cast');

        $this->assertSame('cast', Department::where('code', 'CON')->sole()->exit_interview_form);
    }

    public function test_a_departments_form_can_be_changed_on_edit(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $department = Department::create(['code' => 'CAST', 'name' => 'CAST', 'is_active' => true, 'exit_interview_form' => 'cabm']);

        $this->putJson("/api/admin/departments/{$department->id}", [
            'name' => 'College of Arts, Sciences and Technology',
            'exit_interview_form' => 'cast',
        ])->assertOk()->assertJsonPath('exit_interview_form', 'cast');

        // And an edit that does not mention it leaves it alone.
        $this->putJson("/api/admin/departments/{$department->id}", [
            'name' => 'College of Arts, Sciences and Technology',
            'dean_name' => 'Dr. Reyes',
        ])->assertOk()->assertJsonPath('exit_interview_form', 'cast');
    }

    public function test_the_department_list_carries_each_departments_form(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        Department::create(['code' => 'CAST', 'name' => 'CAST', 'is_active' => true, 'exit_interview_form' => 'cast']);

        $this->getJson('/api/admin/departments')
            ->assertOk()
            ->assertJsonPath('0.exit_interview_form', 'cast');
    }

    /**
     * An unknown key — a form removed from the catalogue after a department
     * was pointed at it — resolves to the default rather than throwing, so an
     * old interview stays downloadable.
     */
    public function test_an_unknown_form_key_falls_back_to_the_default(): void
    {
        $this->assertSame('cabm', ExitInterviewForms::get('gone')->key);
        $this->assertSame('cabm', ExitInterviewForms::get(null)->key);
        $this->assertFalse(ExitInterviewForms::has('gone'));
    }
}
