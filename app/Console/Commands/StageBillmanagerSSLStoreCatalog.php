<?php

namespace App\Console\Commands;

use App\Services\BillmanagerMigration\SSLStoreCatalogImporter;
use DomainException;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class StageBillmanagerSSLStoreCatalog extends Command
{
    protected $signature = 'billmanager:sslstore:stage {catalog : Reviewed local JSON catalog} {server : Disabled SSLStore server ID} {category : Existing category ID} {--sha256= : Reviewed catalog checksum}';

    protected $description = 'Stage reviewed BILLmanager certificate products as hidden and out of stock';

    public function handle(SSLStoreCatalogImporter $importer): int
    {
        try {
            $path = $this->argument('catalog');
            $digest = $this->option('sha256');
            $server = filter_var($this->argument('server'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $category = filter_var($this->argument('category'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$server || !$category || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/', $digest)
                || !stream_is_local($path) || !is_file($path)) {
                throw new DomainException('A local catalog, reviewed checksum, and valid server and category IDs are required.');
            }
            $limit = 2 * 1024 * 1024;
            $bytes = @file_get_contents($path, false, null, 0, $limit + 1);
            if ($bytes === false || strlen($bytes) > $limit || !hash_equals($digest, hash('sha256', $bytes))) {
                throw new DomainException('The catalog could not be read or did not match its reviewed checksum.');
            }
            $catalog = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($catalog) || ($catalog['result']['accepted'] ?? null) !== true || !is_array($catalog['plans'] ?? null)) {
                throw new DomainException('The catalog does not contain a reviewed certificate mapping.');
            }
            $result = $importer->stage($catalog['plans'], $server, $category);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (DomainException $error) {
            $this->error($error->getMessage());
        } catch (JsonException) {
            $this->error('The reviewed catalog is not valid JSON.');
        } catch (Throwable) {
            $this->error('The certificate catalog could not be staged. Check the target database and staging inputs.');
        }

        return self::FAILURE;
    }
}
