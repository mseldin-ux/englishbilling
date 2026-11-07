<?php

namespace Tests;

use App\Models\Student;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function obtainEditingLock(Student $student): string
    {
        $response = $this->get('/detail/'.$student->id)->assertOk();
        $token = $response->viewData('editLock')['token'];
        $this->assertNotSame('', $token);
        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->withCredentials();
        $this->withHeader('X-Student-Lock', $token);

        return $token;
    }
}
