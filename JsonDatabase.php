<?php
class JsonDatabase {
    private $dataDir;
    private static $instance = null;
    private $lockFiles = [];

    private function __construct() {
        $this->dataDir = __DIR__ . '/../../database/json';
        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir, 0777, true);
        }
        $this->initializeDatabase();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeDatabase() {
        $tables = ['users', 'tasks', 'teams', 'templates'];
        foreach ($tables as $table) {
            $filePath = $this->getTablePath($table);
            if (!file_exists($filePath)) {
                file_put_contents($filePath, json_encode([]));
            }
        }
    }

    private function getTablePath($table) {
        return $this->dataDir . '/' . $table . '.json';
    }

    private function acquireLock($table) {
        $lockFile = $this->getTablePath($table) . '.lock';
        $handle = fopen($lockFile, 'w+');
        
        if (!$handle) {
            throw new Exception("Could not create lock file for table: $table");
        }

        $maxAttempts = 10;
        $attempts = 0;
        
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (++$attempts === $maxAttempts) {
                fclose($handle);
                throw new Exception("Could not acquire lock for table: $table");
            }
            usleep(100000); // Wait 0.1 seconds before retrying
        }

        $this->lockFiles[$table] = $handle;
        return true;
    }

    private function releaseLock($table) {
        if (isset($this->lockFiles[$table])) {
            flock($this->lockFiles[$table], LOCK_UN);
            fclose($this->lockFiles[$table]);
            unset($this->lockFiles[$table]);
        }
    }

    public function query($table, $conditions = []) {
        try {
            $this->acquireLock($table);
            $data = json_decode(file_get_contents($this->getTablePath($table)), true) ?: [];
            
            if (empty($conditions)) {
                return $data;
            }

            return array_filter($data, function($item) use ($conditions) {
                foreach ($conditions as $key => $value) {
                    if (!isset($item[$key]) || $item[$key] != $value) {
                        return false;
                    }
                }
                return true;
            });
        } finally {
            $this->releaseLock($table);
        }
    }

    public function insert($table, $data) {
        try {
            $this->acquireLock($table);
            $filePath = $this->getTablePath($table);
            $tableData = json_decode(file_get_contents($filePath), true) ?: [];
            
            $data['id'] = empty($tableData) ? 1 : max(array_column($tableData, 'id')) + 1;
            $data['created_at'] = date('Y-m-d H:i:s');
            $data['updated_at'] = date('Y-m-d H:i:s');
            
            $tableData[] = $data;
            file_put_contents($filePath, json_encode($tableData, JSON_PRETTY_PRINT));
            
            return $data['id'];
        } finally {
            $this->releaseLock($table);
        }
    }

    public function update($table, $id, $data) {
        try {
            $this->acquireLock($table);
            $filePath = $this->getTablePath($table);
            $tableData = json_decode(file_get_contents($filePath), true) ?: [];
            
            foreach ($tableData as &$item) {
                if ($item['id'] == $id) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                    $item = array_merge($item, $data);
                    file_put_contents($filePath, json_encode($tableData, JSON_PRETTY_PRINT));
                    return true;
                }
            }
            
            return false;
        } finally {
            $this->releaseLock($table);
        }
    }

    public function delete($table, $id) {
        try {
            $this->acquireLock($table);
            $filePath = $this->getTablePath($table);
            $tableData = json_decode(file_get_contents($filePath), true) ?: [];
            
            $tableData = array_filter($tableData, function($item) use ($id) {
                return $item['id'] != $id;
            });
            
            file_put_contents($filePath, json_encode(array_values($tableData), JSON_PRETTY_PRINT));
            return true;
        } finally {
            $this->releaseLock($table);
        }
    }

    public function __destruct() {
        // Release any remaining locks
        foreach (array_keys($this->lockFiles) as $table) {
            $this->releaseLock($table);
        }
    }
}