<?php
define("APPPATH", __DIR__ . '/..');
define('PHPUNIT_TEST', true);
require_once APPPATH . '/autoload.php';
require_once APPPATH . '/helpers/common.helper.php';
require_once APPPATH . '/config/db.config.php';

use PHPUnit\Framework\TestCase;

class AppointmentQueueControllerTest extends TestCase
{
    private static $pdo;
    private static $connection;
    private $controller;

    public static function setUpBeforeClass(): void
    {
        $config = [
            'driver' => 'mysql',
            'host' => DB_HOST,
            'database' => DB_NAME,
            'username' => DB_USER,
            'password' => DB_PASS,
            'charset' => DB_ENCODING,
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_AUTOCOMMIT => false
            ]
        ];

        self::$connection = new \Pixie\Connection('mysql', $config, 'DB');
        self::$pdo = self::$connection->getPdoInstance();
    }

    protected function setUp(): void
    {
        if (!defined('APPURL')) {
            define('APPURL', 'http://localhost');
        }

        if (!self::$pdo->inTransaction()) {
            self::$pdo->beginTransaction();
        }
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Clear existing data
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "appointments");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "doctors");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "patients");

        // Insert sample data
        // Insert patients
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name, phone) VALUES (1, 'Patient 1', '0123456789')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name, phone) VALUES (2, 'Patient 2', '0987654321')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name, phone) VALUES (3, 'Patient 3', '0123456780')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name, phone) VALUES (4, 'Patient 4', '0123456781')");

        // Insert doctors
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name, role, active) VALUES (1, 'doctor1@example.com', 'Doctor 1', 'admin', 1)");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name, role, active) VALUES (2, 'doctor2@example.com', 'Doctor 2', 'member', 1)");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name, role, active) VALUES (3, 'doctor3@example.com', 'Doctor 3', 'admin', 0)");

        // Insert appointments with valid patient_id
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, patient_reason, date, appointment_time, status, position, create_at, update_at) VALUES (1, 1, 1, 'Patient 1', '0123456789', 'Reason 1', '10-04-2025', '', 'processing', 1, '2025-04-10 10:00:00', '2025-04-10 10:00:00')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, patient_reason, date, appointment_time, status, position, create_at, update_at) VALUES (2, 1, 2, 'Patient 2', '0987654321', 'Reason 2', '10-04-2025', '', 'processing', 2, '2025-04-10 10:00:00', '2025-04-10 10:00:00')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, patient_reason, date, appointment_time, status, position, create_at, update_at) VALUES (3, 2, 3, 'Patient 3', '0123456780', 'Reason 3', '10-04-2025', '', 'processing', 1, '2025-04-10 10:00:00', '2025-04-10 10:00:00')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, patient_reason, date, appointment_time, status, position, create_at, update_at) VALUES (4, 1, 4, 'Patient 4', '0123456781', 'Reason 4', '11-04-2025', '', 'done', 1, '2025-04-11 10:00:00', '2025-04-11 10:00:00')");

        // Commit the transaction to make the data visible to other connections
        self::$pdo->commit();

        // Start a new transaction for the test case
        self::$pdo->beginTransaction();

        $this->controller = new AppointmentQueueController();
        $this->mockAuthUser();
        $this->mockRoute();
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }

    protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $this->controller = null;
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
        self::$connection = null;
    }

    private function mockAuthUser(string $role = "admin", int $id = 1): void
    {
        $AuthUser = new class($role, $id) {
            private $role;
            private $id;

            public function __construct($role, $id)
            {
                $this->role = $role;
                $this->id = $id;
            }

            public function get($property)
            {
                return $property === "role" ? $this->role : ($property === "id" ? $this->id : null);
            }
        };
        $this->controller->setVariable("AuthUser", $AuthUser);
    }

    private function mockRoute(): void
    {
        $Route = new stdClass();
        $this->controller->setVariable("Route", $Route);
    }

    private function mockInput(string $method, array $data = []): void
    {
        $inputInstance = new class($method, $data) extends Input {
            private $method;
            private $data;

            public function __construct(string $method, array $data)
            {
                $this->method = strtoupper($method);
                $this->data = $data;
            }

            public function get($key)
            {
                return $this->method === 'GET' ? ($this->data[$key] ?? null) : null;
            }

            public function post($key)
            {
                return $this->method === 'POST' ? (array_key_exists($key, $this->data) ? $this->data[$key] : null) : null;
            }

            public function method(): string
            {
                return $this->method;
            }
        };

        $reflection = new \ReflectionClass($this->controller);
        $property = $reflection->getProperty('variables');
        $property->setAccessible(true);
        $variables = $property->getValue($this->controller);
        $variables['Input'] = $inputInstance;
        $property->setValue($this->controller, $variables);

        if ($method === 'POST') {
            global $_POST;
            $_POST = $data;
        }
    }

    // === Test Cases for process() ===

    /** Test 1: Process with no AuthUser */
    // public function testProcessWithNoAuthUser(): void
    // {
    //     $this->controller->setVariable("AuthUser", null);
    //     $this->mockInput("GET");

    //     ob_start();
    //     $this->controller->process();
    //     $output = ob_get_clean();

    //     $this->assertEmpty($output); // Redirects to login
    // }

    /** Test 2: Process with GET request and 'all' */
    // public function testProcessWithGetAll(): void
    // {
    //     $this->mockInput("GET", ["request" => "all"]);

    //     ob_start();
    //     $this->controller->process();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(1, $result->result);
    //     $this->assertEquals("All appointments", $result->msg);
    // }

    // /** Test 3: Process with GET request and 'queue' */
    // public function testProcessWithGetQueue(): void
    // {
    //     $this->mockInput("GET", ["request" => "queue"]);
    //     $this->mockAuthUser("admin", 1);

    //     ob_start();
    //     $this->controller->process();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(0, $result->result);
    //     $this->assertEquals("Missing doctor ID", $result->msg);
    // }

    // /** Test 4: Process with POST request */
    // public function testProcessWithPost(): void
    // {
    //     $this->mockInput("POST", ["doctor_id" => "1", "queue" => [1, 2]]);

    //     ob_start();
    //     $this->controller->process();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(1, $result->result);
    //     $this->assertEquals("Appointments have been updated their positions", $result->msg);
    // }

    // === Test Cases for getAll() ===

    /** Test 5: Get all appointments with default filters */
    public function testGetAllWithDefaultFilters(): void
    {
        $this->mockInput("GET");

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 5 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals("All appointments", $result->msg);
        $this->assertEquals(2, $result->quantity); // 2 appointments for doctor_id 1 on 2025-04-10
    }

    /** Test 6: Get all appointments with search filter */
    public function testGetAllWithSearchFilter(): void
    {
        $this->mockInput("GET", ["search" => "Patient 1"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
        $this->assertEquals("Patient 1", $result->data[0]->patient_name);
    }

    /** Test 7: Get all appointments with date filter */
    public function testGetAllWithDateFilter(): void
    {
        $this->mockInput("GET", ["date" => "10-04-2025"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("All appointments at 10-04-2025", $result->msg);
        $this->assertEquals(2, $result->quantity);
    }

    /** Test 8: Get all appointments with doctor_id filter (admin role) */
    public function testGetAllWithDoctorIdFilterAdmin(): void
    {
        $this->mockInput("GET", ["doctor_id" => "1"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("All appointments - doctor ID: 1 - Doctor 1", $result->msg);
        $this->assertEquals(2, $result->quantity);
    }

    /** Test 9: Get all appointments with doctor_id filter (member role) */
    public function testGetAllWithDoctorIdFilterMember(): void
    {
        $this->mockAuthUser("member", 2);
        $this->mockInput("GET", ["doctor_id" => "1"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("All appointments", $result->msg);
        $this->assertEquals(1, $result->quantity); // Only doctor_id 2's appointments
        $this->assertEquals(3, $result->data[0]->id);
    }

    /** Test 10: Get all appointments with status filter */
    public function testGetAllWithStatusFilter(): void
    {
        $this->mockInput("GET", ["status" => "processing"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals(2, $result->quantity);
    }

    /** Test 11: Get all appointments with order filter */
    public function testGetAllWithOrderFilter(): void
    {
        $this->mockInput("GET", ["order" => ["column" => "patient_name", "dir" => "asc"], "doctor_id" => "1"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertGreaterThan(0, $result->quantity, "No appointments found with the given filter");
        $this->assertEquals("Patient 1", $result->data[0]->patient_name);
    }

    /** Test 12: Get all appointments with invalid order direction */
    public function testGetAllWithInvalidOrderDirection(): void
    {
        $this->mockInput("GET", ["order" => ["column" => "patient_name", "dir" => "invalid"], "doctor_id" => "1"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertGreaterThan(0, $result->quantity, "No appointments found with the given filter");
        $this->assertEquals("Patient 2", $result->data[0]->patient_name); // Falls back to desc
    }

    /** Test 13: Get all appointments with length and start filter */
    public function testGetAllWithLengthAndStartFilter(): void
    {
        $this->mockInput("GET");

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
        $this->assertEquals(1, $result->data[0]->id);
    }

    // === Test Cases for arrange() ===

    /** Test 14: Arrange appointments with valid data */
    public function testArrangeWithValidData(): void
    {
        $this->mockInput("POST", ["doctor_id" => "1", "queue" => [1, 2]]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 14 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals("Appointments have been updated their positions", $result->msg);
    }

    /** Test 15: Arrange appointments with invalid role */
    public function testArrangeWithInvalidRole(): void
    {
        $this->mockAuthUser("member", 2);
        $this->mockInput("POST", ["doctor_id" => "1", "queue" => [1, 2]]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 15 ========\n";
        print_r($output);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Only admin, supporter can arrange appointments", $result->msg);
    }

    /** Test 16: Arrange appointments with missing doctor_id */
    public function testArrangeWithMissingDoctorId(): void
    {
        $this->mockInput("POST", ["queue" => [1, 2]]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Missing field: doctor_id", $result->msg);
    }

    /** Test 17: Arrange appointments with missing queue */
    public function testArrangeWithMissingQueue(): void
    {
        $this->mockInput("POST", ["doctor_id" => "1"]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Missing field: queue", $result->msg);
    }

    /** Test 18: Arrange appointments with invalid doctor_id */
    public function testArrangeWithInvalidDoctorId(): void
    {
        $this->mockInput("POST", ["doctor_id" => "999", "queue" => [1, 2]]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available !", $result->msg);
    }

    /** Test 19: Arrange appointments with inactive doctor */
    public function testArrangeWithInactiveDoctor(): void
    {
        $this->mockInput("POST", ["doctor_id" => "3", "queue" => [1, 2]]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("This doctor account was deactivated. No need this action !", $result->msg);
    }

    /** Test 20: Arrange appointments with invalid queue format */
    public function testArrangeWithInvalidQueueFormat(): void
    {
        $this->mockInput("POST", ["doctor_id" => "1", "queue" => "invalid"]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Queue's format is not valid.", $result->msg);
    }

    /** Test 21: Arrange appointments with mismatched doctor_id in queue */
    public function testArrangeWithMismatchedDoctorIdInQueue(): void
    {
        $this->mockInput("POST", ["doctor_id" => "1", "queue" => [3]]); // Appointment 3 belongs to doctor_id 2

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Appointments have been updated their positions", $result->msg);
    }

    // === Test Cases for getQueue() ===

    /** Test 22: Get queue with member role */
    public function testGetQueueWithMemberRole(): void
    {
        $this->mockAuthUser("member", 1);
        $this->mockInput("POST");

        ob_start();
        $this->controller->getQueue();
        $output = ob_get_clean();
        echo "\n\n======== JSON OUTPUT TEST 22 ========\n";
        print_r($output);
        $this->assertStringContainsString("current 1", $output);
        $this->assertStringContainsString("next 2", $output);
    }

    // /** Test 23: Get queue with admin role and missing doctor_id */
    // public function testGetQueueWithAdminRoleMissingDoctorId(): void
    // {
    //     $this->mockInput("POST");

    //     ob_start();
    //     $this->controller->getQueue();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);
    //     $this->assertEquals(0, $result->result);
    //     $this->assertEquals("Missing doctor ID", $result->msg);
    // }

    // === Edge Cases ===

    /** Test 24: Get all with invalid status filter */
    public function testGetAllWithInvalidStatusFilter(): void
    {
        $this->mockInput("GET", ["status" => "invalid"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 24 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(0, $result->quantity); // Status filter ignored
    }

    /** Test 25: Arrange with empty queue */
    public function testArrangeWithEmptyQueue(): void
    {
        $this->mockInput("POST", ["doctor_id" => "1", "queue" => []]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 25 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals("Missing field: queue", $result->msg);
    }

    /** Test 26: Get all with SQL injection attempt in search */
    public function testGetAllWithSqlInjectionInSearch(): void
    {
        $this->mockInput("GET", ["search" => "Patient 1'; DROP TABLE " . TABLE_PREFIX . "appointments; --"]);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
        $this->assertEquals("Patient 1", $result->data[0]->patient_name);
    }

    /** Test 27: Arrange with SQL injection attempt in queue */
    public function testArrangeWithSqlInjectionInQueue(): void
    {
        $this->mockInput("POST", ["doctor_id" => "1", "queue" => ["1'; DROP TABLE " . TABLE_PREFIX . "appointments; --"]]);

        ob_start();
        $this->controller->arrange();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 27 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals("Appointments have been updated their positions", $result->msg);
    }
}
