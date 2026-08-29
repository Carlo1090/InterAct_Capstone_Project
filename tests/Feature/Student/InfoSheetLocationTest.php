<?php

namespace Tests\Feature\Student;

use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\StaticMapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The pinned company location behind the information sheet's "Sketch of
 * Internship Company Location" box.
 */
class InfoSheetLocationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: StudentInformationSheet} */
    private function studentWithSheet(array $ojt = []): array
    {
        $department = Department::firstOrCreate(['code' => 'CABM-B'], ['name' => 'CABM-B', 'is_active' => true]);
        $program = Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSA'],
            ['name' => 'BS Accountancy', 'is_active' => true]
        );
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'BSA 2026 '.uniqid(),
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);
        $sheet = StudentInformationSheet::create([
            'student_id' => $student->id,
            'batch_id' => $batch->id,
            'submission_status' => 'draft',
            'personal_info' => ['last_name' => 'Pabalan', 'first_name' => 'Marlon', 'parent_guardian_name' => 'Nenita Pabalan'],
            'academic_info' => [],
            'ojt_info' => $ojt,
            'emergency_contact' => null,
        ]);

        return [$student, $sheet];
    }

    private function payload(array $ojt): array
    {
        return [
            'status' => 'draft',
            'personal_info' => ['last_name' => 'Pabalan', 'first_name' => 'Marlon'],
            'academic_info' => [],
            'ojt_info' => $ojt,
        ];
    }

    private function fakeTile(): string
    {
        $tile = imagecreatetruecolor(StaticMapService::TILE_SIZE, StaticMapService::TILE_SIZE);
        imagefill($tile, 0, 0, imagecolorallocate($tile, 214, 222, 205));

        ob_start();
        imagepng($tile);

        return (string) ob_get_clean();
    }

    public function test_a_student_can_pin_their_company_location(): void
    {
        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/info-sheet', $this->payload([
            'host_company' => 'Bohol Quality Corporation',
            'location_lat' => 9.6419184,
            'location_lng' => 123.855035,
            'location_zoom' => 17,
            'location_label' => 'BQ Mall, Tagbilaran',
        ]))->assertOk();

        $ojt = StudentInformationSheet::where('student_id', $student->id)->first()->ojt_info;

        $this->assertEqualsWithDelta(9.6419184, $ojt['location_lat'], 0.0000001);
        $this->assertEqualsWithDelta(123.855035, $ojt['location_lng'], 0.0000001);
        $this->assertSame(17, $ojt['location_zoom']);
        $this->assertSame('BQ Mall, Tagbilaran', $ojt['location_label']);
    }

    public function test_an_impossible_coordinate_is_refused(): void
    {
        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        $this->postJson('/api/student/info-sheet', $this->payload([
            'location_lat' => 120,
            'location_lng' => 500,
            'location_zoom' => 40,
        ]))->assertStatus(422)->assertJsonValidationErrors([
            'ojt_info.location_lat',
            'ojt_info.location_lng',
            'ojt_info.location_zoom',
        ]);
    }

    public function test_half_a_coordinate_is_refused(): void
    {
        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        // A latitude with no longitude names a point on a line, not a place.
        $this->postJson('/api/student/info-sheet', $this->payload([
            'location_lat' => 9.6419184,
        ]))->assertStatus(422)->assertJsonValidationErrors(['ojt_info.location_lng']);
    }

    public function test_the_sheet_still_submits_with_no_pin_at_all(): void
    {
        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        // The map is never a gate: the paper form's box has always been
        // optional, and a student who cannot get a location fix must still be
        // able to hand their sheet in.
        $this->postJson('/api/student/info-sheet', [
            'status' => 'submitted',
            'personal_info' => [
                'last_name' => 'Pabalan',
                'first_name' => 'Marlon',
                'parent_guardian_name' => 'Nenita Pabalan',
            ],
            'academic_info' => [],
            'ojt_info' => ['host_company' => 'Bohol Quality Corporation'],
        ])->assertOk();

        $this->assertSame(
            'submitted',
            StudentInformationSheet::where('student_id', $student->id)->first()->submission_status
        );
    }

    public function test_the_pdf_embeds_the_pinned_map(): void
    {
        Storage::fake('local');
        config(['staticmap.enabled' => true, 'staticmap.tile_url' => 'https://tiles.test/{z}/{x}/{y}.png']);
        Http::fake(['tiles.test/*' => Http::response($this->fakeTile(), 200)]);

        [$student] = $this->studentWithSheet([
            'host_company' => 'Bohol Quality Corporation',
            'location_lat' => 9.6419184,
            'location_lng' => 123.855035,
            'location_zoom' => 17,
        ]);
        Sanctum::actingAs($student);

        $response = $this->get('/api/student/info-sheet/pdf');
        $response->assertOk();

        $withMap = strlen($response->getContent());

        // Tiles were actually fetched and composed into the document.
        $this->assertGreaterThan(0, count(Http::recorded()));

        // The same sheet without a pin is materially smaller — the map really
        // is in the file rather than being silently dropped.
        StudentInformationSheet::where('student_id', $student->id)->first()->update(['ojt_info' => []]);
        $withoutMap = strlen($this->get('/api/student/info-sheet/pdf')->getContent());

        $this->assertGreaterThan($withoutMap + 5000, $withMap);
    }

    public function test_the_pdf_still_downloads_when_the_tile_server_is_down(): void
    {
        Storage::fake('local');
        config(['staticmap.enabled' => true, 'staticmap.tile_url' => 'https://tiles.test/{z}/{x}/{y}.png']);
        Http::fake(['tiles.test/*' => Http::response('down', 503)]);

        [$student] = $this->studentWithSheet([
            'location_lat' => 9.6419184,
            'location_lng' => 123.855035,
            'location_zoom' => 17,
        ]);
        Sanctum::actingAs($student);

        // The whole point of the best-effort design: a student must never be
        // unable to download their own information sheet because somebody
        // else's tile server was having a bad day.
        $this->get('/api/student/info-sheet/pdf')->assertOk();
    }

    public function test_the_collapsed_preview_is_a_cached_png_at_the_printed_aspect(): void
    {
        Storage::fake('local');
        config(['staticmap.enabled' => true, 'staticmap.tile_url' => 'https://tiles.test/{z}/{x}/{y}.png']);
        Http::fake(['tiles.test/*' => Http::response($this->fakeTile(), 200)]);

        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        $response = $this->get('/api/student/location-preview?lat=9.6419184&lng=123.855035&zoom=17&width=4000&height=4000');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('max-age=86400', $response->headers->get('Cache-Control'));

        // The canvas size is fixed SERVER-SIDE: the width/height in the query
        // above are ignored, because a caller-controlled canvas is a way to
        // make us fetch arbitrarily many tiles from a free service.
        $size = getimagesizefromstring($response->getContent());
        $this->assertSame(StaticMapService::PREVIEW_WIDTH, $size[0]);
        $this->assertSame(StaticMapService::PREVIEW_HEIGHT, $size[1]);
    }

    public function test_the_preview_404s_rather_than_returning_a_placeholder(): void
    {
        Storage::fake('local');
        config(['staticmap.enabled' => true, 'staticmap.tile_url' => 'https://tiles.test/{z}/{x}/{y}.png']);
        Http::fake(['tiles.test/*' => Http::response('down', 503)]);

        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        // No pin at all.
        $this->get('/api/student/location-preview')->assertNotFound();

        // A pin whose tiles cannot be fetched. A 404 lets the <img> fail and
        // the form fall back to plain coordinates, instead of presenting a
        // grey rectangle as if it were the location.
        $this->get('/api/student/location-preview?lat=9.6419184&lng=123.855035&zoom=17')->assertNotFound();
    }

    public function test_the_picker_is_told_which_map_to_draw(): void
    {
        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        // Served rather than hardcoded in the SPA so the map the student pins
        // on and the map that prints cannot be two different maps.
        $this->getJson('/api/student/location-options')
            ->assertOk()
            ->assertJsonStructure(['enabled', 'tile_url', 'attribution', 'default_center' => ['lat', 'lng', 'zoom'], 'min_zoom', 'max_zoom'])
            ->assertJsonPath('min_zoom', StaticMapService::MIN_ZOOM)
            ->assertJsonPath('max_zoom', StaticMapService::MAX_ZOOM);
    }

    public function test_address_search_returns_pinnable_results(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['display_name' => 'BQ Mall, Tagbilaran, Bohol', 'lat' => '9.6419184', 'lon' => '123.8550350'],
            ['display_name' => 'No coordinates here'],
        ], 200)]);

        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        $this->getJson('/api/student/location-search?q=BQ Mall Tagbilaran')
            ->assertOk()
            // The second row has no coordinates, so it could not be pinned and
            // is dropped rather than offered as a dead option.
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.lat', 9.6419184)
            ->assertJsonPath('results.0.lng', 123.855035);
    }

    public function test_a_failing_geocoder_is_reported_as_unavailable_not_as_no_matches(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('', 500)]);

        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        // "No results" would send the student hunting for a better search term
        // for a service that is simply down; the UI needs to point them at the
        // manual pin instead.
        $this->getJson('/api/student/location-search?q=Somewhere Real')
            ->assertOk()
            ->assertJsonPath('unavailable', true)
            ->assertJsonCount(0, 'results');
    }

    public function test_a_too_short_search_never_reaches_the_geocoder(): void
    {
        Http::fake();

        [$student] = $this->studentWithSheet();
        Sanctum::actingAs($student);

        $this->getJson('/api/student/location-search?q=BQ')->assertOk()->assertJsonCount(0, 'results');

        // Nominatim's usage policy caps callers at about a request a second;
        // two characters can never be a useful query and must not spend one.
        Http::assertNothingSent();
    }
}
