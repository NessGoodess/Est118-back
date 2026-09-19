<?php

namespace Tests\Feature\Export;

use App\Models\ExportTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_user_can_upload_list_and_delete_template(): void
    {
        $user = $this->authUser(['manage export templates']);
        $file = $this->makeXlsxUpload('plantilla.xlsx');

        $store = $this->post('/api/export-templates', [
            'name' => 'Lista 1',
            'context' => 'students',
            'file' => $file,
            'is_default' => true,
        ], ['Accept' => 'application/json']);

        $store->assertCreated()
            ->assertJsonPath('data.name', 'Lista 1')
            ->assertJsonPath('data.context', 'students')
            ->assertJsonPath('data.is_default', true);

        $id = $store->json('data.id');
        Storage::disk('private')->assertExists(ExportTemplate::find($id)->path);

        $this->getJson('/api/export-templates?context=students')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/export-templates/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('export_templates', ['id' => $id]);
    }

    public function test_fill_template_writes_rows_and_preserves_sheet(): void
    {
        $user = $this->authUser(['manage export templates', 'view students']);
        $file = $this->makeXlsxUpload('base.xlsx', 'ENCABEZADO');

        $store = $this->post('/api/export-templates', [
            'name' => 'Base',
            'context' => 'students',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $templateId = $store->json('data.id');

        $response = $this->postJson('/api/students/export-with-template', [
            'template_id' => $templateId,
            'columns' => ['name', 'class_group'],
            'cell_map' => ['name' => 'G7', 'class_group' => 'H7'],
            'rows' => [
                ['name' => 'Ana', 'class_group' => 'A'],
                ['name' => 'Luis', 'class_group' => 'B'],
            ],
        ]);

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $response->headers->get('content-type')
        );

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $this->assertSame('ENCABEZADO', $sheet->getCell('A1')->getValue());
        $this->assertSame('Ana', $sheet->getCell('G7')->getValue());
        $this->assertSame('A', $sheet->getCell('H7')->getValue());
        $this->assertSame('Luis', $sheet->getCell('G8')->getValue());
        $this->assertSame('B', $sheet->getCell('H8')->getValue());
        @unlink($tmp);
    }

    public function test_fill_does_not_clear_formulas_when_value_is_empty(): void
    {
        $this->authUser(['manage export templates', 'view students']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'TITLE');
        $sheet->setCellValue('H5', '=MID(F5,11,1)');
        $tmpIn = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpIn);
        $file = new UploadedFile(
            $tmpIn,
            'formulas.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        $store = $this->post('/api/export-templates', [
            'name' => 'Con formulas',
            'context' => 'students',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response = $this->postJson('/api/students/export-with-template', [
            'template_id' => $store->json('data.id'),
            'columns' => ['gender'],
            'cell_map' => ['gender' => 'H5'],
            'rows' => [
                ['gender' => ''],
            ],
        ]);

        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $out = IOFactory::load($tmp)->getActiveSheet();
        $this->assertTrue($out->getCell('H5')->isFormula());
        @unlink($tmp);
        @unlink($tmpIn);
    }

    public function test_csv_export_returns_csv(): void
    {
        $this->authUser(['view students']);

        $response = $this->postJson('/api/students/export-csv', [
            'columns' => ['name', 'class_group'],
            'rows' => [
                ['name' => 'Ana', 'class_group' => 'A'],
            ],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $body = $response->streamedContent();
        $this->assertStringContainsString('name,class_group', $body);
        $this->assertStringContainsString('Ana,A', $body);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authUser(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeXlsxUpload(string $name, string $a1 = 'TITLE'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', $a1);

        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);

        return new UploadedFile($tmp, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
