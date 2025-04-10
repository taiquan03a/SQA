<?php
define("APPPATH", __DIR__ . '/..');
define('PHPUNIT_TEST', true);
require_once APPPATH . '/autoload.php';
require_once APPPATH . '/config/db.config.php';

use PHPUnit\Framework\TestCase;

class DrugControllerTest extends TestCase
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

        // Thay TRUNCATE bằng DELETE để hỗ trợ transaction
        self::$pdo->exec("DELETE FROM " . TABLE_PREFIX . TABLE_DRUGS);
        self::$pdo->exec("INSERT INTO " . TABLE_PREFIX . TABLE_DRUGS . " (id, name) VALUES (1, 'Test Drug')");
        
        $this->controller = new DrugController();
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

    private function mockAuthUser(): void
    {
        $AuthUser = (object) ["role" => "user"];
        $this->controller->setVariable("AuthUser", $AuthUser);
    }

    // Bỏ type hint ?int để chấp nhận mọi kiểu dữ liệu
    private function mockRoute($id = null): void
    {
        $Route = (object) ["params" => (object) []];
        if ($id !== null) {
            $Route->params->id = $id;
        }
        $this->controller->setVariable("Route", $Route);
    }

    public function testGetByIdMissingId(): void
    {
        $this->mockRoute();
        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();

        // Debug output
        echo "Debug output (testGetByIdMissingId): ";
        var_dump($output);

        $result = json_decode($output);

        $expected = (object) [
            "result" => 0,
            "msg" => "ID is required !"
        ];
        
        $this->assertEquals($expected, $result, "Expected missing ID response");
        $this->assertEquals(1, self::$pdo->query("SELECT COUNT(*) FROM " . TABLE_PREFIX . TABLE_DRUGS)->fetchColumn(), "Database should have test data");
    }

    public function testGetByIdValidId(): void
    {
        $this->mockRoute(1);
        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();

        // Debug output
        echo "Debug output (testGetByIdValidId): ";
        var_dump($output);

        $result = json_decode($output);

        $this->assertEquals(1, $result->result, "Expected result to be 1");
        $this->assertEquals("Test Drug", $result->data->name, "Expected drug name to be 'Test Drug'");
    }

    public function testGetByIdNonExistentId(): void
    {
        $this->mockRoute(999);
        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();

        // Debug output
        echo "Debug output (testGetByIdNonExistentId): ";
        var_dump($output);

        $result = json_decode($output);

        $expected = (object) [
            "result" => 0,
            "msg" => "Drug not found"
        ];
        
        $this->assertEquals($expected, $result, "Expected drug not found response");
    }

    public function testGetByIdInvalidId(): void
    {
        $this->mockRoute("invalid");
        ob_start();
        $this->controller->getById();
        $output = ob_get_clean();

        // Debug output
        echo "Debug output (testGetByIdInvalidId): ";
        var_dump($output);

        $result = json_decode($output);

        $expected = (object) [
            "result" => 0,
            "msg" => "Invalid ID format"
        ];
        
        $this->assertEquals($expected, $result, "Expected invalid ID format response");
    }
}