<?php

final class DriveUserContext
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public ?int $userId;
    public string $role;
    public string $username;

    private function __construct(?int $userId, string $role, string $username)
    {
        $this->userId = $userId;
        $this->role = $role;
        $this->username = $username;
    }

    public static function fromSession(array $session): self
    {
        return new self(
            isset($session['user_id']) ? (int) $session['user_id'] : null,
            (string) ($session['role'] ?? 'guest'),
            (string) ($session['username'] ?? 'User')
        );
    }

    public function authorize(): void
    {
        if (!$this->isAllowedRole()) {
            die(include __DIR__ . '/../err/denied.php');
        }
    }

    public function isAllowedRole(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MEMBER], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isMember(): bool
    {
        return $this->role === self::ROLE_MEMBER;
    }
}

final class DriveStorage
{
    public const SCOPE_PUBLIC = 'public';
    public const SCOPE_PRIVATE = 'private';

    private const TYPE_VIDEO = 'video';
    private const TYPE_AUDIO = 'audio';
    private const TYPE_DOCUMENT = 'dokumen';

    private const VIDEO_EXTENSIONS = ['mp4', 'mkv', 'mov', 'webm', 'avi'];
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'ogg', 'wav', 'm4a'];
    private const ALLOWED_TYPES = [
        self::TYPE_VIDEO,
        self::TYPE_AUDIO,
        self::TYPE_DOCUMENT,
    ];

    private string $basePath;
    private DriveUserContext $user;
    private string $webBasePath;

    public function __construct(
        string $basePath,
        DriveUserContext $user,
        string $webBasePath = '../data_drive'
    ) {
        $this->basePath = $basePath;
        $this->user = $user;
        $this->webBasePath = $webBasePath;
    }

    public function normalizeScope(?string $scope): string
    {
        return $scope === self::SCOPE_PRIVATE ? self::SCOPE_PRIVATE : self::SCOPE_PUBLIC;
    }

    public function normalizeType(?string $type): string
    {
        return in_array($type, self::ALLOWED_TYPES, true) ? $type : self::TYPE_DOCUMENT;
    }

    public function resolveUploadScope(?string $requestedScope): string
    {
        $scope = $this->normalizeScope($requestedScope);

        if ($scope === self::SCOPE_PUBLIC && !$this->user->isAdmin()) {
            return self::SCOPE_PRIVATE;
        }

        return $scope;
    }

    public function listFilesByType(string $type, string $scope): array
    {
        $directory = $this->getDirectoryForType($type, $scope);
        $webDirectory = $this->getWebDirectoryForType($type, $scope);
        $this->ensureDirectoryExists($directory);

        $files = [];
        $iterator = new DirectoryIterator($directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                continue;
            }

            $files[] = [
                'name' => $fileInfo->getFilename(),
                'size' => $fileInfo->getSize(),
                'time' => $fileInfo->getMTime(),
                'path' => $webDirectory . '/' . rawurlencode($fileInfo->getFilename()),
                'ext' => strtolower($fileInfo->getExtension()),
            ];
        }

        usort(
            $files,
            static fn(array $left, array $right): int => $right['time'] <=> $left['time']
        );

        return $files;
    }

    public function upload(array $file, ?string $requestedScope): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Berkas gagal diterima dari browser.');
        }

        $scope = $this->resolveUploadScope($requestedScope);
        $cleanName = $this->sanitizeFileName((string) ($file['name'] ?? ''));
        $type = $this->detectTypeFromFilename($cleanName);
        $directory = $this->getDirectoryForType($type, $scope);
        $this->ensureDirectoryExists($directory);

        $finalName = $this->ensureUniqueFilename($directory, $cleanName);
        $destination = $directory . '/' . $finalName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            throw new RuntimeException('Gagal mengunggah file. Cek izin folder penyimpanan.');
        }

        return [
            'scope' => $scope,
            'type' => $type,
            'filename' => $finalName,
            'path' => $destination,
        ];
    }

    public function enforceQuota(array $file, int $limitBytes): void
    {
        if (!$this->user->isMember()) {
            return;
        }

        $currentUsage = get_user_usage($this->user->username);
        $newFileSize = (int) ($file['size'] ?? 0);

        if (($currentUsage + $newFileSize) > $limitBytes) {
            throw new RuntimeException('quota_full');
        }
    }

    public function getFileForDownload(?string $filename, ?string $type, ?string $scope): array
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);
        $safeFilename = $this->sanitizeRequestedFilename($filename);
        $filePath = $this->buildFilePath($safeType, $safeScope, $safeFilename);

        if (!is_file($filePath)) {
            throw new RuntimeException('File fisik tidak ditemukan di server.');
        }

        return [
            'name' => $safeFilename,
            'path' => $filePath,
            'size' => filesize($filePath),
        ];
    }

    public function delete(?string $filename, ?string $type, ?string $scope): void
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);
        $safeFilename = $this->sanitizeRequestedFilename($filename);
        $filePath = $this->buildFilePath($safeType, $safeScope, $safeFilename, true);

        if (!is_file($filePath)) {
            throw new RuntimeException('File tidak ditemukan.');
        }

        if (!unlink($filePath)) {
            throw new RuntimeException('Gagal menghapus file. Periksa izin folder.');
        }
    }

    private function buildFilePath(string $type, string $scope, string $filename, bool $forDelete = false): string
    {
        if ($scope === self::SCOPE_PUBLIC) {
            if ($forDelete && !$this->user->isAdmin()) {
                throw new RuntimeException('Hanya Admin yang dapat menghapus file di Public Space.');
            }

            return $this->publicRoot() . '/' . $type . '/' . $filename;
        }

        return $this->privateRootForUser($this->user->username) . '/' . $type . '/' . $filename;
    }

    private function getDirectoryForType(string $type, string $scope): string
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);

        if ($safeScope === self::SCOPE_PRIVATE) {
            return $this->privateRootForUser($this->user->username) . '/' . $safeType;
        }

        return $this->publicRoot() . '/' . $safeType;
    }

    private function getWebDirectoryForType(string $type, string $scope): string
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);

        if ($safeScope === self::SCOPE_PRIVATE) {
            return $this->webBasePath . '/private_admins/' . rawurlencode($this->user->username) . '/' . $safeType;
        }

        return $this->webBasePath . '/public/' . $safeType;
    }

    private function publicRoot(): string
    {
        return $this->basePath . '/public';
    }

    private function privateRootForUser(string $username): string
    {
        return $this->basePath . '/private_admins/' . $username;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder penyimpanan gagal dibuat.');
        }
    }

    private function detectTypeFromFilename(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return self::TYPE_VIDEO;
        }

        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            return self::TYPE_AUDIO;
        }

        return self::TYPE_DOCUMENT;
    }

    private function sanitizeFileName(string $filename): string
    {
        $baseName = basename($filename);
        $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName) ?: 'file';

        return trim($cleanName, '._') !== '' ? $cleanName : 'file';
    }

    private function sanitizeRequestedFilename(?string $filename): string
    {
        $safeFilename = basename((string) $filename);

        if ($safeFilename === '') {
            throw new RuntimeException('Parameter file tidak lengkap.');
        }

        return $safeFilename;
    }

    private function ensureUniqueFilename(string $directory, string $filename): string
    {
        $candidate = $filename;
        $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $counter = 1;

        while (file_exists($directory . '/' . $candidate)) {
            $suffix = '_(' . $counter . ')';
            $candidate = $extension !== ''
                ? $nameOnly . $suffix . '.' . $extension
                : $nameOnly . $suffix;
            $counter++;
        }

        return $candidate;
    }
}

final class DriveViewRenderer
{
    public function renderFileGrid(array $files, string $accent, string $icon, string $type, string $scope): void
    {
        if (empty($files)) {
            echo '<div class="flex flex-col items-center justify-center py-20 opacity-20">
                <i data-lucide="folder-open" class="w-16 h-16 mb-4"></i>
                <p>Tidak ada file ditemukan</p>
              </div>';
            return;
        }

        echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">';

        foreach ($files as $file) {
            $name = htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8');
            $path = htmlspecialchars($file['path'], ENT_QUOTES, 'UTF-8');
            $size = format_bytes((int) $file['size']);
            $date = date('d M Y', (int) $file['time']);
            $downloadUrl = 'download.php?file=' . urlencode($file['name']) . '&type=' . $type . '&scope=' . $scope;
            $deleteUrl = 'delete.php?file=' . urlencode($file['name']) . '&type=' . $type . '&scope=' . $scope;

            echo "
        <div class='glass p-4 rounded-2xl group hover:border-blue-500/50 transition-all duration-300 transform hover:-translate-y-1 shadow-xl hover:shadow-blue-900/10'>
            <div class='flex items-start justify-between mb-4'>
                <div class='p-3 rounded-xl bg-gray-900 group-hover:bg-blue-500/10 transition'>
                    <i data-lucide='$icon' class='w-6 h-6' style='color: $accent'></i>
                </div>
                
                <div class='flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity'>
                    <button onclick=\"openPreview('$path', '$type', '$name')\" class='p-2 hover:bg-blue-500/20 rounded-lg text-blue-400' title='Pratinjau'>
                        <i data-lucide='eye' class='w-4 h-4'></i>
                    </button>
                    
                    <a href='$downloadUrl' class='p-2 hover:bg-green-500/20 rounded-lg text-green-400' title='Unduh'>
                        <i data-lucide='download' class='w-4 h-4'></i>
                    </a>
                    
                    <a href='$deleteUrl' onclick=\"return meelConfirmLink(event, { title: 'Hapus File', text: 'Hapus file ini?', confirmButtonText: 'HAPUS' })\" class='p-2 hover:bg-red-500/20 rounded-lg text-red-400' title='Hapus'>
                        <i data-lucide='trash-2' class='w-4 h-4'></i>
                    </a>
                </div>
            </div>

            <h3 class='text-sm font-bold truncate mb-1 text-gray-200' title='$name'>$name</h3>
            <div class='flex justify-between items-center text-[10px] text-gray-500 font-medium uppercase tracking-tighter'>
                <span>$size</span>
                <span>$date</span>
            </div>
        </div>";
        }

        echo '</div>';
    }
}
