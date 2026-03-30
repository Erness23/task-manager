<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(http_response_code(200));

class TaskAPI {
    private $db;

    public function __construct() {
        // Fallback to XAMPP defaults if Railway environment variables are not found
        $host = getenv('MYSQLHOST') ?: 'localhost';
        $port = getenv('MYSQLPORT') ?: '3306';
        $dbname = getenv('MYSQLDATABASE') ?: 'task_manager';
        $username = getenv('MYSQLUSER') ?: 'root';
        $password = getenv('MYSQLPASSWORD') ?: '';

        try {
            $this->db = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $username, $password);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            $this->resp(500, ["message" => "DB Error: " . $e->getMessage()]);
        }
    }

    // --- HELPER: Centralized JSON Responder ---
    private function resp($code, $data) {
        http_response_code($code);
        echo json_encode($data);
        exit;
    }

    // --- HELPER: Get incoming JSON payload ---
    private function getInput() {
        return json_decode(file_get_contents("php://input"));
    }

    // --- HELPER: Find a task by ID ---
    private function getTask($id) {
        $stmt = $this->db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // --- ROUTER ---
    public function handle() {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = explode('/', trim($_SERVER['PATH_INFO'] ?? '/', '/'));
        $id = $uri[2] ?? null;

        if (($uri[1] ?? '') !== 'tasks') $this->resp(404, ["message" => "Endpoint not found."]);

        if ($method === 'POST') $this->create();
        elseif ($method === 'GET' && $id === 'report') $this->report();
        elseif ($method === 'GET') $this->list();
        elseif ($method === 'PATCH' && $id && ($uri[3] ?? '') === 'status') $this->update($id);
        elseif ($method === 'DELETE' && $id) $this->delete($id);
        else $this->resp(405, ["message" => "Method not allowed."]);
    }

    // --- 1. CREATE ---
    private function create() {
        $d = $this->getInput();
        if (!isset($d->title, $d->due_date, $d->priority)) $this->resp(400, ["message" => "Incomplete data."]);
        if ($d->due_date < date("Y-m-d")) $this->resp(400, ["message" => "Date must be today or later."]);
        if (!in_array($d->priority, ['low', 'medium', 'high'])) $this->resp(400, ["message" => "Invalid priority."]);

        try {
            $stmt = $this->db->prepare("INSERT INTO tasks (title, due_date, priority) VALUES (?, ?, ?)");
            $stmt->execute([$d->title, $d->due_date, $d->priority]);
            $this->resp(201, $this->getTask($this->db->lastInsertId()));
        } catch (PDOException $e) {
            $this->resp($e->errorInfo[1] == 1062 ? 409 : 500, ["message" => "Database error or duplicate task."]);
        }
    }

    // --- 2. LIST ---
    private function list() {
        $status = $_GET['status'] ?? null;
        $sql = "SELECT * FROM tasks" . ($status ? " WHERE status = ?" : "") . " ORDER BY FIELD(priority, 'high', 'medium', 'low'), due_date ASC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($status ? [$status] : []);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->resp(200, count($tasks) ? $tasks : ["message" => "No tasks found.", "data" => []]);
    }

    // --- 3. UPDATE STATUS ---
    private function update($id) {
        $d = $this->getInput();
        if (!isset($d->status)) $this->resp(400, ["message" => "Status required."]);
        
        $task = $this->getTask($id);
        if (!$task) $this->resp(404, ["message" => "Task not found."]);

        $valid = ($task['status'] === 'pending' && $d->status === 'in_progress') || 
                 ($task['status'] === 'in_progress' && $d->status === 'done');
        if (!$valid) $this->resp(422, ["message" => "Invalid status transition."]);

        $stmt = $this->db->prepare("UPDATE tasks SET status = ? WHERE id = ?");
        if ($stmt->execute([$d->status, $id])) $this->resp(200, ["message" => "Status updated."]);
    }

    // --- 4. DELETE ---
    private function delete($id) {
        $task = $this->getTask($id);
        if (!$task) $this->resp(404, ["message" => "Task not found."]);
        if ($task['status'] !== 'done') $this->resp(403, ["message" => "Only 'done' tasks can be deleted."]);

        $stmt = $this->db->prepare("DELETE FROM tasks WHERE id = ?");
        if ($stmt->execute([$id])) $this->resp(200, ["message" => "Task deleted."]);
    }

    // --- 5. REPORT ---
    private function report() {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = $this->db->prepare("SELECT priority, status, COUNT(*) as c FROM tasks WHERE due_date = ? GROUP BY priority, status");
        $stmt->execute([$date]);
        
        // Pre-fill the summary array with zeros
        $sum = array_fill_keys(['high', 'medium', 'low'], ['pending' => 0, 'in_progress' => 0, 'done' => 0]);
        
        // Populate with actual database counts
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sum[$row['priority']][$row['status']] = (int)$row['c'];
        }
        
        $this->resp(200, ["date" => $date, "summary" => $sum]);
    }
}

// Instantiate and Run
(new TaskAPI())->handle();