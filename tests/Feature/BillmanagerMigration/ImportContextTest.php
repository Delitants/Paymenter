<?php

namespace Tests\Feature\BillmanagerMigration;

use App\Models\User;
use App\Services\BillmanagerMigration\ImportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportContextTest extends TestCase
{
    use RefreshDatabase;

    private function context(): ImportContext
    {
        $id = DB::table('billmanager_imports')->insertGetId([
            'source_host' => '192.0.2.10', 'snapshot_sha256' => str_repeat('a', 64), 'status' => 'running',
        ]);

        return new ImportContext($id, '192.0.2.10');
    }

    public function test_mapping_and_native_writes_roll_back_together(): void
    {
        $context = $this->context();
        try {
            DB::transaction(function () use ($context) {
                $id = User::factory()->create(['email' => 'rollback@example.test'])->id;
                $context->recordMapping('users', '31', 'users', $id);
                throw new \RuntimeException('Injected import failure');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected import failure', $e->getMessage());
            $this->assertNull($context->mappedId('users', '31'));
            $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
        }
    }

    public function test_repeated_mapping_is_stable_and_conflicting_mapping_is_rejected(): void
    {
        $context = $this->context();
        $context->recordMapping('users', '31', 'users', 25);
        $context->recordMapping('users', '31', 'users', 25);
        $this->assertSame(25, $context->mappedId('users', '31'));
        $this->assertSame(1, DB::table('billmanager_mappings')->count());
        $this->expectException(\RuntimeException::class);
        $context->recordMapping('users', '31', 'users', 26);
    }

    public function test_source_payload_is_encrypted_and_exact_decimals_survive_replay(): void
    {
        $context = $this->context();
        $row = ['id' => '12', 'amount' => '-1450.8693', 'message' => 'Private synthetic text'];
        $context->archive('payments', '12', $row, 21);
        $context->archive('payments', '12', $row, 21);
        $record = DB::table('billmanager_records')->sole();
        $this->assertStringNotContainsString('Private synthetic', $record->payload);
        $this->assertSame($row, json_decode(Crypt::decryptString($record->payload), true));
        $this->assertSame(1, DB::table('billmanager_records')->count());
    }
}
