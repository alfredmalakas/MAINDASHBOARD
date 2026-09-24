<?php
/**
 * Incoming integration file endpoint.
 * POST /v1/integration/files
 *
 * Authentication: Bearer token for an HR Administrator or Super Admin.
 * Multipart fields:
 *   source_system (required)
 *   file (required)
 *
 * A notification is created only after the incoming file is successfully stored.
 */
class ApiIntegrationController
{
    public static function receiveFile(array $params, array $user): void
    {
        global $pdo;

        $source = trim((string)($_POST['source_system'] ?? ''));
        if ($source === '') {
            throw new ApiException('source_system is required.', 422, 'validation_error');
        }

        if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiException('An integration file is required.', 422, 'file_required');
        }

        $file = $_FILES['file'];
        if ((int)$file['size'] > 20 * 1024 * 1024) {
            throw new ApiException('Integration file must not exceed 20 MB.', 422, 'file_too_large');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = [
            'text/csv',
            'application/csv',
            'application/pdf',
            'application/zip',
            'application/octet-stream',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        if (!in_array($mime, $allowed, true)) {
            throw new ApiException('Unsupported integration file type.', 422, 'unsupported_file');
        }

        $dir = __DIR__ . '/../../storage/integration';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new ApiException('Unable to prepare integration storage.', 500, 'storage_error');
        }

        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        $safeName = 'integration_' . bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
        $target = $dir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new ApiException('Unable to store the incoming integration file.', 500, 'storage_error');
        }

        $relative = 'storage/integration/' . $safeName;
        $stmt = $pdo->prepare(
            'INSERT INTO integration_files
             (source_system, original_filename, stored_path, file_size, received_by_user_id)
             VALUES (?,?,?,?,?)'
        );
        $stmt->execute([
            $source,
            basename((string)$file['name']),
            $relative,
            (int)$file['size'],
            (int)$user['user_id'],
        ]);
        $id = (int)$pdo->lastInsertId();

        // This is the ONLY integration notification trigger:
        // notify HR Admin + Super Admin after a file has actually arrived.
        if (function_exists('notify_admins')) {
            notify_admins(
                $pdo,
                'New integration file received',
                "{$source} sent a new file: " . basename((string)$file['name']),
                'integration',
                'integration_files',
                $id
            );
        }

        if (function_exists('audit')) {
            audit($pdo, 'INTEGRATION_FILE_RECEIVED', 'integration_files', $id,
                "{$source}: " . basename((string)$file['name']));
        }

        api_respond([
            'integration_file_id' => $id,
            'source_system'       => $source,
            'filename'            => basename((string)$file['name']),
            'message'             => 'Integration file received successfully. HR Admin and Super Admin were notified.',
        ], 201);
    }
}
