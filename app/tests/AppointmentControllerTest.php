<?php
define("APPPATH", __DIR__ . '/..');
define('PHPUNIT_TEST', true);
require_once APPPATH . '/autoload.php';
require_once APPPATH . '/helpers/common.helper.php';
require_once APPPATH . '/config/db.config.php';

use PHPUnit\Framework\TestCase;

class AppointmentControllerTest extends TestCase
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
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "notifications");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "patients");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "doctors");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "specialities");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "rooms");

        // Insert sample data
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name, role, speciality_id, room_id) VALUES (1, 'doctor1@example.com', 'Doctor 1', 'admin', 1, 1)");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name, role, speciality_id, room_id) VALUES (2, 'doctor2@example.com', 'Doctor 2', 'member', 1, 1)");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "specialities (id, name, image, description) VALUES (1, 'Speciality 1', 'image.jpg', 'Description')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "rooms (id, name, location) VALUES (1, 'Room 1', 'Location 1')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name, phone) VALUES (1, 'Patient 1', '0123456789')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, patient_birthday, patient_reason, date, appointment_time, status, create_at, update_at) VALUES (1, 1, 1, 'Patient 1', '0123456789', '1990-01-01', 'Reason', '2025-04-10', '2025-04-10 14:00:00', 'processing', '2025-04-10 10:00:00', '2025-04-10 10:00:00')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, date, status, create_at, update_at) VALUES (2, 2, 1, 'Patient 1', '0123456789', '2025-04-09', 'processing', '2025-04-09 10:00:00', '2025-04-09 10:00:00')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, date, status, create_at, update_at) VALUES (3, 1, 1, 'Patient 1', '0123456789', '2025-04-10', 'cancelled', '2025-04-10 10:00:00', '2025-04-10 10:00:00')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, date, status, create_at, update_at) VALUES (4, 1, 1, 'Patient 1', '0123456789', '2025-04-10', 'done', '2025-04-10 10:00:00', '2025-04-10 10:00:00')");

        $this->controller = new AppointmentController();
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

    private function mockRoute($id = null): void
    {
        $Route = new stdClass();
        if ($id !== null) {
            $Route->params = new stdClass();
            $Route->params->id = $id;
        }
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

            public function put($key)
            {
                return $this->method === 'PUT' ? (array_key_exists($key, $this->data) ? $this->data[$key] : null) : null;
            }

            public function patch($key)
            {
                return $this->method === 'PATCH' ? ($this->data[$key] ?? null) : null;
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

        if ($method === 'PUT') {
            global $_PUT;
            $_PUT = $data;
        } elseif ($method === 'PATCH') {
            global $_PATCH;
            $_PATCH = $data;
        }
    }

    // === Test Cases for process() ===

    /** Test 1: Process with no AuthUser */
    // public function testProcessWithNoAuthUser(): void
    // {
    //     $this->controller->setVariable("AuthUser", null);
    //     $this->mockRoute(1);
    //     $this->mockInput("GET");

    //     ob_start();
    //     $this->controller->process();
    //     $output = ob_get_clean();

    //     $this->assertEmpty($output); // Redirects to login
    // }

    /** Test 2: Process with invalid role */
    // public function testProcessWithInvalidRole(): void
    // {
    //     $this->mockAuthUser("member");
    //     $this->mockRoute(1);
    //     $this->mockInput("GET");

    //     ob_start();
    //     $this->controller->process();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(0, $result->result);
    //     $this->assertEquals("You do not have permission to do this action !", $result->msg);
    // }

    // === Test Cases for getById() ===

    /** Test 3: Get appointment by valid ID */
    public function testGetByIdWithValidId(): void
    {
        $this->mockRoute(1);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== JSON OUTPUT TEST 1 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals("Action successfully !", $result->msg);
        $this->assertEquals(1, $result->data->id);
    }

    /** Test 4: Get appointment with missing ID */
    public function testGetByIdWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 2 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 5: Get appointment with invalid ID */
    public function testGetByIdWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 3 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment is not available", $result->msg);
    }

    // === Test Cases for update() ===

    /** Test 6: Update appointment with valid data */
    public function testUpdateWithValidData(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-11 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 4 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals("Appointment has been updated successfully !", $result->msg);
        $this->assertEquals("Updated Patient", $result->data->patient_name);
    }

    /** Test 7: Update appointment with missing ID */
    public function testUpdateWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("PUT", []);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 5 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 8: Update appointment with invalid ID */
    public function testUpdateWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("PUT", []);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment is not available", $result->msg);
    }

    /** Test 9: Update appointment with missing required field */
    public function testUpdateWithMissingRequiredField(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1'
            // Missing patient_name
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Missing field: patient_name", $result->msg);
    }

    /** Test 10: Update appointment with invalid doctor_id */
    public function testUpdateWithInvalidDoctorId(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '999',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available", $result->msg);
    }

    /** Test 11: Update appointment with invalid patient_id */
    public function testUpdateWithInvalidPatientId(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '999',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Patient is not available", $result->msg);
    }

    /** Test 12: Update appointment with invalid patient_name */
    public function testUpdateWithInvalidPatientName(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Invalid@Name',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("( Booking name ) Vietnamese name only has letters and space", $result->msg);
    }

    /** Test 13: Update appointment with invalid status */
    public function testUpdateWithInvalidStatus(): void
    {
        self::$pdo->exec("UPDATE " . TABLE_PREFIX . "appointments SET status = 'cancelled' WHERE id = 1");
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment's status is cancelled ! You can't do this action !", $result->msg);
    }

    /** Test 14: Update appointment with invalid phone */
    public function testUpdateWithInvalidPhone(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => 'abc',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Patient phone number is not a valid phone number. Please, try again !", $result->msg);
    }

    /** Test 15: Update appointment with short phone */
    public function testUpdateWithShortPhone(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '123',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Patient phone number has at least 10 number !", $result->msg);
    }

    /** Test 16: Update appointment with invalid appointment_time */
    public function testUpdateWithInvalidAppointmentTime(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => 'invalid-time'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Invalid appointment time format", $result->msg);
    }

    /** Test 17: Update appointment with past date */
    public function testUpdateWithPastDate(): void
    {
        $this->mockRoute(2);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-09 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Today is 2025-04-10 but this appointment's is 2025-04-09", $result->msg);
    }

    /** Test 18: Update appointment with member role (same doctor) */
    public function testUpdateWithMemberRoleSameDoctor(): void
    {
        $this->mockAuthUser("member", 1);
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Appointment has been updated successfully !", $result->msg);
    }

    // === Test Cases for confirm() ===

    /** Test 19: Confirm appointment to done status */
    public function testConfirmToDone(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['status' => 'done']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("The status of appointment has been updated successfully !", $result->msg);
    }

    /** Test 20: Confirm appointment to cancelled status */
    public function testConfirmToCancelled(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['status' => 'cancelled']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("The status of appointment has been updated successfully !", $result->msg);
    }

    /** Test 21: Confirm appointment with missing ID */
    public function testConfirmWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("PATCH", ['status' => 'done']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 22: Confirm appointment with invalid ID */
    public function testConfirmWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("PATCH", ['status' => 'done']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment is not available", $result->msg);
    }

    /** Test 23: Confirm appointment with missing status */
    public function testConfirmWithMissingStatus(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", []);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Missing new status", $result->msg);
    }

    /** Test 24: Confirm appointment with invalid new status */
    public function testConfirmWithInvalidNewStatus(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['status' => 'invalid']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("The new status of appointment is not valid", $result->msg);
    }

    /** Test 25: Confirm appointment with status already cancelled */
    public function testConfirmWithStatusCancelled(): void
    {
        self::$pdo->exec("UPDATE " . TABLE_PREFIX . "appointments SET status = 'cancelled' WHERE id = 1");
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['status' => 'done']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment's status is cancelled ! You can't do this action !", $result->msg);
    }

    /** Test 26: Confirm appointment with past date */
    public function testConfirmWithPastDate(): void
    {
        $this->mockRoute(2);
        $this->mockInput("PATCH", ['status' => 'done']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Today is 2025-04-10 but this appointment's is 2025-04-09", $result->msg);
    }

    /** Test 27: Confirm appointment with member role, different doctor */
    public function testConfirmWithMemberRoleDifferentDoctor(): void
    {
        $this->mockAuthUser("member", 1);
        $this->mockRoute(2); // Appointment belongs to doctor_id 2
        $this->mockInput("PATCH", ['status' => 'done']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("This appointment belongs to doctor Doctor 2", $result->msg);
    }

    // === Test Cases for delete() ===

    /** Test 28: Delete appointment with valid data */
    public function testDeleteWithValidData(): void
    {
        $this->mockRoute(1);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Appointment is deleted successfully !", $result->msg);
    }

    /** Test 29: Delete appointment with missing ID */
    public function testDeleteWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 30: Delete appointment with invalid ID */
    public function testDeleteWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment is not available", $result->msg);
    }

    /** Test 31: Delete appointment with invalid role */
    public function testDeleteWithInvalidRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockRoute(1);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("You are member or supporter & you can't do this action !", $result->msg);
    }

    /** Test 32: Delete appointment with done status */
    public function testDeleteWithDoneStatus(): void
    {
        $this->mockRoute(4);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Appointment's status is done now. You can not delete!", $result->msg);
    }

    // === Edge Cases ===

    /** Test 33: Update with past appointment date */
    public function testUpdateWithPastAppointmentDate(): void
    {
        $this->mockRoute(2);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-09 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Today is 2025-04-10 but this appointment's is 2025-04-09", $result->msg);
    }

    /** Test 34: Update with invalid birthday */
    public function testUpdateWithInvalidBirthday(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => 'Updated Patient',
            'patient_birthday' => 'invalid-date',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Invalid birthday format", $result->msg);
    }

    /** Test 35: Update with null values in required fields */
    public function testUpdateWithNullRequiredFields(): void
    {
        $this->mockRoute(1);
        $data = [
            'doctor_id' => null,
            'patient_id' => null,
            'patient_name' => null
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Missing field", $result->msg);
    }

    /** Test 36: Confirm with SQL injection attempt in status */
    public function testConfirmWithSqlInjectionInStatus(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['status' => "done'; DROP TABLE " . TABLE_PREFIX . "appointments; --"]);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("The new status of appointment is not valid", $result->msg);
    }

    /** Test 37: Update with long patient_name */
    public function testUpdateWithLongPatientName(): void
    {
        $this->mockRoute(1);
        $longName = str_repeat("Patient ", 50);
        $data = [
            'doctor_id' => '1',
            'patient_id' => '1',
            'patient_name' => $longName,
            'patient_birthday' => '1990-01-01',
            'patient_reason' => 'Updated Reason',
            'patient_phone' => '0987654321',
            'appointment_time' => '2025-04-10 15:00:00'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result); // Assumes DB handles truncation
    }
}