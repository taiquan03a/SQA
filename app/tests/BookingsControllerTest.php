<?php
define("APPPATH", __DIR__ . '/..');
define('PHPUNIT_TEST', true);
require_once APPPATH . '/autoload.php';
require_once APPPATH . '/helpers/common.helper.php';
require_once APPPATH . '/config/db.config.php';

use PHPUnit\Framework\TestCase;

class BookingsControllerTest extends TestCase
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
        if (!self::$pdo->inTransaction()) {
            self::$pdo->beginTransaction();
        }

        // Clear existing data to avoid foreign key violations
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "treatments");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "appointment_records");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "appointments");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "booking_photo");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "notifications");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . TABLE_BOOKINGS);
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "doctor_and_service");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "patients");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "doctors");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "services");

        // Insert sample data
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name) VALUES (1, 'doctor@example.com', 'Test Doctor')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "services (id, name) VALUES (1, 'Service Test')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name) VALUES (1, 'Nguyen Van A')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . TABLE_BOOKINGS . " (id, patient_id, service_id, doctor_id, booking_name, appointment_date, status) VALUES (1, 1, 1, 1, 'Nguyen Van A', '2025-04-15', 'verified')");

        $this->controller = new BookingsController();
        $this->mockAuthUser();
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

    private function mockAuthUser(string $role = "admin", string $name = "Test Doctor"): void
    {
        $AuthUser = new class($role, $name) {
            private $role;
            private $name;

            public function __construct($role, $name)
            {
                $this->role = $role;
                $this->name = $name;
            }

            public function get($property)
            {
                return $property === "role" ? $this->role : ($property === "name" ? $this->name : null);
            }
        };
        $this->controller->setVariable("AuthUser", $AuthUser);
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
                return $this->method === 'POST' ? ($this->data[$key] ?? null) : null;
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
            $_POST = $data;
        }
    }

    // === Test Cases for getAll() ===

    /** Test 1: Get all bookings with supporter role */
    public function testGetAllWithSupporterRole(): void
    {
        $this->mockAuthUser("supporter");
        $this->mockInput("GET", ['length' => '10', 'start' => '0']);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 1 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertNotEmpty($result->data);
    }

    /** Test 2: Get all bookings with member role */
    public function testGetAllWithMemberRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockInput("GET", []);

        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 2 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertNotEmpty($result->data);
    }

    /** Test 3: Get all bookings with no auth user */
    public function testGetAllWithNoAuthUser(): void
    {
        $this->controller->setVariable("AuthUser", '');
        ob_start();
        $this->controller->process();
        $output = ob_get_clean();
        $this->assertEmpty($output); // Should redirect to login
    }

    /** Test 4: Get bookings with search filter matching booking_name */
    public function testGetAllWithSearchFilter(): void
    {
        $this->mockInput("GET", ['search' => 'Nguyen Van A']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 4 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
        $this->assertEquals("Nguyen Van A", $result->data[0]->booking_name);
    }

    /** Test 5: Get bookings with invalid search filter */
    public function testGetAllWithInvalidSearchFilter(): void
    {
        $this->mockInput("GET", ['search' => 'InvalidName']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 5 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(0, count($result->data));
    }

    /** Test 6: Get bookings with service_id filter */
    public function testGetAllWithServiceIdFilter(): void
    {
        $this->mockInput("GET", ['service_id' => '1']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 6 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
    }

    /** Test ７: Get bookings with invalid service_id */
    public function testGetAllWithInvalidServiceId(): void
    {
        $this->mockInput("GET", ['service_id' => '999']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 7 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(0, count($result->data));
    }

    /** Test 8: Get bookings with status filter */
    public function testGetAllWithStatusFilter(): void
    {
        $this->mockInput("GET", ['status' => 'verified']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 8 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
    }

    /** Test 9: Get bookings with invalid status */
    public function testGetAllWithInvalidStatus(): void
    {
        $this->mockInput("GET", ['status' => 'invalid']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 9 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(0, count($result->data));
    }

    /** Test 10: Get bookings with appointment_date filter */
    public function testGetAllWithAppointmentDateFilter(): void
    {
        $this->mockInput("GET", ['appointment_date' => '2025-04-15']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 10 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->quantity);
    }

    /** Test 11: Get bookings with invalid appointment_date */
    public function testGetAllWithInvalidAppointmentDate(): void
    {
        $this->mockInput("GET", ['appointment_date' => '2025-01-01']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 11 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(0, count($result->data));
    }

    /** Test 12: Get bookings with pagination (length=1, start=0) */
    public function testGetAllWithPagination(): void
    {
        $this->mockInput("GET", ['length' => '1', 'start' => '0']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 12 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, count($result->data));
        $this->assertEquals(1, $result->quantity);
    }

    /** Test 13: Get bookings with order by id ascending */
    public function testGetAllWithOrderByIdAsc(): void
    {
        $this->mockInput("GET", ['order' => ['column' => 'id', 'dir' => 'asc']]);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertEquals(1, $result->data[0]->id);
    }

    /** Test 14: Get bookings with invalid order direction */
    public function testGetAllWithInvalidOrderDirection(): void
    {
        $this->mockInput("GET", ['order' => ['column' => 'id', 'dir' => 'invalid']]);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result); // Falls back to default desc
    }

    /** Test 15: Get bookings with empty input */
    public function testGetAllWithEmptyInput(): void
    {
        $this->mockInput("GET", []);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertNotEmpty($result->data);
    }

    // === Test Cases for save() ===

    /** Test 16: Save with supporter role */
    public function testSaveWithSupporterRole(): void
    {
        $this->mockAuthUser("supporter");
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
    }

    /** Test 17: Save with member role (unauthorized) */
    public function testSaveWithMemberRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockInput("POST", []);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("You don't have permission", $result->msg);
    }

    /** Test 18: Save with invalid patient_id */
    public function testSaveWithInvalidPatientId(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'patient_id' => '999'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Patient is not available", $result->msg);
    }

    /** Test 19: Save with invalid service_id */
    public function testSaveWithInvalidServiceId(): void
    {
        $data = [
            'service_id' => '999',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Service is not available", $result->msg);
    }

    /** Test 20: Save with invalid booking_name (non-Vietnamese) */
    public function testSaveWithInvalidBookingName(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'John123',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Vietnamese name only has letters and space", $result->msg);
    }

    /** Test 21: Save with short booking_phone */
    public function testSaveWithShortBookingPhone(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '01234',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Booking number has at least 10 number !", $result->msg);
    }

    /** Test 22: Save with invalid booking_phone (non-numeric) */
    public function testSaveWithInvalidBookingPhone(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => 'abc1234567',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Booking phone is not a valid phone number. Please, try again !", $result->msg);
    }

    /** Test 23: Save with invalid name (non-Vietnamese) */
    public function testSaveWithInvalidName(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'John123',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Vietnamese name only has letters and space", $result->msg);
    }

    /** Test 24: Save with invalid gender */
    public function testSaveWithInvalidGender(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'gender' => '2'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Gender is not valid. There are 2 values: 0 is female & 1 is men", $result->msg);
    }

    /** Test 25: Save with invalid birthday */
    public function testSaveWithInvalidBirthday(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'birthday' => '2026-01-01' // Future date
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertNotEmpty($result->msg);
    }

    /** Test 26: Save with invalid address */
    public function testSaveWithInvalidAddress(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'address' => '123@#$%'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Address only accepts letters, space & number", $result->msg);
    }

    /** Test 27: Save with past appointment_date */
    public function testSaveWithPastAppointmentDate(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2020-01-01'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertNotEmpty($result->msg);
    }

    /** Test 28: Save with invalid appointment_time */
    public function testSaveWithInvalidAppointmentTime(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '25:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertNotEmpty($result->msg);
    }

    /** Test 29: Save with invalid doctor_id */
    public function testSaveWithInvalidDoctorId(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'doctor_id' => '999'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available", $result->msg);
    }

    /** Test 30: Save with minimal required fields */
    public function testSaveWithMinimalRequiredFields(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
    }

    /** Test 31: Save with all optional fields */
    public function testSaveWithAllOptionalFields(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'doctor_id' => '1',
            'patient_id' => '1',
            'gender' => '1',
            'birthday' => '1990-01-01',
            'address' => '123 Duong ABC',
            'reason' => 'Kham suc khoe'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
    }

    /** Test 32: Save with empty optional fields */
    public function testSaveWithEmptyOptionalFields(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'doctor_id' => '',
            'patient_id' => '',
            'gender' => '',
            'birthday' => '',
            'address' => '',
            'reason' => ''
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
    }

    /** Test 33: Save with duplicate booking (same time, doctor) */
    public function testSaveWithDuplicateBooking(): void
    {
        // Insert an existing booking
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . TABLE_BOOKINGS . " (doctor_id, service_id, patient_id, booking_name, booking_phone, name, appointment_date, appointment_time, status) VALUES (1, 1, 1, 'Nguyen Van B', '0123456789', 'Nguyen Van B', '2025-04-16', '14:00', 'verified')");

        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van C',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van C',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'doctor_id' => '1'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result); // No explicit duplicate check in code
    }

    /** Test 34: Save with long booking_name */
    public function testSaveWithLongBookingName(): void
    {
        $longName = str_repeat("Nguyen ", 50);
        $data = [
            'service_id' => '1',
            'booking_name' => $longName,
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result); // Assumes DB handles truncation
    }

    /** Test 35: Save with special characters in reason */
    public function testSaveWithSpecialCharactersInReason(): void
    {
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0123456789',
            'name' => 'Nguyen Van B',
            'appointment_time' => '14:00',
            'appointment_date' => '2025-04-16',
            'reason' => 'Kham suc khoe @#$%'
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
    }

    // === Edge Cases ===

    /** Test 36: Get all with negative length */
    public function testGetAllWithNegativeLength(): void
    {
        $this->mockInput("GET", ['length' => '-1', 'start' => '0']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertNotEmpty($result->data); // Treated as no limit
    }

    /** Test 37: Get all with negative start */
    public function testGetAllWithNegativeStart(): void
    {
        $this->mockInput("GET", ['length' => '10', 'start' => '-1']);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertNotEmpty($result->data); // Treated as 0
    }

    /** Test 38: Save with null values in required fields */
    public function testSaveWithNullRequiredFields(): void
    {
        $data = [
            'service_id' => null,
            'booking_name' => null,
            'booking_phone' => null,
            'name' => null,
            'appointment_time' => null,
            'appointment_date' => null
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Missing field", $result->msg);
    }

    /** Test 39: Save with empty string in required fields */
    public function testSaveWithEmptyStringRequiredFields(): void
    {
        $data = [
            'service_id' => '',
            'booking_name' => '',
            'booking_phone' => '',
            'name' => '',
            'appointment_time' => '',
            'appointment_date' => ''
        ];
        $this->mockInput("POST", $data);
        ob_start();
        $this->controller->save();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Missing field", $result->msg);
    }

    /** Test 40: Get all with SQL injection attempt in search */
    public function testGetAllWithSqlInjectionInSearch(): void
    {
        $this->mockInput("GET", ['search' => "'; DROP TABLE " . TABLE_PREFIX . TABLE_BOOKINGS . "; --"]);
        ob_start();
        $this->controller->getAll();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 13 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result); // Query should still execute safely
        $this->assertEquals(0, count($result->data)); // No matches
    }
}
