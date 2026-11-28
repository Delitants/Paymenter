<?php

namespace App\Services\BillmanagerMigration;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PreviewSnapshot
{
    public const LABELS = [
        'users' => 'Users',
        'services' => 'Services',
        'tickets' => 'Tickets',
        'finance' => 'Financial archive',
    ];

    public const COLUMNS = [
        'users' => ['id' => 'ID', 'source_user_id' => 'BILLmanager user', 'source_account_id' => 'BILLmanager account',
            'first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Email'],
        'services' => ['id' => 'ID', 'source_id' => 'BILLmanager service', 'label' => 'Service', 'product' => 'Product',
            'email' => 'Owner', 'status' => 'Status', 'price' => 'Stored price', 'currency_code' => 'Currency', 'expires_at' => 'Expires'],
        'tickets' => ['id' => 'ID', 'subject' => 'Subject', 'status' => 'Status', 'updated_at' => 'Updated'],
        'finance' => ['id' => 'ID', 'source_account_id' => 'BILLmanager account', 'source_table' => 'Type',
            'source_id' => 'BILLmanager record', 'number' => 'Number', 'status' => 'Status', 'amount' => 'Amount',
            'currency_code' => 'Currency', 'import_id' => 'Snapshot'],
    ];

    private const KEYS = ['users' => 'm.user_id', 'services' => 's.id', 'tickets' => 't.id', 'finance' => 'id'];

    private function __construct(private readonly Connection $database) {}

    private static function file(): string
    {
        return config('billmanager-preview.file', '/etc/paymenter/billmanager-preview.json');
    }

    public static function available(): bool
    {
        try {
            self::connectionSettings();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function connectionSettings(): array
    {
        $file = self::file();
        if (!is_file($file) || !is_readable($file) || filesize($file) > 4096) {
            throw new RuntimeException('The BILLmanager preview connection is unavailable.');
        }

        $contents = file_get_contents($file, length: 4097);
        if ($contents === false || strlen($contents) > 4096) {
            throw new RuntimeException('The BILLmanager preview connection is unavailable.');
        }

        $settings = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($settings) || !is_string($settings['password'] ?? null)
            || !is_string($settings['database'] ?? null) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $settings['database'])
            || !is_string($settings['username'] ?? null) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $settings['username'])
            || !is_string($settings['unix_socket'] ?? null) || !str_starts_with($settings['unix_socket'], '/')) {
            throw new RuntimeException('The BILLmanager preview connection is invalid.');
        }

        $database = config('database.connections.'.config('database.default').'.database');
        if ($settings['database'] === $database) {
            throw new RuntimeException('The BILLmanager copy is already serving the application.');
        }

        return $settings;
    }

    public static function open(): self
    {
        $settings = self::connectionSettings();

        return new self(DB::build([
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => $settings['database'],
            'username' => $settings['username'],
            'password' => $settings['password'],
            'unix_socket' => $settings['unix_socket'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'],
        ]));
    }

    public function counts(): array
    {
        $counts = [];
        foreach (self::KEYS as $dataset => $key) {
            $counts[$dataset] = $this->query($dataset)->count($key);
        }

        return $counts;
    }

    public function records(string $dataset, string $search, string $pageName = 'page', ?int $page = null): Paginator
    {
        $query = $this->query($dataset);
        $fields = match ($dataset) {
            'users' => ['u.first_name', 'u.last_name', 'u.email', 'm.source_user_id', 'a.source_account_id'],
            'services' => ['s.label', 'd.source_id', 'p.name', 's.status', 'u.email'],
            'tickets' => ['t.subject', 't.status'],
            'finance' => ['source_id', 'source_account_id', 'source_table', 'number'],
        };
        if ($search !== '') {
            $query->where(function (Builder $query) use ($fields, $search): void {
                foreach ($fields as $field) {
                    $query->orWhere($field, 'like', '%'.$search.'%');
                }
            });
        }

        return $query->orderByDesc(self::KEYS[$dataset])->simplePaginate(40, pageName: $pageName, page: $page)->withQueryString();
    }

    private function query(string $dataset): Builder
    {
        return match ($dataset) {
            'users' => $this->database->table('billmanager_members as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->join('billmanager_accounts as a', 'a.id', '=', 'm.account_id')
                ->select('u.id', 'm.source_user_id', 'a.source_account_id', 'u.first_name', 'u.last_name', 'u.email'),
            'services' => $this->database->table('billmanager_service_details as d')
                ->join('services as s', 's.id', '=', 'd.service_id')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->leftJoin('products as p', 'p.id', '=', 's.product_id')
                ->select('s.id', 'd.source_id', 's.label', 'p.name as product', 'u.email', 's.status', 's.price',
                    's.currency_code', 's.expires_at'),
            'tickets' => $this->database->table('tickets as t')
                ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('billmanager_mappings as m')
                    ->where('m.target_table', 'tickets')->whereColumn('m.target_id', 't.id'))
                ->select('t.id', 't.subject', 't.status', 't.updated_at'),
            'finance' => $this->database->table('billmanager_financial_records')
                ->select(array_keys(self::COLUMNS['finance'])),
            default => throw new InvalidArgumentException('Unknown BILLmanager preview dataset.'),
        };
    }
}
