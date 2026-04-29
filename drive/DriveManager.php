<?php
/**
 * DriveManager - Efficient file management for MEeL Cloud Drive
 * Centralized class untuk semua operasi file storage
 */

class DriveManager {
    private $conn;
    private $base_path;
    private $max_file_size = 5 * 1024 * 1024 * 1024; // 5 GB per file
    private $member_quota = 20 * 1024 * 1024 * 1024; // 20 GB for members
    
    // Allowed file types by category
    private $file_types = [
        'video' => ['mp4', 'mkv', 'mov', 'webm', 'avi', 'flv', 'wmv', 'm4v'],
        'audio' => ['mp3', 'flac', 'ogg', 'wav', 'm4a', 'aac', 'wma'],
        'dokumen' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', '7z']
    ];

    public function __construct($db_connection, $base_path = null) {
        $this->conn = $db_connection;
        $this->base_path = $base_path ?? __DIR__ . '/../data_drive';
    }

    public function getDirectory($category, $scope = 'public', $username = null) {
        $category = $this->sanitizeCategory($category);
        if ($scope === 'private' && $username) {
            $dir = $this->base_path . '/private_admins/' . $this->sanitizeUsername($username) . '/' . $category;
        } else {
            $dir = $this->base_path . '/public/' . $category;
        }
        return $dir;
    }

    public function listFiles($category, $scope = 'public', $username = null, $sort = 'time') {
        $dir = $this->getDirectory($category, $scope, $username);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.gitkeep') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_file($path)) {
                $files[] = [
                    'name' => $item,
                    'size' => filesize($path),
                    'time' => filemtime($path),
                    'path' => $path,
                    'mime' => $this->getMimeType($path),
                    'sizeFormatted' => $this->formatBytes(filesize($path))
                ];
            }
        }
        
        usort($files, function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'name':
                    return strcmp($a['name'], $b['name']);
                case 'size':
                    return $b['size'] - $a['size'];
                case 'time':
                default:
                    return $b['time'] - $a['time'];
            }
        });
        
        return $files;
    }

    public function getAllFiles($scope = 'public', $username = null) {
        $result = [];
        foreach (array_keys($this->file_types) as $category) {
            $result[$category] = $this->listFiles($category, $scope, $username);
        }
        return $result;
    }

    public function getUserUsage($username) {
        $dir = $this->base_path . '/private_admins/' . $this->sanitizeUsername($username);
        return $this->calculateDirectorySize($dir);
    }

    public function hasQuotaAvailable($username, $fileSize) {
        $current_usage = $this->getUserUsage($username);
        return ($current_usage + $fileSize) <= $this->member_quota;
    }

    public function uploadFile($file, $category, $scope = 'public', $username = null) {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'message' => 'File tidak valid'];
        }

        $category = $this->sanitizeCategory($category);
        $username = $this->sanitizeUsername($username);

        if ($file['size'] > $this->max_file_size) {
            return ['success' => false, 'message' => 'Ukuran file terlalu besar (max 5GB)'];
        }

        $detected_category = $this->detectCategory(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)));
        $original_name = basename($file['name']);
        $clean_name = $this->sanitizeFilename($original_name);
        $ext = strtolower(pathinfo($clean_name, PATHINFO_EXTENSION));

        $dir = $this->getDirectory($detected_category, $scope, $username);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $final_name = $this->getUniqueFilename($dir, $clean_name);
        $final_path = $dir . '/' . $final_name;

        if (!move_uploaded_file($file['tmp_name'], $final_path)) {
            return ['success' => false, 'message' => 'Gagal mengunggah file ke server'];
        }

        chmod($final_path, 0644);

        return [
            'success' => true,
            'message' => 'File berhasil diunggah',
            'data' => [
                'name' => $final_name,
                'size' => filesize($final_path),
                'category' => $detected_category,
                'path' => $final_path
            ]
        ];
    }

    public function deleteFile($filename, $category, $scope = 'public', $username = null) {
        $category = $this->sanitizeCategory($category);
        $filename = basename($filename);
        $username = $this->sanitizeUsername($username);

        $dir = $this->getDirectory($category, $scope, $username);
        $path = $dir . '/' . $filename;

        if (!$this->isPathSafe($path, $dir)) {
            return ['success' => false, 'message' => 'Operasi tidak diizinkan'];
        }

        if (file_exists($path) && is_file($path)) {
            if (unlink($path)) {
                return ['success' => true, 'message' => 'File berhasil dihapus'];
            } else {
                return ['success' => false, 'message' => 'Gagal menghapus file'];
            }
        }

        return ['success' => false, 'message' => 'File tidak ditemukan'];
    }

    public function downloadFile($filename, $category, $scope = 'public', $username = null) {
        $category = $this->sanitizeCategory($category);
        $filename = basename($filename);
        $username = $this->sanitizeUsername($username);

        $dir = $this->getDirectory($category, $scope, $username);
        $path = $dir . '/' . $filename;

        if (!file_exists($path) || !is_file($path)) {
            return ['success' => false, 'message' => 'File tidak ditemukan'];
        }

        if (!$this->isPathSafe($path, $dir)) {
            return ['success' => false, 'message' => 'Akses ditolak'];
        }

        return [
            'success' => true,
            'path' => $path,
            'name' => $filename,
            'size' => filesize($path),
            'mime' => $this->getMimeType($path)
        ];
    }

    private function sanitizeCategory($category) {
        $allowed = array_keys($this->file_types);
        return in_array($category, $allowed) ? $category : 'dokumen';
    }

    private function sanitizeUsername($username) {
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $username ?? '');
    }

    private function sanitizeFilename($filename) {
        $filename = basename($filename);
        return preg_replace("/[^a-zA-Z0-9\._-]/", "_", $filename);
    }

    private function detectCategory($ext) {
        $ext = strtolower($ext);
        foreach ($this->file_types as $category => $extensions) {
            if (in_array($ext, $extensions)) {
                return $category;
            }
        }
        return 'dokumen';
    }

    private function getUniqueFilename($dir, $filename) {
        $path = $dir . '/' . $filename;
        if (!file_exists($path)) {
            return $filename;
        }

        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $counter = 1;

        while (file_exists($dir . '/' . $name . "_(" . $counter . ")." . $ext)) {
            $counter++;
        }

        return $name . "_(" . $counter . ")." . $ext;
    }

    private function isPathSafe($path, $allowed_dir) {
        $real_path = realpath($path);
        $real_dir = realpath($allowed_dir);
        
        if ($real_path === false || $real_dir === false) {
            return false;
        }

        return strpos($real_path, $real_dir) === 0;
    }

    private function getMimeType($filepath) {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filepath);
        }
        
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        $mimes = [
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip'
        ];
        
        return $mimes[$ext] ?? 'application/octet-stream';
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function calculateDirectorySize($dir) {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                $size += filesize($path);
            } elseif (is_dir($path)) {
                $size += $this->calculateDirectorySize($path);
            }
        }
        
        return $size;
    }

    public function getStatistics($scope = 'public', $username = null) {
        $all_files = $this->getAllFiles($scope, $username);
        
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'by_category' => []
        ];

        foreach ($all_files as $category => $files) {
            $category_size = 0;
            $category_count = count($files);
            
            foreach ($files as $file) {
                $category_size += $file['size'];
            }
            
            $stats['total_files'] += $category_count;
            $stats['total_size'] += $category_size;
            $stats['by_category'][$category] = [
                'count' => $category_count,
                'size' => $category_size,
                'sizeFormatted' => $this->formatBytes($category_size)
            ];
        }

        return $stats;
    }
}
