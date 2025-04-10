<?php
define("APPPATH", __DIR__ . '/..');
define('PHPUNIT_TEST', true);
require_once APPPATH . '/autoload.php';
require_once APPPATH . '/helpers/common.helper.php';
require_once APPPATH . '/config/db.config.php';

use PHPUnit\Framework\TestCase;

class BookingControllerTest extends TestCase
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
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        // Clear existing data
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "appointments");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . TABLE_BOOKINGS);
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "patients");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "doctors");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "services");

        // Insert sample data
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, name) VALUES (1, 'doctor@example.com', 'Test Doctor')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "services (id, name) VALUES (1, 'Service Test')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name) VALUES (1, 'Nguyen Van A')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . TABLE_BOOKINGS . " (id, patient_id, service_id, doctor_id, booking_name, booking_phone, name, appointment_date, appointment_time, status) VALUES (1, 1, 1, 1, 'Nguyen Van A', '0123456789', 'Nguyen Van A', '2025-04-15', '14:00', 'processing')");

        $this->controller = new BookingController();
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
                // Trả về giá trị từ $data nếu là PUT, bất kể key có tồn tại hay không
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

        // Gán dữ liệu vào superglobal để mô phỏng thực tế hơn
        if ($method === 'PUT') {
            global $_PUT; // Sử dụng biến toàn cục nếu cần
            $_PUT = $data;
        } elseif ($method === 'PATCH') {
            global $_PATCH;
            $_PATCH = $data;
        }
    }

    // === Test Cases for getById() ===

    /** Test 1: Get booking by valid ID */
    public function testGetByIdWithValidId(): void
    {
        $this->mockRoute(1);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Action successfully !", $result->msg);
        $this->assertEquals(1, $result->data->id);
    }

    /** Test 2: Get booking with missing ID */
    public function testGetByIdWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 3: Get booking with invalid ID */
    public function testGetByIdWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Booking is not available", $result->msg);
    }

    /** Test 4: Get booking with unauthorized role */
    public function testGetByIdWithUnauthorizedRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockRoute(1);
        $this->mockInput("GET");

        ob_start();
        $this->controller->process();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("You don't have permission", $result->msg);
    }

    /** Test 5: Get booking with no auth user */
    public function testGetByIdWithNoAuthUser(): void
    {
        $this->controller->setVariable("AuthUser", null);
        $this->mockRoute(1);
        $this->mockInput("GET");

        ob_start();
        $this->controller->process();
        $output = ob_get_clean();

        $this->assertEmpty($output); // Redirects to login
    }

    // === Test Cases for update() ===

    /** Test 6: Update booking with valid data */
    public function testUpdateWithValidData(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'address' => 'Vietnam',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 6 ========\n";
        print_r($result);
        $this->assertEquals(1, $result->result);
        $this->assertStringContainsString("Congratulation", $result->msg);
        $this->assertEquals("Nguyen Van B", $result->data->booking_name);
    }

    /** Test 7: Update booking with missing ID */
    public function testUpdateWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("PUT", []);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 8: Update booking with invalid ID */
    public function testUpdateWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("PUT", []);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("This booking does not exist !", $result->msg);
    }

    /** Test 9: Update booking with missing required field */
    public function testUpdateWithMissingRequiredField(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => 1,
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00' // Missing appointment_date
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);
        echo "\n\n======== DECODED JSON OUTPUT TEST 1 ========\n";
        print_r($output);
        $this->assertEquals(0, $result->result);
        $this->assertEquals("Missing field: appointment_date", $result->msg);
    }

    /** Test 10: Update booking with invalid service_id */
    public function testUpdateWithInvalidServiceId(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '999',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Service is not available", $result->msg);
    }

    /** Test 11: Update booking with invalid booking_name */
    public function testUpdateWithInvalidBookingName(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'John123',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("( Booking name ) Vietnamese name only has letters and space", $result->msg);
    }

    /** Test 12: Update booking with invalid status */
    public function testUpdateWithInvalidStatus(): void
    {
        self::$pdo->exec("UPDATE " . TABLE_PREFIX . TABLE_BOOKINGS . " SET status = 'verified' WHERE id = 1");
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16',
            'address' => 'Vietnam'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Booking's status is verified now", $result->msg);
    }

    /** Test 13: Update booking with invalid phone */
    public function testUpdateWithInvalidPhone(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => 'abc',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Booking phone is not a valid phone number. Please, try again !", $result->msg);
    }

    /** Test 14: Update booking with invalid gender */
    public function testUpdateWithInvalidGender(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16',
            'gender' => '2'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Gender is not valid. There are 2 values: 0 is female & 1 is men", $result->msg);
    }

    /** Test 15: Update booking with invalid address */
    public function testUpdateWithInvalidAddress(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16',
            'address' => '123@#$%'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Address only accepts letters, space & number", $result->msg);
    }

    // === Test Cases for confirm() ===

    /** Test 16: Confirm booking to verified status */
    public function testConfirmToVerified(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'verified']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertStringContainsString("VERIFIED this booking will create a new appointment", $result->msg);
    }

    /** Test 17: Confirm booking to cancelled status */
    public function testConfirmToCancelled(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'cancelled']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Booking has been cancelled successfully !", $result->msg);
    }

    /** Test 18: Confirm booking with missing ID */
    public function testConfirmWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("PATCH", ['newStatus' => 'verified']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 19: Confirm booking with invalid ID */
    public function testConfirmWithInvalidId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("PATCH", ['newStatus' => 'verified']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("This booking does not exist !", $result->msg);
    }

    /** Test 20: Confirm booking with missing newStatus */
    public function testConfirmWithMissingNewStatus(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", []);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("New status is required to continue !", $result->msg);
    }

    /** Test 21: Confirm booking with invalid newStatus */
    public function testConfirmWithInvalidNewStatus(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'invalid']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Booking is only updated when its new status", $result->msg);
    }

    /** Test 22: Confirm booking with status already verified */
    public function testConfirmWithStatusVerified(): void
    {
        self::$pdo->exec("UPDATE " . TABLE_PREFIX . TABLE_BOOKINGS . " SET status = 'verified' WHERE id = 1");
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'verified']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("You don't have permission to do this action. Only booking's status is processing can do this action !", $result->msg);
    }

    /** Test 23: Confirm booking with status already cancelled */
    public function testConfirmWithStatusCancelled(): void
    {
        self::$pdo->exec("UPDATE " . TABLE_PREFIX . TABLE_BOOKINGS . " SET status = 'cancelled' WHERE id = 1");
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'verified']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("You don't have permission to do this action. Only booking's status is processing can do this action !", $result->msg);
    }

    /** Test 24: Confirm verified booking to cancelled */
    public function testConfirmVerifiedToCancelled(): void
    {
        self::$pdo->exec("UPDATE " . TABLE_PREFIX . TABLE_BOOKINGS . " SET status = 'verified' WHERE id = 1");
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'cancelled']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Only booking's status is processing can do this action", $result->msg);
    }

    /** Test 25: Confirm with supporter role */
    public function testConfirmWithSupporterRole(): void
    {
        $this->mockAuthUser("supporter");
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => 'verified']);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertStringContainsString("VERIFIED this booking will create a new appointment", $result->msg);
    }

    // === Edge Cases ===

    /** Test 26: Update with past appointment_date */
    public function testUpdateWithPastAppointmentDate(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '15:00',
            'appointment_date' => '2020-01-01'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertNotEmpty($result->msg); // Assumes isAppointmentTimeValid() rejects past dates
    }

    /** Test 27: Update with invalid appointment_time */
    public function testUpdateWithInvalidAppointmentTime(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => '1',
            'booking_name' => 'Nguyen Van B',
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'appointment_time' => '25:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertNotEmpty($result->msg); // Assumes isAppointmentTimeValid() rejects invalid time
    }

    /** Test 28: Update with null values in required fields */
    public function testUpdateWithNullRequiredFields(): void
    {
        $this->mockRoute(1);
        $data = [
            'service_id' => null,
            'booking_name' => null,
            'booking_phone' => null,
            'name' => null,
            'appointment_time' => null,
            'appointment_date' => null
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Missing field", $result->msg);
    }

    /** Test 29: Confirm with SQL injection attempt in newStatus */
    public function testConfirmWithSqlInjectionInNewStatus(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PATCH", ['newStatus' => "verified'; DROP TABLE " . TABLE_PREFIX . TABLE_BOOKINGS . "; --"]);

        ob_start();
        $this->controller->confirm();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertStringContainsString("Booking is only updated when its new status", $result->msg);
    }

    /** Test 30: Update with long booking_name */
    public function testUpdateWithLongBookingName(): void
    {
        $this->mockRoute(1);
        $longName = str_repeat("Nguyen ", 50);
        $data = [
            'service_id' => '1',
            'booking_name' => $longName,
            'booking_phone' => '0987654321',
            'name' => 'Nguyen Van B',
            'address' => 'Vietnam',
            'appointment_time' => '15:00',
            'appointment_date' => '2025-04-16'
        ];
        $this->mockInput("PUT", $data);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        echo "\n\n======== DECODED JSON OUTPUT TEST 1 ========\n";
        print_r($result);

        $this->assertEquals(1, $result->result); // Assumes DB handles truncation
    }
}
