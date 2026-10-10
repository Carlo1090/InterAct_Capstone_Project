<?php

namespace Tests\Feature\Coordinator;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\GroupInfoSheet;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\StaticMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The GROUP sheet's "Sketch of Internship Company Location": the interns'
 * majority pin by default, the coordinator's choice when they make one.
 */
class GroupInfoSheetLocationTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 9.6475;

    private const LNG = 123.8540;

    private const YEAR = '2026-2027';

    /** Metres per degree of latitude at GeoDistance's Earth radius. */
    private const METRES_PER_DEGREE = 111194.93;

    private User $coordinator;

    private Batch $batch;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $department = Department::create(['code' => 'CABM-B', 'name' => 'Business Department', 'is_active' => true]);
        $program = Program::create(['department_id' => $department->id, 'code' => 'BSBA-FM', 'name' => 'BSBA-FM Program', 'is_active' => true]);

        $this->coordinator = User::factory()->create(['role' => 'coordinator']);
        $this->coordinator->departmentsCoordinated()->attach($department->id);

        $this->batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $this->coordinator->id,
            'name' => 'BSBA-FM 2026',
            'start_date' => '2026-08-12',
            'end_date' => '2026-10-18',
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'academic_year' => self::YEAR,
            'semester' => 'Internship',
            'is_active' => true,
        ]);

        $this->company = Company::create(['name' => 'Tagbilaran Cooperative Bank', 'address' => 'CPG Avenue', 'is_active' => true]);
    }

    /**
     * An intern at the company whose own information sheet pins it $metres
     * due north of the base point — or carries no pin at all when null.
     */
    private function intern(string $name, ?float $metres, ?int $sheetCompanyId = null): BatchStudent
    {
        $student = User::factory()->create(['role' => 'student', 'name' => $name]);

        $ojt = ['company_id' => $sheetCompanyId ?? $this->company->id, 'host_company' => $this->company->name];

        if ($metres !== null) {
            $ojt += [
                'location_lat' => self::LAT + $metres / self::METRES_PER_DEGREE,
                'location_lng' => self::LNG,
                'location_zoom' => 17,
                'location_label' => $name.' pin',
            ];
        }

        StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $this->batch->id,
            'personal_info' => ['last_name' => $name, 'first_name' => $name, 'contact_number' => '09171234567'],
            'academic_info' => ['year_level' => '4th-year'],
            'ojt_info' => $ojt,
            'submission_status' => 'approved',
        ]);

        return BatchStudent::create([
            'batch_id' => $this->batch->id,
            'student_id' => $student->id,
            'company_id' => $this->company->id,
            'supervisor_id' => User::factory()->create(['role' => 'supervisor'])->id,
            'status' => 'active',
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/coordinator/group-info-sheets/{$this->company->id}/".self::YEAR.$suffix;
    }

    private function save(?array $location): TestResponse
    {
        return $this->postJson($this->url(), [
            'status' => 'draft',
            'company' => [],
            'rows' => [],
            'location' => $location,
        ]);
    }

    private function fakeTiles(int $status = 200): void
    {
        Storage::fake('local');
        config(['staticmap.enabled' => true, 'staticmap.tile_url' => 'https://tiles.test/{z}/{x}/{y}.png']);

        $tile = imagecreatetruecolor(StaticMapService::TILE_SIZE, StaticMapService::TILE_SIZE);
        imagefill($tile, 0, 0, imagecolorallocate($tile, 214, 222, 205));
        ob_start();
        imagepng($tile);
        $png = (string) ob_get_clean();

        Http::fake(['tiles.test/*' => $status === 200 ? Http::response($png, 200) : Http::response('down', $status)]);
    }

    public function test_the_sketch_defaults_to_the_pin_most_interns_agree_on(): void
    {
        // The stray pin's sheet is the OLDEST, so it would win any tie-break —
        // it loses on agreement alone.
        $this->intern('Stray', 1500);
        $gate = $this->intern('Gate', 0);
        $office = $this->intern('Office', 40);
        $this->intern('Unpinned', null);

        Sanctum::actingAs($this->coordinator);

        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('location.source', 'majority')
            ->assertJsonPath('location.chosen', false)
            ->assertJsonPath('location.contested', false)
            ->assertJsonPath('location.enrollment_id', $gate->id)
            ->assertJsonPath('location.agree_count', 2)
            ->assertJsonPath('location.pinned_count', 3)
            ->assertJsonPath('location.label', 'Gate pin')
            ->assertJsonPath('unpinned_count', 1);

        $this->assertEqualsWithDelta(self::LAT, $response->json('location.lat'), 1e-9);

        $pins = collect($response->json('intern_pins'))->keyBy('name');
        $this->assertCount(3, $pins);
        $this->assertTrue($pins['Gate']['agrees']);
        $this->assertTrue($pins['Office']['agrees']);
        $this->assertSame($office->id, $pins['Office']['enrollment_id']);
        $this->assertSame(40, $pins['Office']['distance_m']);
        $this->assertFalse($pins['Stray']['agrees']);
        $this->assertSame(1500, $pins['Stray']['distance_m']);
    }

    public function test_an_excluded_intern_and_a_pin_for_another_company_do_not_vote(): void
    {
        $this->intern('Kept', 0);
        $excluded = $this->intern('Excluded', 2000);
        $this->intern('Moved', 2010, sheetCompanyId: Company::create(['name' => 'Elsewhere', 'address' => 'X', 'is_active' => true])->id);

        // Without these two rules, the two pins 2 km away would outvote Kept.
        GroupInfoSheet::create([
            'coordinator_id' => $this->coordinator->id,
            'company_id' => $this->company->id,
            'academic_year' => self::YEAR,
            'sheet_data' => ['rows' => [['id' => $excluded->id, 'included' => false]]],
            'status' => 'draft',
        ]);

        Sanctum::actingAs($this->coordinator);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('location.label', 'Kept pin')
            ->assertJsonPath('location.pinned_count', 1)
            ->assertJsonPath('intern_pins.0.name', 'Kept')
            ->assertJsonCount(1, 'intern_pins');
    }

    public function test_interns_who_disagree_still_get_a_best_guess_but_it_is_flagged(): void
    {
        $this->intern('First', 0);
        $this->intern('Second', 2000);

        Sanctum::actingAs($this->coordinator);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('location.label', 'First pin')
            ->assertJsonPath('location.contested', true)
            ->assertJsonPath('location.agree_count', 1)
            ->assertJsonPath('location.pinned_count', 2);
    }

    public function test_no_pins_means_no_location(): void
    {
        $this->intern('Nobody', null);

        Sanctum::actingAs($this->coordinator);

        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('location', null)
            ->assertJsonPath('intern_pins', [])
            ->assertJsonPath('unpinned_count', 1);
    }

    public function test_a_saved_choice_overrides_the_majority_until_it_is_reset(): void
    {
        $this->intern('Gate', 0);
        $this->intern('Office', 40);

        Sanctum::actingAs($this->coordinator);

        $ownPin = ['lat' => 9.65, 'lng' => 123.86, 'zoom' => 18, 'label' => 'Main entrance', 'source' => 'coordinator'];

        $this->save($ownPin)->assertOk()
            ->assertJsonPath('location.source', 'coordinator')
            ->assertJsonPath('location.chosen', true)
            ->assertJsonPath('location.contested', false)
            ->assertJsonPath('location.zoom', 18)
            ->assertJsonPath('location.label', 'Main entrance')
            // Both interns pinned well over 150 m from the coordinator's point.
            ->assertJsonPath('location.agree_count', 0)
            ->assertJsonPath('location.pinned_count', 2)
            // The automatic pick still travels, so the page can offer a reset.
            ->assertJsonPath('majority.label', 'Gate pin')
            ->assertJsonPath('majority.agree_count', 2);

        // It is stored, not echoed: a fresh load still has it.
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('location.source', 'coordinator')
            ->assertJsonPath('location.lat', 9.65);

        // Null goes back to following the interns.
        $this->save(null)->assertOk()
            ->assertJsonPath('location.source', 'majority')
            ->assertJsonPath('location.chosen', false)
            ->assertJsonPath('location.label', 'Gate pin');
    }

    public function test_choosing_an_interns_pin_records_whose_it_is(): void
    {
        $this->intern('Gate', 0);
        $stray = $this->intern('Stray', 1500);

        Sanctum::actingAs($this->coordinator);

        $this->save([
            'lat' => self::LAT + 1500 / self::METRES_PER_DEGREE,
            'lng' => self::LNG,
            'zoom' => 17,
            'label' => 'Stray pin',
            'source' => 'intern',
            'enrollment_id' => $stray->id,
        ])->assertOk()
            ->assertJsonPath('location.source', 'intern')
            ->assertJsonPath('location.enrollment_id', $stray->id)
            ->assertJsonPath('location.agree_count', 1);
    }

    public function test_an_impossible_location_is_refused(): void
    {
        $this->intern('Gate', 0);

        Sanctum::actingAs($this->coordinator);

        $this->save(['lat' => 95, 'lng' => 123.86, 'source' => 'coordinator'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location.lat']);

        $this->save(['lat' => 9.65, 'lng' => 123.86, 'source' => 'someone'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location.source']);
    }

    public function test_the_pdf_prints_the_map_and_a_full_roster_still_fits_one_page(): void
    {
        $this->fakeTiles();

        for ($i = 0; $i < 12; $i++) {
            $this->intern('Intern '.$i, $i * 5);
        }

        Sanctum::actingAs($this->coordinator);

        $withMap = $this->get($this->url('/pdf'))->assertOk()->getContent();

        $this->assertGreaterThan(0, count(Http::recorded()));
        preg_match_all('~/Count (\d+)~', $withMap, $matches);
        $this->assertSame(1, max(array_map('intval', $matches[1])), 'a 12-intern roster plus the map must stay on one page');

        // The map really is in the file rather than silently dropped.
        config(['staticmap.enabled' => false]);
        $withoutMap = $this->get($this->url('/pdf'))->assertOk()->getContent();
        $this->assertGreaterThan(strlen($withoutMap) + 5000, strlen($withMap));
    }

    public function test_the_pdf_still_downloads_when_the_tile_server_is_down(): void
    {
        $this->fakeTiles(503);
        $this->intern('Gate', 0);

        Sanctum::actingAs($this->coordinator);

        $this->get($this->url('/pdf'))->assertOk();
    }

    public function test_the_coordinator_preview_is_drawn_at_the_group_box_shape(): void
    {
        $this->fakeTiles();

        Sanctum::actingAs($this->coordinator);

        // Width and height in the query are ignored — fixed server-side.
        $response = $this->get('/api/coordinator/location-preview?lat=9.6475&lng=123.854&zoom=17&width=4000&height=4000');
        $response->assertOk()->assertHeader('Content-Type', 'image/png');

        $size = getimagesizefromstring($response->getContent());
        $this->assertSame([640, 234], [$size[0], $size[1]]);

        $this->get('/api/coordinator/location-preview')->assertNotFound();
        $this->getJson('/api/coordinator/location-options')->assertOk()->assertJsonStructure(['tile_url', 'attribution', 'default_center']);
    }

    public function test_each_role_reaches_only_its_own_map_routes(): void
    {
        $student = $this->intern('Gate', 0)->student;

        Sanctum::actingAs($student);
        $this->getJson('/api/coordinator/location-options')->assertForbidden();

        Sanctum::actingAs($this->coordinator);
        $this->getJson('/api/student/location-options')->assertForbidden();
    }
}
