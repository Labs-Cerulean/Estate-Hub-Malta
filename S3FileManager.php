<?php
// Ensure Composer's autoloader is included (adjust path if needed)
require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class S3FileManager {
    private $client;
    private $bucket;

    public function __construct() {
        // Fallback to constants if env vars aren't set directly
        $accountId = getenv('R2_ACCOUNT_ID') ?: 'e9933d76f02eff964eac2f57d559757b';
        $accessKey = getenv('R2_ACCESS_KEY') ?: 'd2f870c6c4f6d44baca3724225fcd86d';
        $secretKey = getenv('R2_SECRET_KEY') ?: 'dfb3914f821ece155958041f8e0592de575dbe53fe29f7e1fef15901a6d11bdb';
        $this->bucket = getenv('R2_BUCKET_NAME') ?: 'estate-hub-vault';

        $credentials = new Aws\Credentials\Credentials($accessKey, $secretKey);

        $this->client = new S3Client([
            'version'                 => 'latest',
            'region'                  => 'auto', // Cloudflare R2 uses 'auto'
            'endpoint'                => "https://{$accountId}.r2.cloudflarestorage.com",
            'credentials'             => $credentials,
            // R2 requires path style endpoints
            'use_path_style_endpoint' => true,
        ]);
    }

    /**
     * Uploads a file directly to Cloudflare R2
     * Returns the stored File Key (path) on success, or false on failure.
     */
    public function uploadFile($fileTempPath, $originalFileName, $contentType = 'application/octet-stream', $folder = 'general') {
        // Sanitize file name and create a unique path: e.g., documents/drawings/2026/03/uniqueid_file.pdf
        $cleanName = preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($originalFileName));
        $key = "documents/{$folder}/" . date('Y/m/') . uniqid() . '_' . $cleanName;
        
        try {
            $this->client->putObject([
                'Bucket'      => $this->bucket,
                'Key'         => $key,
                'SourceFile'  => $fileTempPath,
                'ContentType' => $contentType
            ]);
            return $key;
        } catch (AwsException $e) {
            error_log("R2 Upload Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generates a secure, temporary, expiring URL to view/download the file
     * By default, the link expires in 60 minutes.
     */
    public function getPresignedUrl($key, $expiry = '+60 minutes') {
        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key'    => $key
            ]);
            $request = $this->client->createPresignedRequest($cmd, $expiry);
            return (string)$request->getUri();
        } catch (AwsException $e) {
            error_log("R2 URL Gen Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Permanently deletes a file from the bucket
     */
    public function deleteFile($key) {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key
            ]);
            return true;
        } catch (AwsException $e) {
            error_log("R2 Delete Error: " . $e->getMessage());
            return false;
        }
    }
}
