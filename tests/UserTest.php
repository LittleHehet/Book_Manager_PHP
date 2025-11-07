<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/User.php';

class UserTest extends TestCase
{
    private $user;
    private $pdo;

    protected function setUp(): void
    {
        global $pdo;
        $this->pdo = $pdo;

        $this->pdo->exec("DELETE FROM users");

        $this->user = new User($this->pdo);
    }

    public function testCreateUser()
    {
        $result = $this->user->create('testuser', 'password123', 'test@example.com');
        $this->assertTrue($result);
    }

    public function testFindByUsername()
    {
        $this->user->create('juan', 'pass123', 'juan@example.com');

        $user = $this->user->findByUsername('juan');
        $this->assertNotFalse($user);
        $this->assertEquals('juan', $user['username']);
        $this->assertEquals('juan@example.com', $user['email']);
    }

    public function testFindByUsernameNotFound()
    {
        $user = $this->user->findByUsername('noexiste');
        $this->assertFalse($user);
    }

    public function testPasswordIsHashed()
    {
        $this->user->create('testuser', 'mypassword', 'test@test.com');
        $user = $this->user->findByUsername('testuser');

        $this->assertNotEquals('mypassword', $user['password']);
        $this->assertStringStartsWith('$2y$', $user['password']);
    }

    public function testVerifyCorrectPassword()
    {
        $this->user->create('maria', 'secreto123', 'maria@test.com');

        $user = $this->user->verify('maria', 'secreto123');
        $this->assertNotFalse($user);
        $this->assertEquals('maria', $user['username']);
    }

    public function testVerifyIncorrectPassword()
    {
        $this->user->create('pedro', 'correcta', 'pedro@test.com');

        $user = $this->user->verify('pedro', 'incorrecta');
        $this->assertFalse($user);
    }

    public function testVerifyNonExistentUser()
    {
        $user = $this->user->verify('noexiste', 'password');
        $this->assertFalse($user);
    }

    public function testGetAllUsers()
    {
        $this->user->create('user1', 'pass1', 'user1@test.com');
        $this->user->create('user2', 'pass2', 'user2@test.com');

        $users = $this->user->getAll();
        $this->assertCount(2, $users);

        foreach ($users as $user) {
            $this->assertArrayHasKey('username', $user);
            $this->assertArrayHasKey('email', $user);
            $this->assertArrayNotHasKey('password', $user);
        }
    }

    public function testCannotCreateDuplicateUsername()
    {
        $this->user->create('duplicate', 'pass1', 'email1@test.com');

        $this->expectException(PDOException::class);
        $this->user->create('duplicate', 'pass2', 'email2@test.com');
    }
}
