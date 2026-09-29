<?php
declare(strict_types=1);

function test_auth(): void
{
    T::section('Auth — password hashing, login, inactive-user rejection');

    $pdo = Database::pdo();
    $pdo->exec("DELETE FROM users WHERE username = 'test_auth_user'");

    $hash = password_hash('correct-horse-battery', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role, status) VALUES ('test_auth_user', ?, 'Test Auth User', 'VIEWER', 'ACTIVE')")
        ->execute([$hash]);

    $ok = Auth::attemptLogin('test_auth_user', 'correct-horse-battery');
    T::assertTrue($ok !== null, 'Correct password logs in successfully');
    T::assertEquals('VIEWER', $_SESSION['role'] ?? null, 'Session role set correctly after login');
    T::assertTrue(Auth::check(), 'Auth::check() true after login');
    Auth::logout();
    T::assertFalse(Auth::check(), 'Auth::check() false after logout');

    $bad = Auth::attemptLogin('test_auth_user', 'wrong-password');
    T::assertTrue($bad === null, 'Wrong password is rejected');

    $pdo->prepare("UPDATE users SET status = 'INACTIVE' WHERE username = 'test_auth_user'")->execute();
    $inactive = Auth::attemptLogin('test_auth_user', 'correct-horse-battery');
    T::assertTrue($inactive === null, 'INACTIVE user cannot log in even with correct password');

    $pdo->exec("DELETE FROM users WHERE username = 'test_auth_user'");
}
