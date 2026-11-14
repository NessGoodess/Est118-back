<?php

namespace Tests\Feature\Content;

use App\Models\Content\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LegalDocumentFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');
    }

    public function test_guest_reads_published_pdf_inline_and_public_copy_is_retired(): void
    {
        Storage::disk('public')->put('legal-documents/aviso.pdf', "%PDF-1.4 aviso");
        LegalDocument::query()->create([
            'type' => 'privacy',
            'title' => 'Aviso de privacidad',
            'src' => '/storage/legal-documents/aviso.pdf',
            'original_name' => 'aviso.pdf',
            'published_at' => now(),
        ]);

        $response = $this->get('/api/legal-documents/privacy/file');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('inline', strtolower((string) $response->headers->get('content-disposition')));
        $this->assertSame('%PDF-1.4 aviso', Storage::disk('private')->get('legal-documents/aviso.pdf'));
        Storage::disk('public')->assertMissing('legal-documents/aviso.pdf');
    }

    public function test_guest_cannot_read_draft_or_internal_notice(): void
    {
        Storage::disk('private')->put('legal-documents/draft.pdf', "%PDF-1.4 draft");
        Storage::disk('private')->put('legal-documents/fotos.pdf', "%PDF-1.4 fotos");

        LegalDocument::query()->create([
            'type' => 'school_rules',
            'title' => 'Reglamento',
            'src' => 'legal-documents/draft.pdf',
            'published_at' => null,
        ]);
        LegalDocument::query()->create([
            'type' => 'student_photos',
            'title' => 'Fotos',
            'src' => 'legal-documents/fotos.pdf',
            'published_at' => now(),
        ]);

        $this->get('/api/legal-documents/school_rules/file')->assertNotFound();
        $this->get('/api/legal-documents/school_rules/file?manage=1')->assertNotFound();
        $this->get('/api/legal-documents/student_photos/file')->assertNotFound();
    }

    public function test_manager_can_read_a_draft_inline(): void
    {
        Storage::disk('private')->put('legal-documents/draft.pdf', "%PDF-1.4 draft");
        LegalDocument::query()->create([
            'type' => 'privacy',
            'title' => 'Aviso',
            'src' => 'legal-documents/draft.pdf',
            'original_name' => 'borrador.pdf',
            'published_at' => null,
        ]);

        $this->authUser(['create legal documents']);

        $response = $this->get('/api/legal-documents/privacy/file?manage=1');

        $response->assertOk();
        $this->assertStringContainsString('inline', strtolower((string) $response->headers->get('content-disposition')));
        $cache = strtolower((string) $response->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_published_file_is_not_browser_cached(): void
    {
        Storage::disk('private')->put('legal-documents/aviso.pdf', "%PDF-1.4 aviso");
        LegalDocument::query()->create([
            'type' => 'privacy',
            'title' => 'Aviso',
            'src' => 'legal-documents/aviso.pdf',
            'published_at' => now(),
        ]);

        $cache = strtolower((string) $this->get('/api/legal-documents/privacy/file')->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('max-age', $cache);
    }

    public function test_upload_stores_the_pdf_on_the_private_disk(): void
    {
        $this->authUser(['create legal documents']);
        $file = UploadedFile::fake()->createWithContent('reglamento.pdf', "%PDF-1.4 reglamento");

        $response = $this->post('/api/content/pdf', [
            'collection' => 'legal-documents',
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $src = $response->json('src');
        $this->assertIsString($src);
        $this->assertStringStartsWith('legal-documents/', $src);
        $this->assertStringEndsWith('.pdf', $src);
        Storage::disk('private')->assertExists($src);
        Storage::disk('public')->assertMissing($src);
    }

    public function test_upsert_rejects_a_path_outside_legal_documents(): void
    {
        $this->authUser(['create legal documents']);

        $this->putJson('/api/legal-documents/privacy', [
            'title' => 'Aviso',
            'src' => '../../.env',
            'publish_action' => 'draft',
        ])->assertStatus(422);
    }

    public function test_manager_can_delete_a_document_and_its_pdf(): void
    {
        Storage::disk('private')->put('legal-documents/aviso.pdf', "%PDF-1.4 aviso");
        LegalDocument::query()->create([
            'type' => 'privacy',
            'title' => 'Aviso de privacidad',
            'src' => 'legal-documents/aviso.pdf',
            'original_name' => 'aviso.pdf',
            'published_at' => now(),
        ]);

        $this->authUser(['delete legal documents']);

        $this->deleteJson('/api/legal-documents/privacy')
            ->assertOk()
            ->assertJsonPath('id', null)
            ->assertJsonPath('type', 'privacy')
            ->assertJsonPath('src', null)
            ->assertJsonPath('published_at', null);

        $this->assertDatabaseMissing('legal_documents', ['type' => 'privacy']);
        Storage::disk('private')->assertMissing('legal-documents/aviso.pdf');
        $this->get('/api/legal-documents/privacy/file')->assertNotFound();
    }

    public function test_create_permission_cannot_delete_a_document(): void
    {
        LegalDocument::query()->create([
            'type' => 'privacy',
            'title' => 'Aviso',
            'src' => 'legal-documents/aviso.pdf',
            'published_at' => now(),
        ]);

        $this->authUser(['create legal documents']);

        $this->deleteJson('/api/legal-documents/privacy')->assertForbidden();
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
}
