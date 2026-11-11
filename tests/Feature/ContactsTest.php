<?php

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactTag;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

test('workspace members can manage contacts and their consent and classifications', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->workspaces()->attach($workspace);
    $group = ContactGroup::factory()->create(['workspace_id' => $workspace->id]);
    $tag = ContactTag::factory()->create(['workspace_id' => $workspace->id]);
    $base = "/workspaces/{$workspace->id}/contacts";
    $data = ['name' => 'Jane', 'phone' => '+966501234567', 'email' => 'jane@example.com', 'opted_in' => true, 'groups' => [$group->id], 'tags' => [$tag->id]];

    $this->actingAs($user)->post($base, $data)->assertSessionHasNoErrors();
    $contact = Contact::firstOrFail();
    expect($contact->opted_in)->toBeTrue();
    expect($contact->groups()->pluck('contact_groups.id')->all())->toBe([$group->id]);
    expect($contact->tags()->pluck('contact_tags.id')->all())->toBe([$tag->id]);
    $this->get($base)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contacts')->has('contacts', 1)->where('contacts.0.name', 'Jane'));

    $data['opted_in'] = false;
    $data['groups'] = [];
    $this->put("$base/{$contact->id}", $data)->assertSessionHasNoErrors();
    expect($contact->fresh()->opted_in)->toBeFalse();
    expect($contact->groups()->count())->toBe(0);
    $this->delete("$base/{$contact->id}")->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('contacts', ['id' => $contact->id]);
});

test('workspace isolation covers contact reads writes classifications and transfers', function () {
    $user = User::factory()->create();
    $own = Workspace::factory()->create();
    $other = Workspace::factory()->create();
    $user->workspaces()->attach($own);
    $contact = Contact::factory()->create(['workspace_id' => $other->id]);
    $group = ContactGroup::factory()->create(['workspace_id' => $other->id]);
    $tag = ContactTag::factory()->create(['workspace_id' => $other->id]);
    $ownBase = "/workspaces/{$own->id}/contacts";
    $otherBase = "/workspaces/{$other->id}/contacts";
    $data = ['name' => 'Attempt', 'phone' => '+966501234567', 'email' => '', 'opted_in' => false, 'groups' => [], 'tags' => []];
    $this->actingAs($user)->get($otherBase)->assertNotFound();
    $this->get('/contacts')->assertOk()->assertInertia(fn (Assert $page) => $page->has('workspaces', 1)->where('workspaces.0.id', $own->id));
    $this->post($otherBase, $data)->assertNotFound();
    $this->get("$otherBase/export/csv")->assertNotFound();
    $this->post("$otherBase/import", [])->assertNotFound();
    $this->post("$otherBase/classifications/groups", ['name' => 'x'])->assertNotFound();
    $this->delete("$ownBase/{$contact->id}")->assertNotFound();
    $this->put("$ownBase/{$contact->id}", $data)->assertNotFound();
    $this->delete("$ownBase/classifications/groups/{$group->id}")->assertNotFound();
    $this->put("$ownBase/classifications/tags/{$tag->id}", ['name' => 'Changed'])->assertNotFound();
    $data['groups'] = [$group->id];
    $data['tags'] = [$tag->id];
    $this->post($ownBase, $data)->assertSessionHasErrors(['groups.0', 'tags.0']);
    $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => $contact->name]);
});

test('administrators can manage contacts without workspace membership', function () {
    $admin = User::factory()->create(['type' => 'admin']);
    $workspace = Workspace::factory()->create();
    $this->actingAs($admin)->get("/workspaces/{$workspace->id}/contacts")->assertOk();
    $this->post("/workspaces/{$workspace->id}/contacts", [
        'name' => 'Admin contact', 'phone' => '+966501234567', 'email' => null,
        'opted_in' => false, 'groups' => [], 'tags' => [],
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'name' => 'Admin contact']);
});

test('groups and tags support scoped creation update and deletion', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->workspaces()->attach($workspace);
    $base = "/workspaces/{$workspace->id}/contacts/classifications";
    $this->actingAs($user);
    foreach (['groups' => ContactGroup::class, 'tags' => ContactTag::class] as $kind => $model) {
        $this->post("$base/$kind", ['name' => 'First'])->assertSessionHasNoErrors();
        $item = $model::where('workspace_id', $workspace->id)->firstOrFail();
        $this->post("$base/$kind", ['name' => 'First'])->assertSessionHasErrors('name');
        $this->put("$base/$kind/{$item->id}", ['name' => 'Changed'])->assertSessionHasNoErrors();
        expect($item->fresh()->name)->toBe('Changed');
        $this->delete("$base/$kind/{$item->id}")->assertSessionHasNoErrors();
        expect($model::find($item->id))->toBeNull();
    }
});

test('csv import updates matching contacts but preserves consent and rejects invalid files atomically', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->workspaces()->attach($workspace);
    $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone' => '+966501234567', 'opted_in' => false]);
    $base = "/workspaces/{$workspace->id}/contacts";
    $csv = "name,phone,email,opted_in\nUpdated,+966501234567,new@example.com,1\nNew,+966501234568,,1\n";
    $this->actingAs($user)->post("$base/import", ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)])->assertSessionHasNoErrors();
    expect($contact->fresh()->name)->toBe('Updated');
    expect($contact->fresh()->opted_in)->toBeFalse();
    expect($workspace->contacts()->where('phone', '+966501234568')->firstOrFail()->opted_in)->toBeTrue();
    $invalid = "name,phone,email,opted_in\nChanged,+966501234567,,1\nBad,abc,,1\n";
    $this->post("$base/import", ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $invalid)])->assertSessionHasErrors('file');
    expect($contact->fresh()->name)->toBe('Updated');
    Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => '=SUM(1,1)', 'phone' => '+966501234569']);
    Contact::factory()->create(['name' => 'Other workspace secret']);
    $output = $this->get("$base/export/csv")->assertOk()->streamedContent();
    expect($output)->toContain('Updated')->toContain("'+966501234567")->toContain("'=SUM(1,1)")->not->toContain('Other workspace secret')->not->toContain('Bad');
});

test('xlsx import and export use real spreadsheets and reject formulas', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->workspaces()->attach($workspace);
    $sheet = new Spreadsheet;
    $sheet->getActiveSheet()->fromArray([['name', 'phone', 'email', 'opted_in'], ['Excel', '+966501234567', '', '0']]);
    $sheet->getActiveSheet()->setCellValueExplicit('B2', '+966501234567', DataType::TYPE_STRING);
    $path = tempnam(sys_get_temp_dir(), 'contacts');
    try {
        (new Xlsx($sheet))->save($path);
        $this->actingAs($user)->post("/workspaces/{$workspace->id}/contacts/import", ['file' => new UploadedFile($path, 'contacts.xlsx', null, null, true)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contacts', ['workspace_id' => $workspace->id, 'name' => 'Excel']);
        $response = $this->get("/workspaces/{$workspace->id}/contacts/export/xlsx")->assertOk()->streamedContent();
        expect(substr($response, 0, 2))->toBe('PK');
        file_put_contents($path, $response);
        $export = IOFactory::load($path);
        expect($export->getActiveSheet()->getCell('B2')->getValue())->toBe('+966501234567');
        expect($export->getActiveSheet()->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING);
        $export->disconnectWorksheets();
        $sheet->getActiveSheet()->setCellValue('A2', '=1+1');
        (new Xlsx($sheet))->save($path);
        $this->post("/workspaces/{$workspace->id}/contacts/import", ['file' => new UploadedFile($path, 'contacts.xlsx', null, null, true)])->assertSessionHasErrors('file');
    } finally {
        $sheet->disconnectWorksheets();
        unlink($path);
    }
});

test('guests cannot access contacts', function () {
    $this->get('/contacts')->assertRedirect('/login');
    $workspace = Workspace::factory()->create();
    $this->get("/workspaces/{$workspace->id}/contacts")->assertRedirect('/login');
});
