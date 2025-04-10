<?php
define("APPPATH", __DIR__ . '/..');
define('PHPUNIT_TEST', true);
require_once APPPATH . '/autoload.php';
require_once APPPATH . '/helpers/common.helper.php';
require_once APPPATH . '/config/db.config.php';

use PHPUnit\Framework\TestCase;

class DoctorControllerTest extends TestCase
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
        if (!defined('UPLOAD_PATH')) {
            define('UPLOAD_PATH', __DIR__ . '/uploads');
        }
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
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "specialities");
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . "rooms");

        // Insert sample data
        // Insert specialities
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "specialities (id, name, description) VALUES (1, 'Cardiology', 'Heart-related issues')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "specialities (id, name, description) VALUES (2, 'Neurology', 'Brain-related issues')");

        // Insert rooms
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "rooms (id, name, location) VALUES (1, 'Room 101', 'Building A')");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "rooms (id, name, location) VALUES (2, 'Room 102', 'Building B')");

        // Insert patients
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "patients (id, name, phone) VALUES (1, 'Patient 1', '0123456789')");

        // Insert doctors
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, phone, name, description, price, role, avatar, active, create_at, update_at, speciality_id, room_id) VALUES (1, 'doctor1@example.com', '0123456789', 'Doctor 1', 'Bác sĩ Doctor 1', 150000, 'admin', 'avatar1.jpg', 1, '2025-04-10 10:00:00', '2025-04-10 10:00:00', 1, 1)");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, phone, name, description, price, role, avatar, active, create_at, update_at, speciality_id, room_id) VALUES (2, 'doctor2@example.com', '0987654321', 'Doctor 2', 'Bác sĩ Doctor 2', 200000, 'member', 'avatar2.jpg', 1, '2025-04-10 10:00:00', '2025-04-10 10:00:00', 2, 2)");
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "doctors (id, email, phone, name, description, price, role, avatar, active, create_at, update_at, speciality_id, room_id) VALUES (3, 'doctor3@example.com', '0123456780', 'Doctor 3', 'Bác sĩ Doctor 3', 120000, 'member', 'avatar3.jpg', 0, '2025-04-10 10:00:00', '2025-04-10 10:00:00', 1, 1)");

        // Insert appointments
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . "appointments (id, doctor_id, patient_id, patient_name, patient_phone, patient_reason, date, appointment_time, status, position, create_at, update_at) VALUES (1, 2, 1, 'Patient 1', '0123456789', 'Reason 1', '10-04-2025', '', 'processing', 1, '2025-04-10 10:00:00', '2025-04-10 10:00:00')");

        // Commit the transaction to make the data visible
        self::$pdo->commit();

        // Start a new transaction for the test case
        self::$pdo->beginTransaction();

        $this->controller = new DoctorController();
        $this->mockAuthUser();
        $this->mockRoute();
        self::$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // Create upload directory
        if (!file_exists(UPLOAD_PATH)) {
            mkdir(UPLOAD_PATH, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $this->controller = null;

        // Clean up upload directory
        $files = glob(UPLOAD_PATH . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
        self::$connection = null;
        if (file_exists(UPLOAD_PATH)) {
            rmdir(UPLOAD_PATH);
        }
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
        $Route->params = new stdClass();
        if ($id !== null) {
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

            public function post($key)
            {
                return $this->method === 'POST' ? (array_key_exists($key, $this->data) ? $this->data[$key] : null) : null;
            }

            public function put($key)
            {
                return $this->method === 'PUT' ? (array_key_exists($key, $this->data) ? $this->data[$key] : null) : null;
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
        } elseif ($method === 'PUT') {
            global $_PUT;
            $_PUT = $data;
        }
    }

    private function mockFileUpload($filename = 'avatar.jpg', $ext = 'jpg', $size = 1024): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, str_repeat('a', $size));

        $_FILES['file'] = [
            'name' => $filename,
            'type' => 'image/jpeg',
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => $size
        ];
    }

    // === Test Cases for getById() ===

    /** Test 6: Get by ID with valid ID */
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
        $this->assertEquals("Doctor 1", $result->data->name);
    }

    /** Test 7: Get by ID with missing ID */
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

    /** Test 8: Get by ID with non-existent ID */
    public function testGetByIdWithNonExistentId(): void
    {
        $this->mockRoute(999);
        $this->mockInput("GET");

        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available", $result->msg);
    }

    // === Test Cases for update() ===

    /** Test 9: Update with valid data */
    public function testUpdateWithValidData(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member",
            "description" => "Updated description",
            "price" => 150000,
            "speciality_id" => 1,
            "room_id" => 1,
            "active" => 1
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Doctor account is updated successfully !", $result->msg);
        $this->assertEquals("Doctor Updated", $result->data->name);
    }

    /** Test 10: Update with non-admin role */
    public function testUpdateWithNonAdminRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("You are not admin & you can't do this action !", $result->msg);
    }

    /** Test 11: Update with missing ID */
    public function testUpdateWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 12: Update with non-existent doctor */
    public function testUpdateWithNonExistentDoctor(): void
    {
        $this->mockRoute(999);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available. Try again !", $result->msg);
    }

    /** Test 13: Update with missing required field (phone) */
    public function testUpdateWithMissingPhone(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "name" => "Doctor Updated",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Missing field: phone", $result->msg);
    }

    /** Test 14: Update with invalid phone format */
    public function testUpdateWithInvalidPhoneFormat(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "invalid_phone",
            "name" => "Doctor Updated",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("This is not a valid phone number. Please, try again !", $result->msg);
    }

    /** Test 15: Update with phone too short */
    public function testUpdateWithPhoneTooShort(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "12345",
            "name" => "Doctor Updated",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Phone number has at least 10 number !", $result->msg);
    }

    /** Test 16: Update with invalid name format */
    public function testUpdateWithInvalidNameFormat(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor@Updated", // Invalid character @
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Vietnamese name only has letters and space", $result->msg);
    }

    /** Test 17: Update with invalid price format */
    public function testUpdateWithInvalidPriceFormat(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member",
            "price" => "invalid_price"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("This is not a valid price. Please, try again !", $result->msg);
    }

    /** Test 18: Update with price too low */
    public function testUpdateWithPriceTooLow(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member",
            "price" => 50000
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Price must greater than 100.000 !", $result->msg);
    }

    /** Test 19: Update with invalid role */
    public function testUpdateWithInvalidRole(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "invalid_role"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Role is not valid. There are 2 valid values: admin, member, supporter !", $result->msg);
    }

    /** Test 20: Update with non-existent speciality */
    public function testUpdateWithNonExistentSpeciality(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member",
            "speciality_id" => 999
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Speciality is not available.", $result->msg);
    }

    /** Test 21: Update with non-existent room */
    public function testUpdateWithNonExistentRoom(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated",
            "role" => "member",
            "room_id" => 999
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Room is not available.", $result->msg);
    }

    // === Test Cases for updateAvatar() ===

    /** Test 22: Update avatar with valid file */
    // public function testUpdateAvatarWithValidFile(): void
    // {
    //     $this->mockRoute(1);
    //     $this->mockInput("POST", ["action" => "avatar"]);
    //     $this->mockFileUpload();

    //     ob_start();
    //     $this->controller->updateAvatar();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(1, $result->result);
    //     $this->assertEquals("Avatar has been updated successfully !", $result->msg);
    //     $this->assertStringContainsString("avatar_doctor_1_", $result->url);
    // }

    /** Test 23: Update avatar with non-admin role */
    public function testUpdateAvatarWithNonAdminRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockRoute(1);
        $this->mockInput("POST", ["action" => "avatar"]);
        $this->mockFileUpload();

        ob_start();
        $this->controller->updateAvatar();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("You are not admin & you can't do this action !", $result->msg);
    }

    /** Test 24: Update avatar with missing ID */
    public function testUpdateAvatarWithMissingId(): void
    {
        $this->mockRoute(null);
        $this->mockInput("POST", ["action" => "avatar"]);
        $this->mockFileUpload();

        ob_start();
        $this->controller->updateAvatar();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("ID is required !", $result->msg);
    }

    /** Test 25: Update avatar with non-existent doctor */
    public function testUpdateAvatarWithNonExistentDoctor(): void
    {
        $this->mockRoute(999);
        $this->mockInput("POST", ["action" => "avatar"]);
        $this->mockFileUpload();

        ob_start();
        $this->controller->updateAvatar();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available. Try again !", $result->msg);
    }

    /** Test 26: Update avatar with inactive doctor */
    public function testUpdateAvatarWithInactiveDoctor(): void
    {
        $this->mockRoute(3); // Doctor 3 is inactive
        $this->mockInput("POST", ["action" => "avatar"]);
        $this->mockFileUpload();

        ob_start();
        $this->controller->updateAvatar();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor have been deactivated so that you can not do this action", $result->msg);
    }

    /** Test 27: Update avatar with missing file */
    public function testUpdateAvatarWithMissingFile(): void
    {
        $this->mockRoute(1);
        $this->mockInput("POST", ["action" => "avatar"]);
        $_FILES = [];

        ob_start();
        $this->controller->updateAvatar();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Photo is not received !", $result->msg);
    }

    /** Test 28: Update avatar with invalid file extension */
    // public function testUpdateAvatarWithInvalidFileExtension(): void
    // {
    //     $this->mockRoute(1);
    //     $this->mockInput("POST", ["action" => "avatar"]);
    //     $this->mockFileUpload('avatar.pdf', 'pdf');

    //     ob_start();
    //     $this->controller->updateAvatar();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(0, $result->result);
    //     $this->assertEquals("Only jpeg,jpg,png files are allowed", $result->msg);
    // }

    // === Test Cases for delete() ===

    /** Test 29: Delete with valid ID (doctor with appointments) */
    // public function testDeleteWithValidIdWithAppointments(): void
    // {
    //     $this->mockRoute(2); // Doctor 2 has an appointment
    //     $this->mockInput("DELETE");

    //     ob_start();
    //     $this->controller->delete();
    //     $output = ob_get_clean();
    //     $result = json_decode($output);

    //     $this->assertEquals(1, $result->result);
    //     $this->assertEquals("Doctor is deactivated successfully", $result->msg);
    //     $this->assertEquals("deactivated", $result->type);

    //     // Verify appointment status
    //     $appointment = DB::table(TABLE_PREFIX . TABLE_APPOINTMENTS)
    //         ->where("id", "=", 1)
    //         ->get();
    //     $this->assertEquals("cancelled", $appointment[0]->status);
    // }

    /** Test 30: Delete with valid ID (doctor without appointments) */
    public function testDeleteWithValidIdWithoutAppointments(): void
    {
        $this->mockRoute(1); // Doctor 1 has no appointments
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(1, $result->result);
        $this->assertEquals("Doctor is deleted successfully !", $result->msg);
        $this->assertEquals("delete", $result->type);
    }

    /** Test 31: Delete with non-admin role */
    public function testDeleteWithNonAdminRole(): void
    {
        $this->mockAuthUser("member");
        $this->mockRoute(1);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("You are not admin & you can't do this action !", $result->msg);
    }

    /** Test 32: Delete with missing ID */
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

    /** Test 33: Delete self */
    public function testDeleteSelf(): void
    {
        $this->mockAuthUser("admin", 1);
        $this->mockRoute(1);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("You can not deactivate yourself !", $result->msg);
    }

    /** Test 34: Delete with non-existent doctor */
    public function testDeleteWithNonExistentDoctor(): void
    {
        $this->mockRoute(999);
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Doctor is not available !", $result->msg);
    }

    /** Test 35: Delete with already deactivated doctor */
    public function testDeleteWithDeactivatedDoctor(): void
    {
        $this->mockRoute(3); // Doctor 3 is already deactivated
        $this->mockInput("DELETE");

        ob_start();
        $this->controller->delete();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("This doctor account was deactivated. No need this action !", $result->msg);
    }

    // === Edge Cases ===

    /** Test 36: Update with SQL injection attempt in name */
    public function testUpdateWithSqlInjectionInName(): void
    {
        $this->mockRoute(1);
        $this->mockInput("PUT", [
            "phone" => "0123456789",
            "name" => "Doctor Updated; DROP TABLE " . TABLE_PREFIX . "doctors; --",
            "role" => "member"
        ]);

        ob_start();
        $this->controller->update();
        $output = ob_get_clean();
        $result = json_decode($output);

        $this->assertEquals(0, $result->result);
        $this->assertEquals("Vietnamese name only has letters and space", $result->msg);
    }
}