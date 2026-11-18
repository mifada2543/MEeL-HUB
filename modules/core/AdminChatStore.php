<?php

final class AdminChatStore
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 2) . '/storage/chats';
    }

    public function get(int $userId): array
    {
        return $this->locked($userId, LOCK_SH, function (string $file): array {
            return $this->read($file);
        });
    }

    public function send(int $userId, string $message, ?callable $afterPersist = null): array
    {
        $message = trim($message);
        $length = preg_match_all('/./us', $message);
        if ($message === '' || $length === false || $length > 5000) {
            throw new RuntimeException('Invalid message (maximum 5000 characters)', 400);
        }
        return $this->locked($userId, LOCK_EX, function (string $file) use ($message, $afterPersist): array {
            $snapshot = $this->read($file);
            $messages = $snapshot['messages'];
            $appended = ['from' => 'admin', 'message' => $message, 'created_at' => date('Y-m-d H:i:s'), 'id' => bin2hex(random_bytes(16))];
            $messages[] = $appended;
            $saved = $this->publish($file, $messages);
            $this->afterPersist($afterPersist, $appended);
            return $saved;
        });
    }

    public function delete(int $userId, int $index, string $revision, ?callable $afterPersist = null): array
    {
        if ($index < 0 || !preg_match('/^[a-f0-9]{64}$/D', $revision)) {
            throw new RuntimeException('Invalid index or revision', 400);
        }
        return $this->locked($userId, LOCK_EX, function (string $file) use ($index, $revision, $afterPersist): array {
            $snapshot = $this->read($file);
            if (!hash_equals($snapshot['revision'], $revision)) {
                throw new RuntimeException('Chat changed; reload before deleting', 409);
            }
            $messages = $snapshot['messages'];
            if (!isset($messages[$index])) {
                throw new RuntimeException('Pesan tidak ditemukan', 404);
            }
            if ($messages[$index]['from'] !== 'admin') {
                throw new RuntimeException('Hanya bisa hapus pesan admin', 403);
            }
            $deleted = $messages[$index];
            array_splice($messages, $index, 1);
            $saved = $this->publish($file, $messages);
            $this->afterPersist($afterPersist, $deleted);
            return $saved + ['deleted_message' => $deleted['message']];
        });
    }

    private function afterPersist(?callable $callback, array $message): void
    {
        if ($callback === null) {
            return;
        }
        try {
            $callback($message);
        } catch (Throwable $e) {
            error_log('[admin chat] History saved; notification callback failed: ' . $e->getMessage());
        }
    }

    private function locked(int $userId, int $mode, callable $operation): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Invalid user', 400);
        }
        $dir = $this->root . '/' . $userId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create chat directory', 500);
        }
        // Lock a stable sidecar, not the JSON inode replaced by rename.
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open chat lock', 500);
        }
        try {
            if (!flock($lock, $mode)) {
                throw new RuntimeException('Cannot lock chat', 500);
            }
            return $operation($dir . '/isipesan.json');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(string $file): array
    {
        $raw = '[]';
        if (file_exists($file) || is_link($file)) {
            if (!is_file($file) || ($raw = @file_get_contents($file)) === false) {
                throw new RuntimeException('Cannot read chat history', 500);
            }
        }
        try {
            $messages = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Corrupt chat history', 500, $e);
        }
        if (!is_array($messages) || ($messages !== [] && array_keys($messages) !== range(0, count($messages) - 1)) || substr(ltrim($raw), 0, 1) !== '[') {
            throw new RuntimeException('Corrupt chat history', 500);
        }
        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['from'], $message['message'], $message['created_at']) || !is_string($message['from']) || !is_string($message['message']) || !is_string($message['created_at'])) {
                throw new RuntimeException('Corrupt chat history', 500);
            }
        }
        return ['messages' => $messages, 'revision' => hash('sha256', $raw)];
    }

    private function publish(string $file, array $messages): array
    {
        try {
            $raw = json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Cannot serialize chat history', 500, $e);
        }
        $temp = @tempnam(dirname($file), '.chat-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create chat snapshot', 500);
        }
        try {
            if (dirname($temp) !== realpath(dirname($file)) || @file_put_contents($temp, $raw) !== strlen($raw) || !@chmod($temp, 0644) || !@rename($temp, $file)) {
                throw new RuntimeException('Cannot persist chat history', 500);
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        return ['messages' => $messages, 'revision' => hash('sha256', $raw)];
    }
}
